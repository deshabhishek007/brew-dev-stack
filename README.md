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

Also installed: `wp-cli`, `composer`, `mysql`, `phpmyadmin`, and optionally
`postgresql` with `pgvector`.

Everything comes from Homebrew except two things, both by necessity:
**Adminer** (no Homebrew formula exists — fetched from adminer.org and checked before
installing) and **WordPress core** (fetched by `wp-cli` when you create a site).

Nothing is hidden. `brew upgrade` maintains the rest.

## Install

```bash
git clone https://github.com/deshabhishek007/brew-dev-stack.git
cd brew-dev-stack
./install.sh
```

See exactly what it would change first:

```bash
./install.sh --dry-run
```

Then follow the three printed steps (they need `sudo`, so the script does not run
them for you): trust the CA, add the resolver file, start the services.

Options — `./install.sh --help` lists them all:

```bash
SITES_DIR=~/code TLD=localdev PHP_VERSION=8.4 ./install.sh
WITH_MYSQL=0 WITH_PHPMYADMIN=0 ./install.sh
```

## Creating a WordPress site

One-time, so sites can be created without prompts afterwards:

```bash
bin/devstack config     # your name, email, admin username → ~/.config/brew-dev-stack/config
```

Then, per site:

```bash
bin/devstack new myblog                    # WordPress (default)
bin/devstack new myapp --type=laravel      # Laravel
bin/devstack new thing --type=plain        # empty PHP project
```

Every type gets a database and a **dedicated database user** — not root, so one site
cannot drop another's data — plus a certificate entry for its hostname.

| Type | What you get |
|---|---|
| `wordpress` | core downloaded and installed, `wp-config.php` with debug logging and `WP_ENVIRONMENT_TYPE=local`, pretty permalinks, and a generated admin password shown once |
| `laravel` | `composer create-project`, `.env` wired to the database and `APP_URL`, migrations run |
| `plain` | a `public/` docroot with a starter `index.php`, and the credentials in `README.md` |

## Where your settings live

`SITES_DIR`, `TLD` and `PHP_VERSION` are chosen at install time and recorded in
`~/.config/brew-dev-stack/config`, so every command afterwards uses the same values.
Environment variables still override them for a single invocation.

```bash
SITES_DIR=~/code TLD=localdev ./install.sh   # recorded, not just used once
```

## Managing sites

```bash
bin/devstack list
```

```
SITE              TYPE            DOCROOT  REPO                        URL
climaone          PHP (composer)  public/  you/climaone-amc            https://climaone.test
ofis-management   Laravel         public/  you/ofis-management         https://ofis-management.test
wpmeta            WordPress       root     you/wpmeta                  https://wpmeta.test
phpmyadmin        PHP (composer)  root     -                           https://phpmyadmin.test →link
```

Type is detected from what is on disk (`wp-config.php`, `artisan`, `bin/console`,
`composer.json`), and the repo column only reports a repository **rooted at that
directory** — without that check, a symlinked site walks up the tree and reports
whatever repo happens to contain its target.

```bash
bin/devstack rm oldsite                    # files + database + db user
bin/devstack rm oldsite --keep-db          # keep the data
bin/devstack rm oldsite --keep-files       # keep the code
```

`rm` shows exactly what it will delete, warns about uncommitted or unpushed git work,
and requires you to type the site name — `y` is too easy to hit by reflex for something
that drops a database.

## Database administration

```bash
bin/devstack install adminer
```

