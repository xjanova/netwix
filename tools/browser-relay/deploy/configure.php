<?php

// CLI-only deployment helper; token values are never printed.
if (PHP_SAPI !== 'cli') exit(1);
$root = '/home/admin/domains/netwix.online/public_html';
$private = '/home/admin/netwix-backups/20261019-brainx-urban-relay';
$envPath = $root.'/.env';
$tokenPath = $private.'/relay-token';
if (!is_dir($private)) mkdir($private, 0700, true);
chmod($private, 0700);
if (!file_exists($private.'/.env.before')) {
    if (!copy($envPath, $private.'/.env.before')) throw new RuntimeException('Cannot back up environment');
    chmod($private.'/.env.before', 0600);
}
if (!file_exists($tokenPath)) {
    file_put_contents($tokenPath, bin2hex(random_bytes(32)), LOCK_EX);
    chmod($tokenPath, 0600);
}
$token = trim(file_get_contents($tokenPath));
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Invalid stored token');
$env = file_get_contents($envPath);
foreach (['RONGYOK_BROWSER_RELAY_ENABLED' => 'true', 'RONGYOK_BROWSER_RELAY_TOKEN' => $token] as $key => $value) {
    $line = $key.'='.$value;
    if (preg_match('/^'.preg_quote($key,'/').'=/m', $env)) {
        $env = preg_replace('/^'.preg_quote($key,'/').'=.*$/m', $line, $env);
    } else $env = rtrim($env).PHP_EOL.$line.PHP_EOL;
}
$temp = $envPath.'.relay-tmp';
if (file_put_contents($temp, $env, LOCK_EX) === false) throw new RuntimeException('Cannot write environment');
chmod($temp, 0600);
if (!rename($temp, $envPath)) throw new RuntimeException('Cannot activate environment');
echo "Relay configuration written; private token retained outside web root.\n";
