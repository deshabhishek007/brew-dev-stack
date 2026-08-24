#!/usr/bin/env bash
#
# Remove everything brew-dev-stack installed or generated — the disease this
# project exists to cure is tools that leave invisible leftovers, so the
# uninstaller must know about every single thing the stack can create.
#
# It does NOT touch: your projects in $SITES_DIR, MySQL/PostgreSQL/Redis data,
# or any Homebrew package. Those are yours.
#
set -euo pipefail

TLD="${TLD:-test}"
SITES_DIR="${SITES_DIR:-$HOME/sites}"
CONFIG_FILE="${XDG_CONFIG_HOME:-$HOME/.config}/brew-dev-stack/config"
# shellcheck source=/dev/null
[[ -f "$CONFIG_FILE" ]] && . "$CONFIG_FILE"

if command -v brew >/dev/null 2>&1; then BREW="$(brew --prefix)"
elif [[ -x /opt/homebrew/bin/brew ]]; then BREW=/opt/homebrew
else BREW=/usr/local; fi
export PATH="$BREW/bin:$BREW/sbin:/usr/bin:/bin:/usr/sbin:/sbin${PATH:+:$PATH}"

ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
skip() { printf '  \033[2m·\033[0m %s\n' "$*"; }

cat <<EOF
This removes everything brew-dev-stack installed or generated:

  services   nginx, dnsmasq, every php@ FPM, the Mailpit agent, all site workers
  nginx      the vhosts, the PHP-version map, tunnel targets, certificates
  php        the stack's conf.d files in EVERY installed version
  tooling    the devstack symlink, the MCP registration, the dashboard,
             adminer and phpmyadmin links in $SITES_DIR
  system     /etc/resolver/$TLD (needs sudo)

It does NOT touch your projects, your MySQL/PostgreSQL/Redis data, or any
Homebrew package. Config backups (*.bak-*) are left for you.

EOF
read -r -p "Continue? [y/N] " ans
[[ "$ans" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
echo

# --- services --------------------------------------------------------------
for f in $(brew list --formula 2>/dev/null | grep -E '^php@[0-9.]+$'); do
  brew services stop "$f" >/dev/null 2>&1 && ok "stopped $f" || skip "$f not running"
done
sudo brew services stop nginx   >/dev/null 2>&1 && ok "stopped nginx"   || skip "nginx not running as a root service"
sudo brew services stop dnsmasq >/dev/null 2>&1 && ok "stopped dnsmasq" || skip "dnsmasq not running as a root service"

# Mailpit runs from our own agent, not brew's service.
launchctl bootout "gui/$(id -u)/dev.brewdevstack.mailpit" 2>/dev/null || true
rm -f "$HOME/Library/LaunchAgents/dev.brewdevstack.mailpit.plist" && ok "mailpit agent removed"

# Laravel queue/schedule workers, one agent per site.
found_worker=0
for plist in "$HOME"/Library/LaunchAgents/dev.brewdevstack.*.plist; do
  [[ -e "$plist" ]] || continue
  label="$(basename "$plist" .plist)"
  launchctl bootout "gui/$(id -u)/$label" 2>/dev/null || true
  rm -f "$plist"
  ok "worker removed: $label"
  found_worker=1
done
[[ "$found_worker" == 0 ]] && skip "no site workers installed"

# --- nginx -----------------------------------------------------------------
rm -f "$BREW/etc/nginx/servers/local-dev.conf" \
      "$BREW/etc/nginx/servers/zz-tunnel.conf" \
      "$BREW/etc/nginx/servers/mailpit.conf" \
      "$BREW/etc/nginx/php-versions.map" \
      "$BREW/etc/nginx/tunnel-target.conf" \
      "$BREW/etc/nginx/tunnel-subs.conf" \
      "$BREW/etc/nginx/certs/local-${TLD}.pem" \
      "$BREW/etc/nginx/certs/local-${TLD}-key.pem"
ok "nginx vhosts, map, tunnel targets and certificates removed"

# --- php: the stack's conf.d in every version ------------------------------
for ini in "$BREW"/etc/php/*/conf.d/zz-error-log.ini \
           "$BREW"/etc/php/*/conf.d/zz-xdebug.ini \
           "$BREW"/etc/php/*/conf.d/zz-redis.ini; do
  [[ -e "$ini" ]] && rm -f "$ini"
done
ok "stack php configuration removed from every version"

# Removing the Mailpit redirection would silently return mail() to the real
# MTA — a test site could then email a real person. Point it at /usr/bin/true
# instead: mail is discarded, not delivered. Delete these files to undo that.
for ini in "$BREW"/etc/php/*/conf.d/zz-mailpit.ini; do
  [[ -e "$ini" ]] || continue
  cat > "$ini" <<'MAILEOF'
; Left by brew-dev-stack's uninstaller. Without this, mail() goes to the system
; MTA, which attempts REAL delivery. Discarding is the safer default; delete
; this file if you want the system MTA back.
sendmail_path = "/usr/bin/true"
MAILEOF
done
ok "mail() now discards instead of delivering (see zz-mailpit.ini comment)"

# --- tooling ---------------------------------------------------------------
rm -f "$BREW/bin/devstack" && ok "devstack removed from PATH"
command -v claude >/dev/null 2>&1 && claude mcp remove -s user devstack >/dev/null 2>&1 \
  && ok "MCP registration removed" || skip "no MCP registration (or claude CLI absent)"
for link in devstack phpmyadmin; do
  [[ -L "$SITES_DIR/$link" ]] && rm -f "$SITES_DIR/$link" && ok "$link link removed"
done
if [[ -f "$SITES_DIR/adminer/index.php" ]] && [[ "$(find "$SITES_DIR/adminer" -type f | wc -l | tr -d ' ')" -le 2 ]]; then
  rm -rf "$SITES_DIR/adminer" && ok "adminer removed"
fi
rm -f "$BREW/var/run/devstack-tunnel"

# --- system ----------------------------------------------------------------
sudo rm -f "/etc/resolver/$TLD" && ok "/etc/resolver/$TLD removed"

echo
echo "Done. Also worth knowing:"
echo "  · Homebrew packages remain:  brew uninstall nginx dnsmasq mkcert wp-cli … as you wish"
echo "  · pecl builds (xdebug, redis) live inside each php@ keg and go with it"
echo "  · databases and their data are untouched"
echo "  · the local CA remains trusted:  mkcert -uninstall  to revoke it"
echo "  · config backups (*.bak-*) in $BREW/etc were deliberately kept"
