<?php
/**
 * brew-dev-stack — entry point and reference.
 *
 * Read-only by design: any page the browser loads can reach 127.0.0.1, so a
 * panel that could create or delete sites would be exposed to CSRF and DNS
 * rebinding. Destructive operations stay in the terminal.
 */
declare(strict_types=1);

const CACHE_TTL = 15;
const RECENT    = 8;

$ROOT = dirname(__DIR__);
$BREW = trim(shell_exec('brew --prefix 2>/dev/null') ?: '/opt/homebrew');

function devstack(string $args, bool $json = true): string|array
{
    $bin   = dirname(__DIR__) . '/bin/devstack';
    $cache = sys_get_temp_dir() . '/devstack-web-' . md5($args) . ($json ? '.json' : '.txt');
    if (is_file($cache) && (time() - filemtime($cache)) < CACHE_TTL) {
        $raw = (string) file_get_contents($cache);
    } else {
        $raw = shell_exec(escapeshellarg($bin) . ' ' . $args . ($json ? ' --json' : '') . ' 2>/dev/null') ?: '';
        @file_put_contents($cache, $raw);
    }
    return $json ? (json_decode($raw, true) ?: []) : $raw;
}

/** Parse `devstack help` so the reference cannot drift from the CLI. */
function command_groups(string $help): array
{
    $groups = []; $current = null;
    foreach (explode("\n", $help) as $line) {
        if (preg_match('/^([A-Z][a-z]+)$/', trim($line), $m) && $line === trim($line)) {
            $current = $m[1]; $groups[$current] = [];
        } elseif ($current && preg_match('/^\s{2}(\S.*?)\s{2,}(\S.*)$/', $line, $m)) {
            $groups[$current][] = [trim($m[1]), trim($m[2])];
        } elseif ($current && preg_match('/^\s{2}(\S.*)$/', $line, $m) && !str_contains($line, '  ')) {
            $groups[$current][] = [trim($m[1]), ''];
        }
    }
    return array_filter($groups);
}

function config_value(string $key): string
{
    $f = ($_SERVER['HOME'] ?? getenv('HOME')) . '/.config/brew-dev-stack/config';
    if (!is_file($f)) return '';
    foreach (file($f) as $line) {
        if (preg_match('/^' . preg_quote($key, '/') . '="?([^"\n]*)"?/', $line, $m)) return $m[1];
    }
    return '';
}

function ago(int $ts): string
{
    $d = time() - $ts;
    return match (true) {
        $d < 3600   => max(1, intdiv($d, 60)) . 'm ago',
        $d < 86400  => intdiv($d, 3600) . 'h ago',
        $d < 604800 => intdiv($d, 86400) . 'd ago',
        default     => date('j M', $ts),
    };
}

function e(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }

$tld    = getenv('TLD') ?: 'test';
$all    = devstack('list');
$checks = devstack('doctor');
$groups = command_groups((string) devstack('help', false));

$sites = array_values(array_filter($all, fn($s) => $s['type'] !== '-' && $s['url'] !== ''));
usort($sites, fn($a, $b) => $b['modified'] <=> $a['modified']);
$problems = array_values(array_filter($checks, fn($c) => $c['status'] !== 'pass'));

