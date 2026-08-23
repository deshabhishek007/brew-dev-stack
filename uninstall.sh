#!/usr/bin/env bash
#
# Remove brew-dev-stack configuration. Does NOT delete your projects,
# your databases, or the Homebrew packages themselves.
#
set -euo pipefail

TLD="${TLD:-test}"
PHP_VERSION="${PHP_VERSION:-8.3}"
SITES_DIR="${SITES_DIR:-$HOME/sites}"
BREW="$(brew --prefix)"

echo "This will:"
echo "  - stop nginx, dnsmasq and php@$PHP_VERSION"
echo "  - remove the generated nginx/dnsmasq config and certificates"
echo "  - remove /etc/resolver/$TLD"
echo
echo "It will NOT touch $SITES_DIR, your databases, or Homebrew packages."
read -r -p "Continue? [y/N] " ans
[[ "$ans" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }

brew services stop "php@$PHP_VERSION" 2>/dev/null || true
sudo brew services stop nginx 2>/dev/null || true
sudo brew services stop dnsmasq 2>/dev/null || true

rm -f "$BREW/etc/nginx/servers/local-dev.conf"
rm -f "$BREW/etc/dnsmasq.d/${TLD}-tld.conf"
rm -f "$BREW/etc/nginx/certs/local-${TLD}.pem" "$BREW/etc/nginx/certs/local-${TLD}-key.pem"
[[ -L "$SITES_DIR/phpmyadmin" ]] && rm -f "$SITES_DIR/phpmyadmin"
sudo rm -f "/etc/resolver/$TLD"

echo
echo "Done. Your nginx.conf backups are at $BREW/etc/nginx/nginx.conf.bak-*"
echo "To also remove the local CA:  mkcert -uninstall"