Installs [Adminer](https://www.adminer.org/) at `https://adminer.test` — a single PHP
file that administers **MySQL, PostgreSQL and SQLite** through one UI. It is served by
the stack you already have, so it costs nothing when you are not looking at it.

That matters for PostgreSQL specifically: pgAdmin is an Electron application that stays
resident, which is a poor trade on a machine with limited RAM.

| | |
|---|---|
| MySQL | system `MySQL`, server `127.0.0.1`, user `root`, no password |
| PostgreSQL | system `PostgreSQL`, server `127.0.0.1:5432`, your macOS username |

Homebrew's PostgreSQL trusts local connections by default, so no password is needed.
phpMyAdmin remains available at `https://phpmyadmin.test` if you prefer it for MySQL.

### PostgreSQL and pgvector

```bash
WITH_POSTGRES=1 WITH_PGVECTOR=1 ./install.sh
```

`PG_VERSION` defaults to **18**. This matters: Homebrew's `pgvector` bottle is built
only against recent PostgreSQL majors, so installing it next to an older postgres
*appears to succeed* and then `CREATE EXTENSION vector` fails with "extension not
available" — a confusing failure, because nothing errored at install time.

## Catching outgoing mail

```bash
bin/devstack install mailpit
```

Every WordPress and Laravel site sends mail. Without a catcher, PHP's `mail()` hands off
to the system MTA — on macOS, postfix — which attempts **real MX delivery**. It usually
fails from a home connection, but it fails *silently*, and "usually" is not "never". A
local test of a password reset or an order confirmation can reach a real person.

Mailpit fixes that at the stack level rather than per site:

| | |
|---|---|
| Inbox | `https://mailpit.test` |
| SMTP | `127.0.0.1:1025` |
| Bound to | **127.0.0.1 only** — captured mail contains password-reset links |

`sendmail_path` is set once in `php.ini`, so **every** PHP application is captured —
WordPress included, with no plugin. New Laravel sites additionally get `MAIL_*` written
into `.env`, so queued mail and mailable previews work natively.

Homebrew's own `mailpit` service is deliberately not used: it passes no arguments, so
mailpit listens on every interface, and brew rewrites its plist on each `brew services`
call, so the bind flags cannot live there.

## Debugging

```bash
bin/devstack install xdebug
bin/devstack logs php          # PHP errors
bin/devstack logs mysite       # a site's WordPress debug.log
bin/devstack logs all          # nginx and PHP together
```

Xdebug is configured with `start_with_request = trigger`, so it stays dormant until you
ask for it — always-on step debugging slows every request noticeably. Trigger with
`?XDEBUG_TRIGGER=1`, or a browser helper extension. It listens on port 9003.

**Xdebug is not in Homebrew.** It is a `pecl` build tied to one PHP version, so it lives
outside `brew upgrade` and must be rebuilt after `devstack php <ver>` — the command says
so when it finishes.

New sites also get `WP_DEBUG` / `WP_DEBUG_LOG` (WordPress) and `.env` debug defaults
(Laravel), and PHP errors are written to `$(brew --prefix)/var/log/php-error.log`.

## Redis

```bash
bin/devstack install redis
```

Installs Redis, binds it to 127.0.0.1, and builds the **phpredis** extension — Laravel
can fall back to the pure-PHP `predis` package, but WordPress object-cache plugins need
the extension. Use a different `REDIS_DB` per site to keep them apart.

## Laravel workers

```bash
bin/devstack queue myapp on        # queue:work as a managed agent
bin/devstack schedule myapp on     # schedule:run every minute
bin/devstack queue myapp           # status
```

Both run under launchd, survive logout, and log to the site's own
`storage/logs/devstack-{queue,schedule}.log`. The queue worker uses `--max-time=3600`
so it recycles hourly — a long-lived worker otherwise keeps running stale code after
you edit a job. Workers honour a per-site PHP pin, so they run the same version as the
web requests.

## Public tunnels for webhooks

```bash
bin/devstack tunnel myapp
```

Opens a Cloudflare quick tunnel — a random `trycloudflare.com` URL, no account needed,
gone when you press ctrl-c. This is how you test Stripe, Twilio or GitHub callbacks
against a local site.

`--http-host-header` is set so the wildcard vhost still matches; without it cloudflared
forwards the public hostname and nothing resolves.

**This makes the site reachable by anyone with the URL.** Local sites often run with no
credentials — do not tunnel one holding anything you would not publish.

## Daily use

```bash
bin/devstack help       # grouped command reference
bin/devstack status     # what is running, what is listening
bin/devstack doctor     # diagnose why something is not working
bin/devstack reload     # regenerate the certificate + reload nginx
bin/devstack logs       # tail the error log
```

`status` is an inventory — services, listeners, socket, site count. `doctor` is a
diagnosis — it checks the things that actually break (resolver file, dnsmasq answering,
CA trust, certificate shape, php-fpm log ownership) and tells you what to do about each.

### PHP versions, globally or per site

```bash
bin/devstack php                      # what is in use, and any overrides
bin/devstack php 8.4                  # change the default for every site
bin/devstack php 8.2 --site=legacy    # pin one site
bin/devstack php --site=legacy        # back to the default
```

Each installed version runs its own php-fpm pool on its own socket, and nginx picks
between them with a `map` keyed on the hostname. Changing a site's PHP version is a
config reload, not a new vhost — several versions serve side by side.

Changing the **default** also relinks the CLI to match. A CLI/FPM mismatch is easy to
miss and resolves dependencies against one version while running them under another.

Xdebug is built per version, so re-run `install xdebug` after switching.

### MySQL performance_schema

```bash
bin/devstack mysql perf         # show configured + running state and current memory
bin/devstack mysql perf off     # ~200 MB back
bin/devstack mysql perf on      # when you need query analysis
```

Toggling restarts MySQL and waits for the socket to actually accept connections —
`brew services restart` returns before MySQL is ready, so a fixed sleep is unreliable.

With it off, `performance_schema.*` and `information_schema.global_variables` are
unavailable; use `SHOW VARIABLES` and `SHOW STATUS` instead.

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
