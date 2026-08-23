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

## Measuring memory correctly

`ps` RSS misleads on macOS: a long-idle process has much of its memory compressed out
of residency, so RSS understates it badly, while a freshly restarted one looks larger
than it is. Use `footprint -p <pid>` for a real figure, and judge overall system
pressure from swap usage, compressor footprint and `vm_stat` pageouts rather than
free pages.
