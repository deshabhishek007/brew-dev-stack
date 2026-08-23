<?php
header('Content-Type: text/plain');
$bin = dirname(__DIR__) . '/bin/devstack';
echo "bin:        $bin\n";
echo "exists:     " . (is_file($bin) ? 'yes' : 'NO') . "\n";
echo "executable: " . (is_executable($bin) ? 'yes' : 'NO') . "\n";
echo "PATH:       " . (getenv('PATH') ?: '(unset)') . "\n";
echo "whoami:     " . trim(shell_exec('whoami 2>&1') ?: '?') . "\n";
echo "brew:       " . trim(shell_exec('command -v brew 2>&1') ?: '(not found)') . "\n";
echo "--- devstack list --json (stderr shown) ---\n";
echo substr(shell_exec(escapeshellarg($bin) . ' list --json 2>&1') ?: '(no output)', 0, 400) . "\n";
