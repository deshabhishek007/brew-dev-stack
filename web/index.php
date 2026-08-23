<?php
/**
 * brew-dev-stack dashboard — read-only.
 *
 * Deliberately has no controls that change anything. Any page your browser
 * loads can issue requests to 127.0.0.1, so a local control panel that could
 * create or delete sites would be reachable by a malicious page via CSRF or
 * DNS rebinding. Destructive operations stay in the terminal.
 */
declare(strict_types=1);

const CACHE_TTL = 15;   // `list` walks every site and runs git; ~2.5s on 87 sites.

function devstack(string $args): array
{
    $bin = dirname(__DIR__) . '/bin/devstack';
    $cache = sys_get_temp_dir() . '/devstack-web-' . md5($args) . '.json';

    if (is_file($cache) && (time() - filemtime($cache)) < CACHE_TTL) {
        $raw = file_get_contents($cache);
    } else {
        $raw = shell_exec(escapeshellarg($bin) . ' ' . $args . ' --json 2>/dev/null') ?: '[]';
        @file_put_contents($cache, $raw);
    }
    return json_decode($raw, true) ?: [];
}

function tool_up(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 1,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code > 0 && $code < 500;
}

$tld    = getenv('TLD') ?: 'test';
$sites  = devstack('list');
$checks = devstack('doctor');

$tools = [
    'Mailpit'    => ['url' => "https://mailpit.$tld",    'note' => 'captured mail'],
    'Adminer'    => ['url' => "https://adminer.$tld",    'note' => 'MySQL · PostgreSQL · SQLite'],
    'phpMyAdmin' => ['url' => "https://phpmyadmin.$tld", 'note' => 'MySQL'],
];
foreach ($tools as $name => $t) {
    $tools[$name]['up'] = in_array($name === 'Mailpit' ? 'mailpit' : ($name === 'Adminer' ? 'adminer' : 'phpmyadmin'),
        array_column($sites, 'name'), true);
}

$failing = array_filter($checks, fn($c) => $c['status'] === 'fail');
$warning = array_filter($checks, fn($c) => $c['status'] === 'warn');
$served  = array_filter($sites, fn($s) => $s['url'] !== '');

$byType = [];
foreach ($sites as $s) { $byType[$s['type']] = ($byType[$s['type']] ?? 0) + 1; }
arsort($byType);

