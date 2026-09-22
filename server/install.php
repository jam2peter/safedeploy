#!/usr/bin/env php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

$options = getopt('', ['public-dir:', 'private-dir:', 'config:']);
$publicDir = rtrim((string)($options['public-dir'] ?? ''), '/');
$privateDir = rtrim((string)($options['private-dir'] ?? ''), '/');
$configSource = (string)($options['config'] ?? '');

if ($publicDir === '' || $privateDir === '' || $configSource === '') {
    fail('Usage: php server/install.php --public-dir=/path/to/public_html/safedeploy --private-dir=/path/to/safedeploy-private --config=/path/to/config.php');
}
if (!is_file($configSource)) {
    fail('config file not found');
}

$repoServer = __DIR__;
$srcSource = $repoServer . '/src';
$publicSource = $repoServer . '/public/index.php';
$migration = $repoServer . '/migrations/001.sql';

foreach ([$srcSource, $publicSource, $migration] as $required) {
    if (!file_exists($required)) {
        fail("missing source component: {$required}");
    }
}

if (!is_dir($privateDir) && !mkdir($privateDir, 0700, true) && !is_dir($privateDir)) {
    fail('cannot create private directory');
}
chmod($privateDir, 0700);

$privateSrc = $privateDir . '/src';
if (!is_dir($privateSrc) && !mkdir($privateSrc, 0700, true) && !is_dir($privateSrc)) {
    fail('cannot create private src directory');
}
chmod($privateSrc, 0700);

foreach (glob($srcSource . '/*.php') ?: [] as $source) {
    $dest = $privateSrc . '/' . basename($source);
    if (!copy($source, $dest)) fail("copy failed: {$source}");
    chmod($dest, 0600);
}

if (!copy($configSource, $privateDir . '/config.php')) fail('config copy failed');
chmod($privateDir . '/config.php', 0600);

$config = require $privateDir . '/config.php';
if (!is_array($config)) fail('config invalid');
$stateDir = rtrim((string)($config['state_dir'] ?? ''), '/');
if ($stateDir === '') fail('state_dir missing in config');
if (!is_dir($stateDir) && !mkdir($stateDir, 0700, true) && !is_dir($stateDir)) fail('state_dir create failed');
chmod($stateDir, 0700);

$db = (array)($config['db'] ?? []);
$dsn = (string)($db['dsn'] ?? '');
if ($dsn === '') fail('db.dsn missing');
$pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sql = file_get_contents($migration);
if ($sql === false) fail('migration read failed');
$statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql));
if (!is_array($statements)) fail('migration parse failed');
foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement !== '') $pdo->exec($statement);
}

if (!is_dir($publicDir) && !mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
    fail('cannot create public endpoint directory');
}
if (!copy($publicSource, $publicDir . '/index.php')) fail('public endpoint copy failed');
chmod($publicDir . '/index.php', 0644);

echo "SAFEDEPLOY_INSTALL=PASS\n";
echo "PUBLIC_DIR={$publicDir}\n";
echo "PRIVATE_DIR={$privateDir}\n";
echo "SECRET_EXPOSED=NO\n";
