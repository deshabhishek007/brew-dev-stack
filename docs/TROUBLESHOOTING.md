# Troubleshooting

Run `bin/devstack doctor` first — it checks most of these automatically.

## A site shows a certificate error

The certificate only covers project directories that existed when it was last
generated. Add a project, then:

```bash
bin/devstack reload
```

If **every** site fails with `no alternative certificate subject name matches`,
the certificate is probably a `*.test` wildcard. Wildcards directly under a TLD are
rejected by OpenSSL and by Apple's TLS stack. Regenerate with `bin/site-cert-regen`,
which enumerates explicit names.

Directories whose names are not valid hostnames (spaces, underscores) are skipped and
listed by the script — rename them to have them served.

## `.test` does not resolve

Three things must all be true:

```bash
cat /etc/resolver/test                      # nameserver 127.0.0.1
pgrep -x dnsmasq                            # dnsmasq running
dig +short @127.0.0.1 anything.test         # 127.0.0.1
```

If dnsmasq is running but not answering, it likely started before the config was
written: `sudo brew services restart dnsmasq`.

After changing resolver files: `sudo killall -HUP mDNSResponder`.

## php-fpm will not start (`status 78`)

`brew services list` shows `error 78` and the php-fpm log is empty — because the
failure happens before php-fpm starts. The usual cause is a shared
`$(brew --prefix)/var/log/php-fpm.log` owned by root, created by a different PHP
version that ran as a root LaunchDaemon. launchd cannot open it for stderr
redirection and fails the job.

```bash
ls -l $(brew --prefix)/var/log/php-fpm.log     # root-owned?
mv $(brew --prefix)/var/log/php-fpm.log{,.old} # then restart
```

The installer sets a per-version log to avoid this.

## Sites return 502 Bad Gateway

nginx cannot reach the php-fpm socket.

```bash
ls -l $(brew --prefix)/var/run/php*-fpm.sock   # exists? owned by you?
brew services restart php@8.3
```

The socket must be readable by nginx's worker user — the installer sets
`listen.owner`/`listen.group` to your account, and `nginx.conf` runs workers as you.

## Ports 80/443 already taken

```bash
sudo lsof -nP -iTCP:80 -sTCP:LISTEN
```

Common occupants: Herd (`nginx-arm64` from inside `Herd.app`), Valet, Docker
port bindings, or macOS's built-in Apache (`sudo apachectl stop`).

Two web servers can both hold a listening socket; whichever accepts first wins, so
the symptom is intermittent rather than a clean failure. Stop the other one fully.

## Migrating from Valet or Herd

Both leave configuration behind that keeps working invisibly until it doesn't.
Check for all of these:

```bash
ls ~/.config/valet                                  # Valet's home
ls $(brew --prefix)/etc/nginx/valet                 # nginx include dir
grep -rn valet $(brew --prefix)/etc/nginx/nginx.conf
ls $(brew --prefix)/etc/php/*/php-fpm.d/*valet*     # extra FPM pools
ls $(brew --prefix)/etc/dnsmasq.d/*valet*
ls /Library/LaunchDaemons/homebrew.mxcl.php@*       # root php-fpm daemons
ls /Library/PrivilegedHelperTools/                  # Herd's helper
```

Two that cause real confusion:

- A **root LaunchDaemon for another PHP version** keeps a second php-fpm running and
  causes the status-78 failure above.
- Herd may serve a **Valet-issued certificate** for an old hostname, so TLS fails with
  a name mismatch that has nothing to do with your current configuration. Check what
  is actually being served:

  ```bash
  echo | openssl s_client -connect 127.0.0.1:443 -servername yoursite.test 2>/dev/null \
    | openssl x509 -noout -issuer -ext subjectAltName
  ```

## phpMyAdmin returns 200 but login never completes

`blowfish_secret` is empty. phpMyAdmin renders the page but cookie auth silently
fails. It must be exactly 32 characters:

```bash
grep blowfish $(brew --prefix)/etc/phpmyadmin.config.inc.php
```

If root has no password, `AllowNoPassword` must also be `true`.

## Mail is not appearing in Mailpit

Check in this order.

**1. Is Mailpit running and bound correctly?**

```bash
curl -sf http://127.0.0.1:8025/readyz && echo up
lsof -nP -iTCP:1025 -iTCP:8025 -sTCP:LISTEN
```

You want `127.0.0.1:1025` and `127.0.0.1:8025`. If you see `*:1025` or `[::]:8025`,
Homebrew's own service is running instead of this stack's agent — it passes no bind
flags. Fix with `brew services stop mailpit && bin/devstack install mailpit`.

**2. Is PHP routed to it — in the right PHP?**

```bash
php -i | grep sendmail_path                       # the CLI
```

The CLI and php-fpm read the same `php.ini`, but **php-fpm only picks up a change when
it restarts**. If the CLI shows Mailpit and your site still does not, that is the cause:

```bash
brew services restart php@8.3
```

To check what php-fpm actually has, put `<?php echo ini_get('sendmail_path');` in a site
and load it in a browser. Do not trust the CLI as a proxy for it.

**3. Is the application bypassing `mail()` altogether?**

`sendmail_path` only captures PHP's `mail()`. These bypass it:

- **WP Mail SMTP, FluentSMTP** and similar plugins send over SMTP directly. Point them at
  `127.0.0.1:1025`, no authentication, no encryption.
- **Laravel sites created before you installed Mailpit** have no `MAIL_*` in `.env`.
  New ones are wired automatically; for older ones add:

  ```
  MAIL_MAILER=smtp
  MAIL_HOST=127.0.0.1
  MAIL_PORT=1025
  ```

- Anything calling an external API (SendGrid, Postmark, SES) is not local mail at all
  and **will really send**. Check for API keys in `.env` before testing.

**4. Confirm the whole path with one command**

```bash
php -r 'mail("test@example.test","probe","body","From: probe@local.test");'
curl -s 'http://127.0.0.1:8025/api/v1/messages?limit=1' | head -c 200
```

## If you remove Mailpit, mail escapes again

Deleting the ini file or stopping Mailpit returns PHP to the system MTA, which attempts
real delivery. If you uninstall it, either remove the sites that send mail or set
`sendmail_path` to something inert:

```bash
# $(brew --prefix)/etc/php/<version>/conf.d/zz-mailpit.ini
sendmail_path = "/usr/bin/true"
```

Silently discarding mail is safer locally than silently sending it.

## Measuring memory correctly

`ps` RSS misleads on macOS: a long-idle process has much of its memory compressed out
of residency, so RSS understates it badly, while a freshly restarted one looks larger
than it is. Use `footprint -p <pid>` for a real figure, and judge overall system
pressure from swap usage, compressor footprint and `vm_stat` pageouts rather than
free pages.
