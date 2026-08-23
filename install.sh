#!/usr/bin/env bash
#
# brew-dev-stack — a self-owned local PHP dev environment on Homebrew.
# nginx + dnsmasq + php-fpm + mkcert (+ optional MySQL / PostgreSQL / phpMyAdmin)
#
# Usage:
#   ./install.sh                        # defaults: ~/sites, .test, php@8.3
#   SITES_DIR=~/code TLD=localdev PHP_VERSION=8.4 ./install.sh
#
set -euo pipefail

SITES_DIR="${SITES_DIR:-$HOME/sites}"
TLD="${TLD:-test}"
PHP_VERSION="${PHP_VERSION:-8.3}"
WITH_MYSQL="${WITH_MYSQL:-1}"
WITH_PHPMYADMIN="${WITH_PHPMYADMIN:-1}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHPSHORT="${PHP_VERSION//./}"
GROUP="$(id -gn)"

bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
info()  { printf '  %s\n' "$*"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn()  { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()   { printf '\033[31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# --- preflight -------------------------------------------------------------
bold "Preflight"
[[ "$(uname -s)" == "Darwin" ]] || die "macOS only."
command -v brew >/dev/null || die "Homebrew not found. See https://brew.sh"
BREW="$(brew --prefix)"
ok "Homebrew at $BREW"

[[ "$TLD" =~ ^[a-z][a-z0-9-]*$ ]] || die "TLD must be a single lowercase label (got '$TLD')."
# Reserved-for-testing TLDs never resolve publicly, so they are safe to hijack.
case "$TLD" in
  test|localhost|example|invalid|localdev) ;;
  *) warn "'$TLD' is not an IANA reserved TLD. If it becomes a real domain, you will shadow it." ;;
esac

mkdir -p "$SITES_DIR"
ok "Sites directory: $SITES_DIR"
info "PHP: $PHP_VERSION   TLD: .$TLD"

# --- packages --------------------------------------------------------------
bold "Installing packages"
FORMULAE=(nginx dnsmasq mkcert nss "php@${PHP_VERSION}")
[[ "$WITH_MYSQL" == "1" ]] && FORMULAE+=(mysql)
[[ "$WITH_PHPMYADMIN" == "1" ]] && FORMULAE+=(phpmyadmin)
for f in "${FORMULAE[@]}"; do
  if brew list --versions "$f" >/dev/null 2>&1; then
    ok "$f (already installed)"
  else
    info "installing $f…"
    brew install "$f" >/dev/null && ok "$f"
  fi
done

# --- render templates ------------------------------------------------------
render() {  # render <template> <destination>
  sed -e "s|{{BREW}}|$BREW|g" \
      -e "s|{{SITES}}|$SITES_DIR|g" \
      -e "s|{{TLD}}|$TLD|g" \
      -e "s|{{USER}}|$USER|g" \
      -e "s|{{GROUP}}|$GROUP|g" \
      -e "s|{{PHPSHORT}}|$PHPSHORT|g" \
      "$1" > "$2"
}
backup() { [[ -f "$1" ]] && cp "$1" "$1.bak-$(date +%Y%m%d%H%M%S)" && info "backed up $(basename "$1")"; return 0; }

bold "Writing configuration"
mkdir -p "$BREW"/etc/nginx/{servers,certs} "$BREW"/var/log/nginx "$BREW"/var/run "$BREW"/etc/dnsmasq.d

backup "$BREW/etc/nginx/nginx.conf"
render "$SCRIPT_DIR/config/nginx.conf.template"    "$BREW/etc/nginx/nginx.conf"
render "$SCRIPT_DIR/config/local-dev.conf.template" "$BREW/etc/nginx/servers/local-dev.conf"
ok "nginx"

render "$SCRIPT_DIR/config/dnsmasq-tld.conf.template" "$BREW/etc/dnsmasq.d/${TLD}-tld.conf"
grep -q "^conf-dir=$BREW/etc/dnsmasq.d" "$BREW/etc/dnsmasq.conf" 2>/dev/null \
  || echo "conf-dir=$BREW/etc/dnsmasq.d/,*.conf" >> "$BREW/etc/dnsmasq.conf"
ok "dnsmasq"

# php-fpm: unix socket avoids port collisions with other PHP versions.
FPM_POOL="$BREW/etc/php/$PHP_VERSION/php-fpm.d/www.conf"
FPM_CONF="$BREW/etc/php/$PHP_VERSION/php-fpm.conf"
if [[ -f "$FPM_POOL" ]]; then
  backup "$FPM_POOL"
  /usr/bin/sed -i '' \
    -e "s|^listen = .*|listen = $BREW/var/run/php${PHPSHORT}-fpm.sock|" \
    -e "s|^;\{0,1\}listen.owner = .*|listen.owner = $USER|" \
    -e "s|^;\{0,1\}listen.group = .*|listen.group = $GROUP|" \
    -e "s|^;\{0,1\}listen.mode = .*|listen.mode = 0660|" "$FPM_POOL"
  # Per-version error log: a root-owned shared php-fpm.log from another version
  # makes launchd fail this service with status 78 before php-fpm even starts.
  backup "$FPM_CONF"
  /usr/bin/sed -i '' "s|^;\{0,1\}error_log = .*|error_log = $BREW/var/log/php-fpm-${PHP_VERSION}.log|" "$FPM_CONF"
  # Valet/Herd leftovers add a second pool that fights this one.
  for stale in "$BREW/etc/php/$PHP_VERSION/php-fpm.d/"*valet*.conf; do
    [[ -e "$stale" ]] && mv "$stale" "$stale.disabled" && warn "disabled stale pool $(basename "$stale")"
  done
  ok "php-fpm ($PHP_VERSION) on unix socket"
else
  die "php-fpm pool config not found at $FPM_POOL"
fi

if [[ "$WITH_MYSQL" == "1" ]]; then
  backup "$BREW/etc/my.cnf"
  cp "$SCRIPT_DIR/config/my.cnf.template" "$BREW/etc/my.cnf"
  ok "mysql (tuned for development)"
fi

if [[ "$WITH_PHPMYADMIN" == "1" ]]; then
  SECRET="$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
  PMA_CFG="$BREW/etc/phpmyadmin.config.inc.php"
  backup "$PMA_CFG"
  sed -e "s|{{SECRET}}|$SECRET|" -e "s|{{BREW}}|$BREW|g" \
      "$SCRIPT_DIR/config/phpmyadmin.config.inc.php.template" > "$PMA_CFG"
  chmod 640 "$PMA_CFG"
  mkdir -p "$BREW/var/tmp/phpmyadmin" && chmod 700 "$BREW/var/tmp/phpmyadmin"
  ln -sfn "$BREW/share/phpmyadmin" "$SITES_DIR/phpmyadmin"
  ok "phpmyadmin → https://phpmyadmin.$TLD (symlinked into $SITES_DIR)"
fi

# --- certificates ----------------------------------------------------------
bold "Certificates"
if [[ ! -d "$(mkcert -CAROOT 2>/dev/null)" ]] || ! security find-certificate -c mkcert /Library/Keychains/System.keychain >/dev/null 2>&1; then
  warn "mkcert CA is not installed in your trust store yet."
  info "Run:  mkcert -install     (asks for your password)"
fi
"$SCRIPT_DIR/bin/site-cert-regen" || warn "cert generation reported a problem"

# --- done ------------------------------------------------------------------
bold "Remaining steps (these need sudo — run them yourself)"
cat <<EOF

  1. Trust the local CA (once):
       mkcert -install

  2. Tell macOS to resolve .$TLD locally (once):
       sudo mkdir -p /etc/resolver
       echo "nameserver 127.0.0.1" | sudo tee /etc/resolver/$TLD >/dev/null

  3. Start the services (nginx and dnsmasq bind privileged ports, so root):
       brew services start php@$PHP_VERSION
       sudo brew services start dnsmasq
       sudo brew services start nginx
$([[ "$WITH_MYSQL" == "1" ]] && echo "       brew services start mysql")

  Then create a project and visit it:
       mkdir -p $SITES_DIR/hello && echo '<?php phpinfo();' > $SITES_DIR/hello/index.php
       $SCRIPT_DIR/bin/site-cert-regen && sudo nginx -s reload
       open https://hello.$TLD

EOF
