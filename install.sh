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
WITH_POSTGRES="${WITH_POSTGRES:-0}"
PG_VERSION="${PG_VERSION:-18}"
WITH_PGVECTOR="${WITH_PGVECTOR:-0}"
WITH_ADMINER="${WITH_ADMINER:-1}"
DRY_RUN="${DRY_RUN:-0}"

usage() {
  cat <<'EOF'
brew-dev-stack — a self-owned local PHP dev environment on Homebrew.
nginx + dnsmasq + php-fpm + mkcert, optionally MySQL and phpMyAdmin.

Serves every directory under your sites folder at https://<name>.test

Usage:
  ./install.sh [options]

Options:
  -n, --dry-run     Show what would change without modifying anything
  -h, --help        This message

Environment:
  SITES_DIR         Project directory        (default: ~/sites)
  TLD               Local TLD, no dot        (default: test)
  PHP_VERSION       Homebrew PHP version     (default: 8.3)
  WITH_MYSQL        Install and tune MySQL   (default: 1)
  WITH_PHPMYADMIN   Install phpMyAdmin       (default: 1)
  WITH_POSTGRES     Install PostgreSQL       (default: 0)
  PG_VERSION        PostgreSQL major         (default: 18)
  WITH_PGVECTOR     Install pgvector         (default: 0; needs WITH_POSTGRES=1)
  WITH_ADMINER      Install Adminer          (default: 1)

Examples:
  ./install.sh --dry-run
  SITES_DIR=~/code TLD=localdev PHP_VERSION=8.4 ./install.sh
  WITH_MYSQL=0 WITH_PHPMYADMIN=0 ./install.sh

The three steps that need sudo (trusting the CA, the /etc/resolver entry, and
starting nginx/dnsmasq) are printed at the end for you to run yourself.
EOF
}

for arg in "$@"; do
  case "$arg" in
    -n|--dry-run) DRY_RUN=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 1 ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHPSHORT="${PHP_VERSION//./}"
GROUP="$(id -gn)"

bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
dry()   { printf '  \033[36m→\033[0m would %s\n' "$*"; }
is_dry() { [[ "$DRY_RUN" == "1" ]]; }
info()  { printf '  %s\n' "$*"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn()  { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()   { printf '\033[31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# --- preflight -------------------------------------------------------------
is_dry && bold "DRY RUN — nothing will be modified"
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
# composer: the vhost supports Laravel/Symfony docroots, so PHP dependency
# management belongs in the base install, not as an afterthought.
FORMULAE=(nginx dnsmasq mkcert nss wp-cli composer "php@${PHP_VERSION}")
[[ "$WITH_MYSQL" == "1" ]] && FORMULAE+=(mysql)
[[ "$WITH_PHPMYADMIN" == "1" ]] && FORMULAE+=(phpmyadmin)
# pgvector's Homebrew bottle is built only against recent PostgreSQL majors.
# Installing it alongside an older postgres appears to succeed, then
# CREATE EXTENSION vector fails — so default to a version it supports.
[[ "$WITH_POSTGRES" == "1" ]] && FORMULAE+=("postgresql@${PG_VERSION}")
[[ "$WITH_PGVECTOR" == "1" ]] && FORMULAE+=(pgvector)
for f in "${FORMULAE[@]}"; do
  if brew list --versions "$f" >/dev/null 2>&1; then
    ok "$f (already installed)"
  else
    if is_dry; then dry "brew install $f"; else
      info "installing $f…"
      brew install "$f" >/dev/null && ok "$f"
    fi
  fi
done

# --- render templates ------------------------------------------------------
render() {  # render <template> <destination>
  if is_dry; then dry "write $2"; return 0; fi
  sed -e "s|{{BREW}}|$BREW|g" \
      -e "s|{{SITES}}|$SITES_DIR|g" \
      -e "s|{{TLD}}|$TLD|g" \
      -e "s|{{USER}}|$USER|g" \
      -e "s|{{GROUP}}|$GROUP|g" \
      -e "s|{{PHPSHORT}}|$PHPSHORT|g" \
      "$1" > "$2"
}
backup() { is_dry && { [[ -f "$1" ]] && dry "back up $1"; return 0; }; [[ -f "$1" ]] && cp "$1" "$1.bak-$(date +%Y%m%d%H%M%S)" && info "backed up $(basename "$1")"; return 0; }

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
if is_dry; then
  dry "point $FPM_POOL at $BREW/var/run/php${PHPSHORT}-fpm.sock"
  dry "set per-version error log in $FPM_CONF"
elif [[ -f "$FPM_POOL" ]]; then
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
  if is_dry; then dry "write $BREW/etc/my.cnf (development tuning)"; else
    backup "$BREW/etc/my.cnf"
    cp "$SCRIPT_DIR/config/my.cnf.template" "$BREW/etc/my.cnf"
    ok "mysql (tuned for development)"
  fi
fi

if [[ "$WITH_PHPMYADMIN" == "1" ]]; then
  if is_dry; then
    dry "write $BREW/etc/phpmyadmin.config.inc.php with a fresh 32-char blowfish_secret"
    dry "symlink $SITES_DIR/phpmyadmin -> $BREW/share/phpmyadmin"
  else
  # Pipe-free: `tr | head -c` SIGPIPEs tr, which `set -o pipefail` turns into
  # an abort. Intermittent, so it would have looked like a flaky installer.
  SECRET="$(openssl rand -base64 48)"; SECRET="${SECRET//[^A-Za-z0-9]/}"; SECRET="${SECRET:0:32}"
  PMA_CFG="$BREW/etc/phpmyadmin.config.inc.php"
  backup "$PMA_CFG"
  sed -e "s|{{SECRET}}|$SECRET|" -e "s|{{BREW}}|$BREW|g" \
      "$SCRIPT_DIR/config/phpmyadmin.config.inc.php.template" > "$PMA_CFG"
  chmod 640 "$PMA_CFG"
  mkdir -p "$BREW/var/tmp/phpmyadmin" && chmod 700 "$BREW/var/tmp/phpmyadmin"
  ln -sfn "$BREW/share/phpmyadmin" "$SITES_DIR/phpmyadmin"
  ok "phpmyadmin → https://phpmyadmin.$TLD (symlinked into $SITES_DIR)"
  fi
fi

if [[ "$WITH_ADMINER" == "1" ]]; then
  if is_dry; then
    dry "download Adminer to $SITES_DIR/adminer/index.php"
  else
    "$SCRIPT_DIR/bin/devstack" install adminer >/dev/null 2>&1 \
      && ok "adminer → https://adminer.$TLD" \
      || warn "adminer install failed — run: bin/devstack install adminer"
  fi
fi

# Persist the layout so the CLI uses the same values. Without this, installing
# with a non-default SITES_DIR or TLD leaves every devstack command falling back
# to ~/sites and .test, silently operating on the wrong paths.
CFG_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/brew-dev-stack"
CFG="$CFG_DIR/config"
if is_dry; then
  dry "record SITES_DIR / TLD / PHP_VERSION in $CFG"
else
  mkdir -p "$CFG_DIR"
  ADMIN_NAME=""; ADMIN_EMAIL=""; ADMIN_USER=""
  # shellcheck source=/dev/null
  [[ -f "$CFG" ]] && . "$CFG"
  cat > "$CFG" <<EOF
# brew-dev-stack configuration.

# Stack layout (written by install.sh; environment variables still override).
SITES_DIR="$SITES_DIR"
TLD="$TLD"
PHP_VERSION="$PHP_VERSION"
EOF
  if [[ -n "$ADMIN_EMAIL" ]]; then
    cat >> "$CFG" <<EOF

# Used when creating new sites.
ADMIN_NAME="$ADMIN_NAME"
ADMIN_EMAIL="$ADMIN_EMAIL"
ADMIN_USER="$ADMIN_USER"
EOF
  fi
  chmod 600 "$CFG"
  ok "settings recorded in $CFG"
fi

# --- certificates ----------------------------------------------------------
bold "Certificates"
if [[ ! -d "$(mkcert -CAROOT 2>/dev/null)" ]] || ! security find-certificate -c mkcert /Library/Keychains/System.keychain >/dev/null 2>&1; then
  warn "mkcert CA is not installed in your trust store yet."
  info "Run:  mkcert -install     (asks for your password)"
fi
if is_dry; then
  dry "generate $BREW/etc/nginx/certs/local-$TLD.pem with one SAN per project"
else
  "$SCRIPT_DIR/bin/site-cert-regen" || warn "cert generation reported a problem"
fi

# --- done ------------------------------------------------------------------
is_dry && { echo; bold "Dry run complete — no changes made."; echo "  Re-run without --dry-run to apply."; exit 0; }
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