function e(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>devstack</title>
<style>
:root{--bg:#fbfbfa;--panel:#fff;--fg:#1a1a18;--dim:#77776e;--line:#e6e6e1;--accent:#b8563f;--ok:#2f7d4f;--warn:#9a6b00;--fail:#b3261e}
@media (prefers-color-scheme:dark){:root{--bg:#191918;--panel:#212120;--fg:#eee;--dim:#8b8b82;--line:#32322f;--accent:#d97757;--ok:#5cbe83;--warn:#d9a441;--fail:#e5786d}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 ui-sans-serif,-apple-system,system-ui,sans-serif}
.wrap{max-width:1100px;margin:0 auto;padding:32px 20px 64px}
h1{font-size:22px;font-weight:600;margin:0}
h1 span{color:var(--dim);font-weight:400}
h2{font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);margin:32px 0 12px;font-weight:600}
.head{display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:10px}
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.stat{flex:1;min-width:120px;padding:14px 16px}
.stat b{display:block;font-size:24px;font-weight:600;letter-spacing:-.02em}
.stat span{color:var(--dim);font-size:12px}
.tools{display:flex;gap:10px;flex-wrap:wrap}
.tool{flex:1;min-width:200px;padding:14px 16px;text-decoration:none;color:inherit;display:block}
.tool:hover{border-color:var(--accent)}
.tool b{display:block;font-weight:600}
.tool span{color:var(--dim);font-size:12.5px}
.tool.off{opacity:.45}
.checks{padding:4px 0;overflow:hidden}
.check{display:flex;gap:10px;padding:9px 16px;border-bottom:1px solid var(--line);font-size:13.5px}
.check:last-child{border-bottom:0}
.dot{width:7px;height:7px;border-radius:50%;margin-top:7px;flex:none}
.pass .dot{background:var(--ok)}.warn .dot{background:var(--warn)}.fail .dot{background:var(--fail)}
.fail{color:var(--fail)}.warn{color:var(--warn)}
input[type=search]{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--fg);font:inherit;font-size:14px}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th{text-align:left;font-weight:600;color:var(--dim);font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;padding:10px 16px;border-bottom:1px solid var(--line)}
td{padding:9px 16px;border-bottom:1px solid var(--line)}
tr:last-child td{border-bottom:0}
td a{color:var(--accent);text-decoration:none}
td a:hover{text-decoration:underline}
.muted{color:var(--dim)}
.tag{font-size:11px;padding:1px 7px;border:1px solid var(--line);border-radius:20px;color:var(--dim)}
.foot{margin-top:28px;color:var(--dim);font-size:12.5px}
code{background:var(--panel);border:1px solid var(--line);border-radius:4px;padding:1px 5px;font-size:12px}
</style>
</head>
<body>
<div class="wrap">

  <div class="head">
    <h1>devstack <span>· <?= e($tld) ?></span></h1>
    <div class="muted" style="font-size:12.5px">
      <?= count($failing) ? '<span class="fail">' . count($failing) . ' failing</span>'
        : (count($warning) ? '<span class="warn">' . count($warning) . ' warning</span>'
        : '<span style="color:var(--ok)">all checks passing</span>') ?>
    </div>
  </div>

  <div class="stats">
    <div class="stat panel"><b><?= count($served) ?></b><span>sites served</span></div>
    <div class="stat panel"><b><?= count($sites) ?></b><span>directories</span></div>
    <?php foreach (array_slice($byType, 0, 3, true) as $type => $n):
      if ($type === '-') continue; ?>
      <div class="stat panel"><b><?= $n ?></b><span><?= e($type) ?></span></div>
    <?php endforeach; ?>
  </div>

  <h2>Tools</h2>
  <div class="tools">
    <?php foreach ($tools as $name => $t): ?>
      <a class="tool panel <?= $t['up'] ? '' : 'off' ?>" href="<?= e($t['url']) ?>">
        <b><?= e($name) ?></b><span><?= $t['up'] ? e($t['note']) : 'not installed' ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <h2>Health</h2>
  <div class="panel checks">
    <?php foreach ($checks as $c): ?>
      <div class="check <?= e($c['status']) ?>"><span class="dot"></span><span><?= e($c['message']) ?></span></div>
    <?php endforeach; ?>
    <?php if (!$checks): ?><div class="check"><span class="muted">no checks returned</span></div><?php endif; ?>
  </div>

  <h2>Sites</h2>
  <input type="search" id="q" placeholder="Filter by name, type or repository…" autocomplete="off">
  <div class="panel" style="margin-top:10px;overflow:hidden">
    <table id="sites">
      <thead><tr><th>Site</th><th>Type</th><th>Docroot</th><th>Repository</th></tr></thead>
      <tbody>
      <?php foreach ($sites as $s): ?>
        <tr>
          <td><?= $s['url']
                ? '<a href="' . e($s['url']) . '">' . e($s['name']) . '</a>'
                : '<span class="muted">' . e($s['name']) . '</span> <span class="tag">not a hostname</span>' ?>
              <?= $s['symlink'] ? ' <span class="tag">link</span>' : '' ?></td>
          <td><?= $s['type'] === '-' ? '<span class="muted">—</span>' : e($s['type']) ?></td>
          <td class="muted"><?= e($s['docroot']) ?></td>
          <td class="muted"><?= $s['repo'] === '-' ? '—' : e($s['repo']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="foot">
    Read-only. Create and remove sites with <code>devstack new</code> and <code>devstack rm</code>.
    Data cached for <?= CACHE_TTL ?>s.
  </p>
</div>

<script>
const q = document.getElementById('q');
const rows = [...document.querySelectorAll('#sites tbody tr')];
q.addEventListener('input', () => {
  const t = q.value.toLowerCase();
  rows.forEach(r => { r.style.display = r.textContent.toLowerCase().includes(t) ? '' : 'none'; });
});
</script>
</body>
</html>
