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
const RECENT    = 6;

/** Sites that are this stack's own tooling, not your projects. */
const STACK_SITES = ['devstack', 'adminer', 'phpmyadmin', 'mailpit'];

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

function command_groups(string $help): array
{
    $groups = []; $current = null;
    foreach (explode("\n", $help) as $line) {
        if (preg_match('/^([A-Z][a-z]+)$/', trim($line), $m) && $line === trim($line)) {
            $current = $m[1]; $groups[$current] = [];
        } elseif ($current && preg_match('/^\s{2}(\S.*?)\s{2,}(\S.*)$/', $line, $m)) {
            $groups[$current][] = [trim($m[1]), trim($m[2])];
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

/** The public hostname of a running quick tunnel, if there is one. */
function live_tunnel(string $brew): ?array
{
    if (trim(shell_exec("pgrep -f '[c]loudflared tunnel' 2>/dev/null") ?: '') === '') return null;

    $url = null;
    // cloudflared serves the quick tunnel hostname on its metrics port.
    $ch = curl_init('http://127.0.0.1:20241/quicktunnel');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body && ($d = json_decode((string) $body, true)) && !empty($d['hostname'])) {
        $url = 'https://' . $d['hostname'];
    }
    if ($url === null) {                       // fall back to what the tunnel wrote
        $state = @file("$brew/var/run/devstack-tunnel", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $url = $state[1] ?? null;
    }

    $site = null;
    $target = @file_get_contents("$brew/etc/nginx/tunnel-target.conf") ?: '';
    if (preg_match('~set \$t_root "([^"]+)"~', $target, $m) && !str_contains($m[1], '/var/empty')) {
        $site = basename(rtrim(str_replace('/public', '', $m[1]), '/'));
    }
    return ['site' => $site, 'url' => $url];
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
$tunnel = live_tunnel($BREW);

// Your projects — not this stack's own tooling, and not directories with
// nothing servable in them.
$sites = array_values(array_filter(
    $all,
    fn($s) => $s['type'] !== '-' && $s['url'] !== '' && !in_array($s['name'], STACK_SITES, true)
));
usort($sites, fn($a, $b) => $b['modified'] <=> $a['modified']);
$problems = array_values(array_filter($checks, fn($c) => $c['status'] !== 'pass'));

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
    ['DNS',      'dnsmasq',            "Answers *.$tld as 127.0.0.1", "/etc/dnsmasq.d/$tld-tld.conf"],
    ['Web',      'nginx',              'One vhost serves every site', '/etc/nginx/servers/local-dev.conf'],
    ['PHP',      'php-fpm',            'Per-version unix sockets',    '/etc/nginx/php-versions.map'],
    ['TLS',      'mkcert',             'One cert, one SAN per site',  "/etc/nginx/certs/local-$tld.pem"],
    ['Database', 'mysql, postgresql',  'Tuned for a laptop',          '/etc/my.cnf'],
    ['Mail',     'mailpit',            'sendmail_path is redirected', '/etc/php/*/conf.d/zz-mailpit.ini'],
    ['Tunnel',   'cloudflared',        'On demand, for webhooks',     '/etc/nginx/servers/zz-tunnel.conf'],
];

$notes = [
    ['Every directory under ~/sites is served',
     "<code>.$tld</code> resolves to 127.0.0.1 and one nginx vhost maps the hostname to the folder. A project with a <code>public/</code> directory serves from it automatically, so Laravel and WordPress both work with no per-site config. Add a folder, run <code>devstack reload</code>."],
    ['Certificates name every site',
     "A <code>*.$tld</code> wildcard is rejected by OpenSSL and Apple's TLS stack — the same rule that blocks <code>*.com</code>. <code>openssl s_client</code> shows such a cert happily, then every browser refuses it. So each site gets its own SAN, which is why adding one needs a reload."],
    ['Nothing listens beyond localhost',
     'nginx, MySQL, Postgres, Redis and Mailpit all bind to 127.0.0.1. Local sites often run with no credentials, and binding to <code>*</code> would expose them to anyone on the same network.'],
    ['Mail never leaves the machine',
     "PHP's <code>sendmail_path</code> points at Mailpit, so every <code>mail()</code> call is captured — WordPress included, with no plugin. Without it, mail goes to the system MTA, which attempts real delivery."],
    ['PHP can differ per site',
     'nginx picks the php-fpm socket from a map keyed on the hostname, so versions run side by side. <code>devstack php 8.2 --site=legacy</code> pins one; the default serves the rest.'],
    ['Tunnels expose one site, briefly',
     'A Cloudflare quick tunnel — random address, no account, gone on ctrl-c. nginx rewrites the local hostname out of every response, so a WordPress site does not serve asset URLs the visitor cannot reach and your database is never touched.'],
    ['This page changes nothing',
     'Any page your browser loads can reach 127.0.0.1, so a panel that could delete a site would be reachable by a malicious page. Creating and removing sites stays in the terminal.'],
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
.wrap{max-width:760px;margin:0 auto;padding:48px 22px 64px}
.hi{font-size:24px;font-weight:600;letter-spacing:-.02em;margin:0}
.status{display:flex;align-items:center;gap:9px;color:var(--dim);font-size:13.5px;margin-top:8px}
.status .dot{width:8px;height:8px;border-radius:50%;background:var(--ok);flex:none}
.status.bad .dot{background:var(--fail)}.status.warn .dot{background:var(--warn)}
.tabs{display:flex;gap:2px;margin:26px 0 18px;border-bottom:1px solid var(--line)}
.tab{padding:8px 14px;font-size:13.5px;color:var(--dim);cursor:pointer;border:0;background:none;
  font-family:inherit;border-bottom:2px solid transparent;margin-bottom:-1px}
.tab:hover{color:var(--fg)}
.tab[aria-selected=true]{color:var(--fg);border-bottom-color:var(--accent);font-weight:500}
.tab .n{color:var(--faint);font-size:12px;margin-left:5px}
.pane{display:none}.pane.on{display:block}
.alert{padding:12px 15px;border-radius:9px;font-size:13.5px;margin-bottom:14px;border:1px solid var(--line);background:var(--panel)}
.alert b{display:block;font-weight:600;margin-bottom:2px}
.alert.warn{border-color:color-mix(in srgb,var(--warn) 50%,var(--line))}
.alert.warn b{color:var(--warn)}
.alert.fail{border-color:color-mix(in srgb,var(--fail) 45%,var(--line))}
.alert.fail b{color:var(--fail)}
.alert span{color:var(--dim);display:block}
.alert a.turl{display:block;font-family:ui-monospace,Menlo,monospace;font-size:13px;color:var(--accent);
  text-decoration:none;margin:3px 0 5px;word-break:break-all}
.alert a.turl:hover{text-decoration:underline}
.tools{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:9px}
.tool{padding:13px 15px;border:1px solid var(--line);border-radius:9px;background:var(--panel);text-decoration:none;color:inherit}
.tool:hover{border-color:var(--accent)}
.tool b{display:block;font-weight:600;font-size:14.5px}
.tool span{color:var(--dim);font-size:12.5px}
.tool.off{opacity:.38;pointer-events:none}
.card{border:1px solid var(--line);border-radius:9px;background:var(--panel);overflow:hidden;margin-top:9px}
.row{display:flex;align-items:center;gap:12px;padding:10px 15px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit}
.row:last-child{border-bottom:0}.row:hover{background:var(--hover)}
.row .nm{font-weight:500;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.row .ty{color:var(--dim);font-size:12.5px;flex:none}
.row .tm{color:var(--faint);font-size:12px;flex:none;width:62px;text-align:right}
input[type=search]{width:100%;padding:10px 14px;border:1px solid var(--line);border-radius:9px;background:var(--panel);color:var(--fg);font:inherit;font-size:14px}
input[type=search]:focus{outline:none;border-color:var(--accent)}
h3.sub{font-size:11.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--faint);margin:22px 0 8px;font-weight:600}
h3.sub:first-child{margin-top:0}
.cmd{display:flex;gap:12px;padding:4px 15px;font-size:13.5px;align-items:baseline}
.cmd code{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;background:var(--code);padding:2px 6px;border-radius:4px;white-space:nowrap;flex:none}
.cmd span{color:var(--dim);font-size:13px}
.grp{padding:12px 0;border-bottom:1px solid var(--line)}
.grp:last-child{border-bottom:0}
.grp h4{font-size:11px;color:var(--faint);margin:0 15px 7px;font-weight:600;letter-spacing:.05em;text-transform:uppercase}
table.arch{width:100%;border-collapse:collapse;font-size:13px}
table.arch td{padding:9px 15px;border-bottom:1px solid var(--line);vertical-align:top}
table.arch tr:last-child td{border-bottom:0}
table.arch td:first-child{font-weight:600;width:76px}
table.arch td:nth-child(2){color:var(--dim);white-space:nowrap;font-family:ui-monospace,Menlo,monospace;font-size:12px}
table.arch td:nth-child(3){color:var(--dim)}
table.arch td:last-child{color:var(--faint);font-family:ui-monospace,Menlo,monospace;font-size:11.5px;text-align:right;white-space:nowrap}
.note{padding:12px 15px;border-bottom:1px solid var(--line);font-size:13.5px;color:var(--dim)}
.note:last-child{border-bottom:0}
.note b{color:var(--fg);font-weight:600;display:block;margin-bottom:2px}
.note code,.alert code{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;background:var(--code);padding:1px 5px;border-radius:4px;white-space:nowrap}
.hidden{display:none}
@media(max-width:620px){table.arch td:last-child{display:none}}
</style>
</head>
<body>
<div class="wrap">

  <h1 class="hi"><?= $greet ?><?= $name ? ', ' . e($name) : '' ?></h1>
  <?php if (!$problems): ?>
    <div class="status"><span class="dot"></span><?= count($sites) ?> sites on <code>.<?= e($tld) ?></code> · all checks passing</div>
  <?php else:
    $worst = in_array('fail', array_column($problems, 'status'), true) ? 'bad' : 'warn'; ?>
    <div class="status <?= $worst ?>"><span class="dot"></span><?= count($problems) ?> thing<?= count($problems) === 1 ? '' : 's' ?> need<?= count($problems) === 1 ? 's' : '' ?> attention</div>
  <?php endif; ?>

  <div class="tabs" role="tablist">
    <button class="tab" role="tab" data-p="overview" aria-selected="true">Overview</button>
    <button class="tab" role="tab" data-p="sites" aria-selected="false">Sites<span class="n"><?= count($sites) ?></span></button>
    <button class="tab" role="tab" data-p="commands" aria-selected="false">Commands</button>
    <button class="tab" role="tab" data-p="reference" aria-selected="false">Reference</button>
  </div>

  <section class="pane on" id="overview">
    <?php foreach ($problems as $p): ?>
      <div class="alert <?= e($p['status'] === 'fail' ? 'fail' : 'warn') ?>">
        <b><?= $p['status'] === 'fail' ? 'Needs fixing' : 'Warning' ?></b><span><?= e($p['message']) ?></span>
      </div>
    <?php endforeach; ?>

    <?php if ($tunnel !== null): ?>
      <div class="alert warn">
        <b>A public tunnel is open<?= $tunnel['site'] ? ' — ' . e($tunnel['site']) : '' ?></b>
        <?php if ($tunnel['url']): ?><a class="turl" href="<?= e($tunnel['url']) ?>"><?= e($tunnel['url']) ?></a><?php endif; ?>
        <span>Reachable by anyone with the address. ctrl-c in the terminal running <code>devstack tunnel</code> closes it.</span>
      </div>
    <?php endif; ?>

    <div class="tools">
      <?php foreach ($tools as [$n, $u, $note, $up]): ?>
        <a class="tool <?= $up ? '' : 'off' ?>" href="<?= e($u) ?>"><b><?= e($n) ?></b><span><?= $up ? e($note) : 'not installed' ?></span></a>
      <?php endforeach; ?>
    </div>

    <h3 class="sub">Recently worked on</h3>
    <div class="card" style="margin-top:0">
      <?php foreach (array_slice($sites, 0, RECENT) as $s): ?>
        <a class="row" href="<?= e($s['url']) ?>">
          <span class="nm"><?= e($s['name']) ?></span>
          <span class="ty"><?= e($s['type']) ?></span>
          <span class="tm"><?= ago((int) $s['modified']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="pane" id="sites">
    <input type="search" id="q" placeholder="Search <?= count($sites) ?> sites…" autocomplete="off">
    <div class="card" id="list">
      <?php foreach ($sites as $s): ?>
        <a class="row" href="<?= e($s['url']) ?>">
          <span class="nm"><?= e($s['name']) ?></span>
          <span class="ty"><?= e($s['type']) ?></span>
          <span class="tm"><?= ago((int) $s['modified']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="pane" id="commands">
    <div class="card" style="margin-top:0">
      <?php foreach ($groups as $g => $cmds): ?>
        <div class="grp"><h4><?= e($g) ?></h4>
          <?php foreach ($cmds as [$cmd, $desc]): ?>
            <div class="cmd"><code><?= e($cmd) ?></code><span><?= e($desc) ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="pane" id="reference">
    <h3 class="sub">Architecture</h3>
    <div class="card" style="margin-top:0">
      <table class="arch">
        <?php foreach ($layers as [$l, $pkg, $what, $path]): ?>
          <tr><td><?= e($l) ?></td><td><?= e($pkg) ?></td><td><?= e($what) ?></td><td><?= e($path) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <h3 class="sub">Design decisions</h3>
    <div class="card" style="margin-top:0">
      <?php foreach ($notes as [$t, $body]): ?>
        <div class="note"><b><?= e($t) ?></b><?= $body ?></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<script>
const tabs = [...document.querySelectorAll('.tab')];
function show(id) {
  tabs.forEach(t => t.setAttribute('aria-selected', String(t.dataset.p === id)));
  document.querySelectorAll('.pane').forEach(p => p.classList.toggle('on', p.id === id));
  history.replaceState(null, '', '#' + id);
}
tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.p)));
if (location.hash) show(location.hash.slice(1));

const q = document.getElementById('q'), rows = [...document.querySelectorAll('#list .row')];
q.addEventListener('input', () => {
  const t = q.value.trim().toLowerCase();
  rows.forEach(r => r.classList.toggle('hidden', !r.textContent.toLowerCase().includes(t)));
});
</script>
</body>
</html>