// A forgotten tunnel leaves the machine reachable from the internet, so this
// is worth stating loudly rather than leaving it to be noticed.
$tunnel = null;
$cf = trim(shell_exec("pgrep -f '[c]loudflared tunnel' 2>/dev/null") ?: '');
if ($cf !== '') {
    // The state file carries the public URL; cloudflared running is what makes
    // it true. Requiring both means neither a stale file nor an unrelated
    // cloudflared process can produce a wrong answer.
    $state = @file("$BREW/var/run/devstack-tunnel", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $tunnel = ['site' => $state[0] ?? null, 'url' => $state[1] ?? null];
    if ($tunnel['site'] === null) {
        $target = @file_get_contents("$BREW/etc/nginx/tunnel-target.conf") ?: '';
        if (preg_match('~set \$t_root "([^"]+)"~', $target, $m) && !str_contains($m[1], '/var/empty')) {
            $tunnel['site'] = basename(rtrim(str_replace('/public', '', $m[1]), '/'));
        }
    }
}

$name  = trim(explode(' ', config_value('ADMIN_NAME'))[0] ?? '');
$hour  = (int) date('G');
$greet = $hour < 5 ? 'Still up' : ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'));

$names = array_column($all, 'name');
$tools = [
    ['Mailpit',    "https://mailpit.$tld",    'Everything your sites send', true],
    ['Adminer',    "https://adminer.$tld",    'MySQL · Postgres · SQLite',  in_array('adminer', $names, true)],
    ['phpMyAdmin', "https://phpmyadmin.$tld", 'MySQL',                     in_array('phpmyadmin', $names, true)],
];

$layers = [
    ['DNS',      'dnsmasq',                "Answers *.$tld as 127.0.0.1", "$BREW/etc/dnsmasq.d/$tld-tld.conf"],
    ['Web',      'nginx',                  'One vhost serves every site', "$BREW/etc/nginx/servers/local-dev.conf"],
    ['PHP',      'php-fpm',                'Per-version unix sockets',    "$BREW/etc/nginx/php-versions.map"],
    ['TLS',      'mkcert',                 'One cert, one SAN per site',  "$BREW/etc/nginx/certs/local-$tld.pem"],
    ['Database', 'mysql, postgresql',       'Tuned for a laptop',          "$BREW/etc/my.cnf"],
    ['Mail',     'mailpit',                'sendmail_path is redirected', "$BREW/etc/php/*/conf.d/zz-mailpit.ini"],
    ['Tunnel',   'cloudflared',            'On demand, for webhooks',     "$BREW/etc/nginx/servers/zz-tunnel.conf"],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>devstack</title>
<style>
:root{--bg:#fbfbfa;--panel:#fff;--fg:#1a1a18;--dim:#82827a;--faint:#a8a89f;--line:#e7e7e2;--accent:#b8563f;--ok:#2f7d4f;--warn:#8a5d00;--fail:#b3261e;--hover:#f5f5f2;--code:#f2f2ee}
@media (prefers-color-scheme:dark){:root{--bg:#171716;--panel:#1f1f1e;--fg:#ededea;--dim:#8d8d84;--faint:#6b6b63;--line:#2e2e2b;--accent:#d97757;--ok:#5cbe83;--warn:#d9a441;--fail:#e5786d;--hover:#262624;--code:#252523}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.6 ui-sans-serif,-apple-system,system-ui,sans-serif}
.wrap{max-width:800px;margin:0 auto;padding:60px 22px 100px}
.hi{font-size:26px;font-weight:600;letter-spacing:-.02em;margin:0}
.status{display:flex;align-items:center;gap:9px;color:var(--dim);font-size:14px;margin-top:9px}
.status .dot{width:8px;height:8px;border-radius:50%;background:var(--ok);flex:none}
.status.bad .dot{background:var(--fail)}.status.warn .dot{background:var(--warn)}
.issues{margin:18px 0 0;padding:0;list-style:none}
.issues li{padding:11px 14px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;margin-bottom:6px;background:var(--panel)}
.issues li.fail{border-color:color-mix(in srgb,var(--fail) 45%,var(--line));color:var(--fail)}
.issues li.warn{color:var(--warn)}
h2{font-size:11.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--faint);margin:46px 0 13px;font-weight:600}
.tools{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:9px}
.tool{padding:13px 15px;border:1px solid var(--line);border-radius:9px;background:var(--panel);text-decoration:none;color:inherit;transition:border-color .12s}
.tool:hover{border-color:var(--accent)}
.tool b{display:block;font-weight:600;font-size:14.5px}
.tool span{color:var(--dim);font-size:12.5px}
.tool.off{opacity:.38;pointer-events:none}
.card{border:1px solid var(--line);border-radius:9px;background:var(--panel);overflow:hidden}
.row{display:flex;align-items:center;gap:12px;padding:10px 15px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit}
.row:last-child{border-bottom:0}.row:hover{background:var(--hover)}
.row .nm{font-weight:500;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.row .ty{color:var(--dim);font-size:12.5px;flex:none}
.row .tm{color:var(--faint);font-size:12px;flex:none;width:62px;text-align:right}
input[type=search]{width:100%;padding:10px 14px;border:1px solid var(--line);border-radius:9px;background:var(--panel);color:var(--fg);font:inherit;font-size:14px;margin-bottom:9px}
input[type=search]:focus{outline:none;border-color:var(--accent)}
.more{display:block;text-align:center;padding:10px;color:var(--dim);font-size:13px;cursor:pointer;border-top:1px solid var(--line)}
.more:hover{color:var(--accent)}
.grp{padding:13px 15px;border-bottom:1px solid var(--line)}
.grp:last-child{border-bottom:0}
.grp h3{font-size:12px;color:var(--faint);margin:0 0 9px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
.cmd{display:flex;gap:14px;padding:3px 0;font-size:13.5px;align-items:baseline}
.cmd code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;background:var(--code);padding:2px 6px;border-radius:4px;white-space:nowrap;flex:none;min-width:0}
.cmd span{color:var(--dim);font-size:13px;min-width:0}
table.arch{width:100%;border-collapse:collapse;font-size:13px}
table.arch td{padding:9px 15px;border-bottom:1px solid var(--line);vertical-align:top}
table.arch tr:last-child td{border-bottom:0}
table.arch td:first-child{font-weight:600;width:78px}
table.arch td:nth-child(2){color:var(--dim);width:auto;white-space:nowrap;font-family:ui-monospace,Menlo,monospace;font-size:12px}
table.arch td:nth-child(3){color:var(--dim)}
table.arch td:last-child{color:var(--faint);font-family:ui-monospace,Menlo,monospace;font-size:11.5px;text-align:right;white-space:nowrap}
.note{padding:13px 15px;border-bottom:1px solid var(--line);font-size:13.5px;color:var(--dim)}
.note:last-child{border-bottom:0}
.note b{color:var(--fg);font-weight:600;display:block;margin-bottom:2px;font-size:13.5px}
.note code{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;background:var(--code);
  padding:1px 5px;border-radius:4px;white-space:nowrap}
.tunnel{margin:18px 0 0;padding:12px 15px;border-radius:9px;font-size:13.5px;
  border:1px solid color-mix(in srgb,var(--warn) 50%,var(--line));background:var(--panel)}
.tunnel b{display:block;color:var(--warn);font-weight:600;margin-bottom:2px}
.tunnel span{color:var(--dim);display:block}
.tunnel .turl{display:block;font-family:ui-monospace,Menlo,monospace;font-size:13px;
  color:var(--accent);text-decoration:none;margin:4px 0 6px;word-break:break-all}
.tunnel .turl:hover{text-decoration:underline}
.hidden{display:none}
@media(max-width:620px){table.arch td:last-child{display:none}}
</style>
</head>
<body>
<div class="wrap">

  <h1 class="hi"><?= $greet ?><?= $name ? ', ' . e($name) : '' ?></h1>
  <?php if (!$problems): ?>
    <div class="status"><span class="dot"></span><?= count($sites) ?> sites running on <code>.<?= e($tld) ?></code> · all checks passing</div>
  <?php else:
    $worst = in_array('fail', array_column($problems, 'status'), true) ? 'bad' : 'warn'; ?>
    <div class="status <?= $worst ?>"><span class="dot"></span><?= count($problems) ?> thing<?= count($problems) === 1 ? '' : 's' ?> need<?= count($problems) === 1 ? 's' : '' ?> attention</div>
    <ul class="issues">
      <?php foreach ($problems as $p): ?><li class="<?= e($p['status']) ?>"><?= e($p['message']) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($tunnel !== null): ?>
    <div class="tunnel">
      <b>A public tunnel is open</b>
      <?php if ($tunnel['url']): ?>
        <a href="<?= e($tunnel['url']) ?>" class="turl"><?= e($tunnel['url']) ?></a>
        <span>serving <?= e($tunnel['site'] ?? '?') ?> — reachable by anyone with this address.
              Close it with ctrl-c in the terminal running <code>devstack tunnel</code>.</span>
      <?php else: ?>
        <span><?= e($tunnel['site'] ?? 'A site') ?> is reachable from the internet right now.
              Close it with ctrl-c in the terminal running <code>devstack tunnel</code>.</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h2>Tools</h2>
  <div class="tools">
    <?php foreach ($tools as [$n, $u, $note, $up]): ?>
      <a class="tool <?= $up ? '' : 'off' ?>" href="<?= e($u) ?>"><b><?= e($n) ?></b><span><?= $up ? e($note) : 'not installed' ?></span></a>
    <?php endforeach; ?>
  </div>

  <h2>Sites</h2>
  <input type="search" id="q" placeholder="Search <?= count($sites) ?> sites…" autocomplete="off">
  <div class="card" id="list">
    <?php foreach ($sites as $i => $s): ?>
      <a class="row<?= $i >= RECENT ? ' extra hidden' : '' ?>" href="<?= e($s['url']) ?>">
        <span class="nm"><?= e($s['name']) ?></span>
        <span class="ty"><?= e($s['type']) ?></span>
        <span class="tm"><?= ago((int) $s['modified']) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (count($sites) > RECENT): ?><span class="more" id="more">Show <?= count($sites) - RECENT ?> more</span><?php endif; ?>
  </div>

  <h2>Commands</h2>
  <div class="card">
    <?php foreach ($groups as $g => $cmds): ?>
      <div class="grp">
        <h3><?= e($g) ?></h3>
        <?php foreach ($cmds as [$cmd, $desc]): ?>
          <div class="cmd"><code><?= e($cmd) ?></code><span><?= e($desc) ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <h2>How it works</h2>
  <div class="card">
    <table class="arch">
      <?php foreach ($layers as [$layer, $pkg, $what, $path]): ?>
        <tr><td><?= e($layer) ?></td><td><?= e($pkg) ?></td><td><?= e($what) ?></td>
            <td><?= e(str_replace($BREW, '', $path)) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <h2>Worth knowing</h2>
  <div class="card">
    <div class="note"><b>Every directory under ~/sites is served</b>
      <code>.<?= e($tld) ?></code> resolves to 127.0.0.1, and one nginx vhost maps the hostname to the folder.
      A project with a <code>public/</code> directory serves from it automatically, so Laravel and
      WordPress both work with no per-site config. Add a folder, run <code>devstack reload</code>.</div>

    <div class="note"><b>Certificates list every site by name</b>
      A <code>*.<?= e($tld) ?></code> wildcard is rejected by OpenSSL and Apple's TLS stack — the same rule
      that blocks <code>*.com</code>. <code>openssl s_client</code> shows such a cert happily, then every
      browser refuses it. So each site gets its own SAN, which is why adding a site needs a reload.</div>

    <div class="note"><b>Nothing listens beyond localhost</b>
      nginx, MySQL, Postgres, Redis and Mailpit all bind to 127.0.0.1. Local sites often run with
      no credentials, and binding to <code>*</code> would expose them to anyone on the same network.</div>

    <div class="note"><b>Mail never leaves the machine</b>
      PHP's <code>sendmail_path</code> points at Mailpit, so every <code>mail()</code> call is captured —
      WordPress included, with no plugin. Without it, mail goes to the system MTA, which attempts
      real delivery.</div>

    <div class="note"><b>PHP can differ per site</b>
      nginx picks the php-fpm socket from a map keyed on the hostname, so versions run side by side.
      <code>devstack php 8.2 --site=legacy</code> pins one; the default serves the rest.</div>

    <div class="note"><b>Tunnels expose one site, briefly</b>
      <code>devstack tunnel &lt;name&gt;</code> opens a Cloudflare quick tunnel — a random
      trycloudflare.com address, no account, gone when you press ctrl-c. nginx rewrites the local
      hostname out of every response, so a WordPress site does not serve asset URLs the visitor
      cannot reach, and your database is never touched. Only the site you name is exposed; anyone
      with the URL can reach it, so do not tunnel anything private.</div>

    <div class="note"><b>This page changes nothing</b>
      Any page your browser loads can reach 127.0.0.1, so a panel that could delete a site would be
      reachable by a malicious page. Creating and removing sites stays in the terminal.</div>
  </div>
</div>

<script>
const q = document.getElementById('q'), more = document.getElementById('more'),
      rows = [...document.querySelectorAll('#list .row')];
more?.addEventListener('click', () => { rows.forEach(r => r.classList.remove('hidden')); more.remove(); });
q.addEventListener('input', () => {
  const t = q.value.trim().toLowerCase();
  rows.forEach((r, i) => r.classList.toggle('hidden', t ? !r.textContent.toLowerCase().includes(t) : i >= <?= RECENT ?>));
  more?.classList.toggle('hidden', !!t);
});
</script>
</body>
</html>
