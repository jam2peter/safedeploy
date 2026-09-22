<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

$requestId = bin2hex(random_bytes(8));
$action = trim((string)($_GET['action'] ?? 'health'));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$defaultPrivate = $docRoot !== '' ? dirname($docRoot) . '/safedeploy-private' : '';
$private = rtrim((string)(getenv('SAFEDEPLOY_PRIVATE') ?: $defaultPrivate), '/');
$configFile = $private . '/config.php';

if ($action === 'health' && $method === 'GET') {
    respond(200, [
        'ok' => true,
        'product' => 'SafeDeploy',
        'api_version' => '0.1',
    ]);
}

if ($private === '' || !is_readable($configFile)) {
    respond(500, ['ok' => false, 'request_id' => $requestId, 'error' => 'server_not_configured']);
}

foreach (['StateStore.php', 'OidcVerifier.php', 'BundleValidator.php', 'DeployExecutor.php'] as $file) {
    $path = $private . '/src/' . $file;
    if (!is_readable($path)) {
        respond(500, ['ok' => false, 'request_id' => $requestId, 'error' => 'server_component_missing']);
    }
    require_once $path;
}

$config = require $configFile;
if (!is_array($config)) {
    respond(500, ['ok' => false, 'request_id' => $requestId, 'error' => 'config_invalid']);
}

try {
    $db = (array)($config['db'] ?? []);
    $dsn = (string)($db['dsn'] ?? '');
    if ($dsn === '') {
        throw new RuntimeException('db_dsn_missing');
    }
    $pdo = new PDO(
        $dsn,
        (string)($db['user'] ?? ''),
        (string)($db['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $store = new StateStore($pdo);
    $executor = new DeployExecutor($config, $store);

    $token = trim((string)($_SERVER['HTTP_X_SAFEDEPLOY_GITHUB_OIDC'] ?? ''));
    if ($token === '') {
        $store->audit($requestId, $action, 'REJECTED', ['reason' => 'oidc_required']);
        respond(401, ['ok' => false, 'request_id' => $requestId, 'error' => 'oidc_required']);
    }

    $verifier = new OidcVerifier($config, (string)$config['state_dir']);
    $auth = $verifier->verify($token);
    unset($token);

    if ($action === 'deploy.status' && $method === 'GET') {
        $store->audit($requestId, $action, 'PASS', ['repository' => $auth['repository'] ?? '']);
        respond(200, [
            'ok' => true,
            'request_id' => $requestId,
            'api_version' => (string)($config['api_version'] ?? '0.1'),
            'deployments' => $store->listDeployments(),
            'allowed_roots' => array_keys((array)($config['deploy_roots'] ?? [])),
        ]);
    }

    if ($action === 'deploy.inspect' && $method === 'GET') {
        $target = trim((string)($_GET['target'] ?? ''));
        $result = $executor->inspect($target);
        $store->audit($requestId, $action, 'PASS', ['target' => $target, 'exists' => $result['exists']]);
        $result['request_id'] = $requestId;
        respond(200, $result);
    }

    if ($action === 'deploy.stage' && $method === 'POST') {
        $store->consumeReplay($auth['token_fingerprint'], $auth['run_id'], $action);
        $raw = file_get_contents('php://input');
        $bundle = json_decode((string)$raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($bundle)) {
            throw new RuntimeException('bundle_json_invalid');
        }
        $result = $executor->stage($bundle, $auth, $requestId);
        $result['request_id'] = $requestId;
        respond(201, $result);
    }

    if ($action === 'deploy.apply' && $method === 'POST') {
        $store->consumeReplay($auth['token_fingerprint'], $auth['run_id'], $action);
        $jobId = filter_var($_GET['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($jobId === false) {
            throw new RuntimeException('job_id_invalid');
        }
        $result = $executor->apply((int)$jobId, $auth, $requestId);
        $result['request_id'] = $requestId;
        respond(200, $result);
    }

    if ($action === 'deploy.rollback' && $method === 'POST') {
        $store->consumeReplay($auth['token_fingerprint'], $auth['run_id'], $action);
        $deploymentId = filter_var($_GET['deployment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($deploymentId === false) {
            throw new RuntimeException('deployment_id_invalid');
        }
        $result = $executor->rollback((int)$deploymentId, $auth, $requestId);
        $result['request_id'] = $requestId;
        respond(200, $result);
    }

    $store->audit($requestId, $action, 'REJECTED', ['reason' => 'action_or_method_invalid']);
    respond(404, ['ok' => false, 'request_id' => $requestId, 'error' => 'action_or_method_invalid']);
} catch (Throwable $e) {
    try {
        if (isset($store) && $store instanceof StateStore) {
            $store->audit($requestId, $action, 'FAIL', ['reason' => $e->getMessage()]);
        }
    } catch (Throwable $ignored) {}
    $code = match ($e->getMessage()) {
        'replay_detected' => 409,
        'target_invalid',
        'target_root_denied',
        'bundle_format_invalid',
        'source_commit_invalid',
        'manifest_invalid',
        'file_count_invalid',
        'file_path_invalid',
        'file_hash_invalid',
        'file_hash_mismatch',
        'manifest_hash_mismatch',
        'idempotency_key_invalid',
        'bundle_encoding_invalid',
        'bundle_decode_invalid',
        'bundle_gzip_invalid',
        'bundle_too_large',
        'job_id_invalid',
        'deployment_id_invalid' => 400,
        'deployment_not_found',
        'job_not_found' => 404,
        default => str_starts_with($e->getMessage(), 'jwt_') || str_starts_with($e->getMessage(), 'trust_')
            ? 403 : 500,
    };
    respond($code, ['ok' => false, 'request_id' => $requestId, 'error' => $e->getMessage()]);
}
