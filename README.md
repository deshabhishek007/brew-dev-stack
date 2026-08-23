# brew-dev-stack

A local PHP development environment built entirely from Homebrew packages you own,
with no application managing it for you.

`nginx` + `dnsmasq` + `php-fpm` + `mkcert`, optionally MySQL and phpMyAdmin. Every
project directory under `~/sites` is served automatically at `https://<name>.test`
with a trusted certificate. No per-site configuration.

```
~/sites/blog        →  https://blog.test
~/sites/shop        →  https://shop.test          (Laravel: serves shop/public)
~/sites/anything    →  https://anything.test
```

## Why

Tools like Laravel Herd and Valet work well, but they own ports 80 and 443, install a
root privileged helper, ship their own PHP builds, and write to `/etc/resolver`. When
something breaks, or when you migrate between them, the leftovers are invisible and
they persist for years.

This is the same setup expressed as plain configuration files:

| Layer | Package | Config |
|---|---|---|
| DNS for `*.test` | `dnsmasq` | `$(brew --prefix)/etc/dnsmasq.d/test-tld.conf` |
| Web server | `nginx` | `$(brew --prefix)/etc/nginx/servers/local-dev.conf` |
| PHP | `php@8.3` | unix socket, per-version log |
| TLS | `mkcert` | locally-trusted certificate |

Nothing is hidden. `brew upgrade` maintains all of it.

## Install

```bash
git clone https://github.com/YOURNAME/brew-dev-stack.git
cd brew-dev-stack
./install.sh
```

Then follow the three printed steps (they need `sudo`, so the script does not run
them for you): trust the CA, add the resolver file, start the services.

Options:

```bash
SITES_DIR=~/code TLD=localdev PHP_VERSION=8.4 ./install.sh
WITH_MYSQL=0 WITH_PHPMYADMIN=0 ./install.sh
```

## Daily use

```bash
bin/devstack status     # what is running, what is listening
bin/devstack doctor     # diagnose the usual failure modes
bin/devstack reload     # regenerate the certificate + reload nginx
bin/devstack logs       # tail the error log
```

After creating a new project directory, run `bin/devstack reload` so the certificate
covers the new hostname.

## Design decisions

These are the non-obvious ones, each learned the hard way.

### No `*.test` wildcard certificate

A wildcard sitting directly under a TLD is rejected by **both OpenSSL and Apple's TLS
stack** — the same rule that prevents `*.com`. The trap is that `openssl s_client`
displays such a certificate happily, so it passes a naive check and then fails in
every browser and every `curl`. `bin/site-cert-regen` enumerates one explicit SAN per
project instead. Re-run it when you add a project.

### Bound to 127.0.0.1, not `*`

Local sites routinely run with no credentials — phpMyAdmin with a passwordless root,
half-configured WordPress admins. Listening on all interfaces exposes every one of
them to anyone on the same network who sends the right `Host` header. On a café or
coworking network that is a real exposure, so the default here is loopback only.

### php-fpm on a unix socket, with a per-version log

Two things bite when several PHP versions are installed. Port 9000 collides, and the
default shared `php-fpm.log` gets created root-owned by whichever version starts
first — after which launchd fails the other version with **status 78** before php-fpm
even runs, and the php-fpm error log stays empty because the failure happens earlier.
A socket and a per-version log avoid both.

### `public/` detected, not configured

The vhost checks for `<project>/public` and uses it when present. Laravel, Symfony and
friends work with no configuration; WordPress and plain PHP serve from the root.

### MySQL tuned for a laptop

Stock MySQL sizes itself for a dedicated server: roughly **550 MB resident while
completely idle**, of which `performance_schema` alone pre-allocates about 200 MB.
The bundled `my.cnf` brings that to **~250 MB**. Re-enable `performance_schema` when
you actually need query analysis — the file documents how.

## Troubleshooting

`bin/devstack doctor` checks all of the below. See [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)
for the full list, including migrating away from Valet or Herd.

## Requirements

macOS (Apple Silicon or Intel) with Homebrew. Works alongside Docker, `nvm`, and
per-project tooling; conflicts with anything else that wants ports 80/443 — including
Herd, Valet, and a system Apache.

## License

MIT
