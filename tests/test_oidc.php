<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/src/OidcVerifier.php';

function b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function makeJwt(OpenSSLAsymmetricKey $privateKey, array $claims, string $kid = 'test-key'): string {
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid];
    $h = b64url(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = b64url(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $input = $h . '.' . $p;
    $sig = '';
    if (!openssl_sign($input, $sig, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('sign_failed');
    }
    return $input . '.' . b64url($sig);
}

function expectFailure(callable $fn, string $expected): void {
    try {
        $fn();
        throw new RuntimeException('expected_failure_missing:' . $expected);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'expected_failure_missing:' . $expected) {
            throw $e;
        }
        if ($e->getMessage() !== $expected) {
            throw new RuntimeException('expected=' . $expected . ' got=' . $e->getMessage());
        }
    }
}

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
]);
if (!$key instanceof OpenSSLAsymmetricKey) {
    throw new RuntimeException('keygen_failed');
}
$details = openssl_pkey_get_details($key);
if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
    throw new RuntimeException('rsa_details_missing');
}

$jwks = [
    'keys' => [[
        'kty' => 'RSA',
        'kid' => 'test-key',
        'n' => b64url($details['rsa']['n']),
        'e' => b64url($details['rsa']['e']),
    ]],
];

$now = time();
$base = [
    'iss' => 'https://token.actions.githubusercontent.com',
    'aud' => 'https://deploy.example.test/safedeploy/',
    'repository' => 'example/project',
    'repository_id' => '123456',
    'repository_owner' => 'example',
    'ref' => 'refs/heads/main',
    'event_name' => 'push',
    'job_workflow_ref' => 'example/project/.github/workflows/deploy.yml@refs/heads/main',
    'actor' => 'octocat',
    'run_id' => '987654321',
    'jti' => 'fixture-token-1',
    'iat' => $now - 5,
    'nbf' => $now - 5,
    'exp' => $now + 300,
];

$config = [
    'issuer' => 'https://token.actions.githubusercontent.com',
    'audience' => 'https://deploy.example.test/safedeploy/',
    'trust' => [
        'repository' => 'example/project',
        'repository_id' => '123456',
        'owner' => 'example',
        'refs' => ['refs/heads/main'],
        'events' => ['push', 'workflow_dispatch'],
        'job_workflow_refs' => ['example/project/.github/workflows/deploy.yml@refs/heads/main'],
    ],
];

$tmp = sys_get_temp_dir() . '/safedeploy-oidc-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$verifier = new OidcVerifier($config, $tmp);

$ctx = $verifier->verify(makeJwt($key, $base), $jwks);
if (($ctx['repository'] ?? '') !== 'example/project') {
    throw new RuntimeException('valid_token_rejected');
}

$badAud = $base;
$badAud['aud'] = 'https://wrong.example/';
expectFailure(fn() => $verifier->verify(makeJwt($key, $badAud), $jwks), 'jwt_audience_invalid');

$badRepo = $base;
$badRepo['repository'] = 'other/project';
expectFailure(fn() => $verifier->verify(makeJwt($key, $badRepo), $jwks), 'trust_repository_denied');

$badRef = $base;
$badRef['ref'] = 'refs/heads/other';
expectFailure(fn() => $verifier->verify(makeJwt($key, $badRef), $jwks), 'trust_ref_denied');

$expired = $base;
$expired['exp'] = $now - 3600;
expectFailure(fn() => $verifier->verify(makeJwt($key, $expired), $jwks), 'jwt_expired');

echo "OIDC_RS256_SYNTHETIC_TESTS=PASS\n";
