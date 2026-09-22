<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/src/StateStore.php';
require_once __DIR__ . '/../server/src/BundleValidator.php';
require_once __DIR__ . '/../server/src/DeployExecutor.php';

function bundle(array $files, string $target, string $commit): array {
    ksort($files, SORT_STRING);
    $encoded = [];
    $material = '';
    foreach ($files as $path => $data) {
        $sha = hash('sha256', $data);
        $material .= $path . "\0" . $sha . "\n";
        $encoded[$path] = [
            'sha256' => $sha,
            'encoding' => 'gzip+base64',
            'base64' => base64_encode(gzencode($data, 9)),
        ];
    }
    $manifest = hash('sha256', $material);
    return [
        'format' => 'SAFEDEPLOY_BUNDLE_V1',
        'source_commit' => $commit,
        'target' => $target,
        'manifest_sha256' => $manifest,
        'idempotency_key' => 'deploy:' . hash('sha256', $target . "\0" . $commit . "\0" . $manifest),
        'files' => $encoded,
    ];
}

function expectFailure(callable $fn, string $expected): void {
    try {
        $fn();
        throw new RuntimeException('expected_failure_missing:' . $expected);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'expected_failure_missing:' . $expected) throw $e;
        if ($e->getMessage() !== $expected) {
            throw new RuntimeException('expected=' . $expected . ' got=' . $e->getMessage());
        }
    }
}

$tmp = sys_get_temp_dir() . '/safedeploy-exec-' . bin2hex(random_bytes(5));
$state = $tmp . '/state';
$apps = $tmp . '/public/apps';
mkdir($state, 0700, true);
mkdir($apps, 0755, true);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE safedeploy_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  idempotency_key TEXT NOT NULL UNIQUE,
  target_rel TEXT NOT NULL,
  source_commit TEXT NOT NULL,
  manifest_sha256 TEXT NOT NULL,
  payload_json TEXT NULL,
  status TEXT NOT NULL,
  created_at TEXT NOT NULL,
  finished_at TEXT NULL
)');
$pdo->exec('CREATE TABLE safedeploy_deployments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_id INTEGER NOT NULL UNIQUE,
  target_rel TEXT NOT NULL,
  source_commit TEXT NOT NULL,
  manifest_sha256 TEXT NOT NULL,
  status TEXT NOT NULL,
  backup_path TEXT NULL,
  rollback_copy_path TEXT NULL,
  actor TEXT NOT NULL,
  created_at TEXT NOT NULL,
  promoted_at TEXT NULL,
  rolled_back_at TEXT NULL
)');
$pdo->exec('CREATE TABLE safedeploy_oidc_replay (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_fingerprint TEXT NOT NULL UNIQUE,
  run_id TEXT NOT NULL,
  action_name TEXT NOT NULL,
  created_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE safedeploy_audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id TEXT NOT NULL,
  action_name TEXT NOT NULL,
  result TEXT NOT NULL,
  details_json TEXT NOT NULL,
  created_at TEXT NOT NULL
)');

$config = [
    'state_dir' => $state,
    'deploy_roots' => ['apps' => $apps],
    'limits' => ['max_files' => 100, 'max_bytes' => 1024 * 1024],
];
$store = new StateStore($pdo);
$executor = new DeployExecutor($config, $store);
$auth = ['actor' => 'test', 'repository' => 'example/project'];

$v1 = bundle(['index.html' => 'release-one'], 'apps/demo', str_repeat('1', 40));
$j1 = $executor->stage($v1, $auth, 'req-1');
$d1 = $executor->apply((int)$j1['job_id'], $auth, 'req-2');
if (file_get_contents($apps . '/demo/index.html') !== 'release-one') {
    throw new RuntimeException('release_one_not_promoted');
}

$v2 = bundle(['index.html' => 'release-two'], 'apps/demo', str_repeat('2', 40));
$j2 = $executor->stage($v2, $auth, 'req-3');
$d2 = $executor->apply((int)$j2['job_id'], $auth, 'req-4');
if (file_get_contents($apps . '/demo/index.html') !== 'release-two') {
    throw new RuntimeException('release_two_not_promoted');
}

$executor->rollback((int)$d2['deployment_id'], $auth, 'req-5');
if (file_get_contents($apps . '/demo/index.html') !== 'release-one') {
    throw new RuntimeException('rollback_did_not_restore_release_one');
}

$validator = new BundleValidator($config);
expectFailure(
    fn() => $validator->validate(bundle(['index.html' => 'x'], 'other/demo', str_repeat('3', 40))),
    'target_root_denied'
);

$tampered = bundle(['index.html' => 'valid'], 'apps/tamper', str_repeat('4', 40));
$tampered['files']['index.html']['sha256'] = str_repeat('0', 64);
expectFailure(fn() => $validator->validate($tampered), 'file_hash_mismatch');

$tamperedPayload = bundle(['index.html' => 'valid'], 'apps/tamper2', str_repeat('5', 40));
$tamperedPayload['files']['index.html']['base64'] = base64_encode(gzencode('changed', 9));
expectFailure(fn() => $validator->validate($tamperedPayload), 'file_hash_mismatch');

echo "DEPLOY_EXECUTOR_SYNTHETIC_TESTS=PASS\n";
