<?php
declare(strict_types=1);

final class OidcVerifier
{
    public function __construct(
        private array $config,
        private string $stateDir
    ) {}

    public function verify(string $jwt, ?array $jwksOverride = null): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('jwt_format_invalid');
        }
        [$h64, $p64, $s64] = $parts;
        $header = $this->decodeJsonPart($h64);
        $claims = $this->decodeJsonPart($p64);

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('jwt_alg_invalid');
        }
        $kid = (string)($header['kid'] ?? '');
        if ($kid === '') {
            throw new RuntimeException('jwt_kid_missing');
        }

        $jwks = $jwksOverride ?? $this->loadJwks();
        $key = null;
        foreach (($jwks['keys'] ?? []) as $candidate) {
            if (is_array($candidate) && (string)($candidate['kid'] ?? '') === $kid) {
                $key = $candidate;
                break;
            }
        }
        if (!$key) {
            throw new RuntimeException('jwks_key_not_found');
        }

        $publicKey = $this->jwkToPublicKey($key);
        $signature = $this->base64UrlDecode($s64);
        $verified = openssl_verify($h64 . '.' . $p64, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('jwt_signature_invalid');
        }

        $now = time();
        $skew = 60;
        $issuer = (string)($this->config['issuer'] ?? 'https://token.actions.githubusercontent.com');
        $audience = (string)($this->config['audience'] ?? '');

        if ((string)($claims['iss'] ?? '') !== $issuer) {
            throw new RuntimeException('jwt_issuer_invalid');
        }
        if (!$this->audienceMatches($claims['aud'] ?? null, $audience)) {
            throw new RuntimeException('jwt_audience_invalid');
        }
        if (!isset($claims['exp']) || (int)$claims['exp'] < $now - $skew) {
            throw new RuntimeException('jwt_expired');
        }
        if (isset($claims['nbf']) && (int)$claims['nbf'] > $now + $skew) {
            throw new RuntimeException('jwt_not_yet_valid');
        }
        if (isset($claims['iat']) && (int)$claims['iat'] > $now + $skew) {
            throw new RuntimeException('jwt_iat_invalid');
        }

        $trust = (array)($this->config['trust'] ?? []);
        $this->exactClaim($claims, 'repository', (string)($trust['repository'] ?? ''));
        $this->exactClaim($claims, 'repository_owner', (string)($trust['owner'] ?? ''));

        $repositoryId = trim((string)($trust['repository_id'] ?? ''));
        if ($repositoryId !== '') {
            $this->exactClaim($claims, 'repository_id', $repositoryId);
        }

        $refs = array_values(array_filter((array)($trust['refs'] ?? []), 'is_string'));
        if ($refs && !in_array((string)($claims['ref'] ?? ''), $refs, true)) {
            throw new RuntimeException('trust_ref_denied');
        }

        $events = array_values(array_filter((array)($trust['events'] ?? []), 'is_string'));
        if ($events && !in_array((string)($claims['event_name'] ?? ''), $events, true)) {
            throw new RuntimeException('trust_event_denied');
        }

        $workflowRefs = array_values(array_filter((array)($trust['job_workflow_refs'] ?? []), 'is_string'));
        if ($workflowRefs && !in_array((string)($claims['job_workflow_ref'] ?? ''), $workflowRefs, true)) {
            throw new RuntimeException('trust_workflow_denied');
        }

        $runId = trim((string)($claims['run_id'] ?? ''));
        if ($runId === '' || !preg_match('/^[0-9]{1,30}$/', $runId)) {
            throw new RuntimeException('run_id_missing');
        }

        $jti = trim((string)($claims['jti'] ?? ''));
        $fingerprintMaterial = $jti !== '' ? $jti : $s64;
        $fingerprint = hash('sha256', $fingerprintMaterial);

        return [
            'actor' => (string)($claims['actor'] ?? 'github-actions'),
            'repository' => (string)($claims['repository'] ?? ''),
            'run_id' => $runId,
            'token_fingerprint' => $fingerprint,
            'ref' => (string)($claims['ref'] ?? ''),
            'event_name' => (string)($claims['event_name'] ?? ''),
            'job_workflow_ref' => (string)($claims['job_workflow_ref'] ?? ''),
        ];
    }

    private function exactClaim(array $claims, string $name, string $expected): void
    {
        if ($expected === '' || (string)($claims[$name] ?? '') !== $expected) {
            throw new RuntimeException('trust_' . $name . '_denied');
        }
    }

    private function audienceMatches(mixed $claim, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }
        if (is_string($claim)) {
            return hash_equals($expected, $claim);
        }
        if (is_array($claim)) {
            foreach ($claim as $value) {
                if (is_string($value) && hash_equals($expected, $value)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function decodeJsonPart(string $part): array
    {
        $raw = $this->base64UrlDecode($part);
        $value = json_decode($raw, true);
        if (!is_array($value)) {
            throw new RuntimeException('jwt_json_invalid');
        }
        return $value;
    }

    private function base64UrlDecode(string $value): string
    {
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        if ($raw === false) {
            throw new RuntimeException('base64url_invalid');
        }
        return $raw;
    }

    private function loadJwks(): array
    {
        $cacheDir = rtrim($this->stateDir, '/') . '/cache';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
            throw new RuntimeException('jwks_cache_dir_failed');
        }
        @chmod($cacheDir, 0700);
        $cache = $cacheDir . '/github-jwks.json';
        $ttl = max(60, (int)($this->config['jwks_cache_seconds'] ?? 3600));

        if (is_file($cache) && filemtime($cache) !== false && (time() - (int)filemtime($cache)) < $ttl) {
            $decoded = json_decode((string)file_get_contents($cache), true);
            if (is_array($decoded) && isset($decoded['keys'])) {
                return $decoded;
            }
        }

        $url = (string)($this->config['jwks_url'] ?? '');
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('jwks_url_invalid');
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'header' => "User-Agent: SafeDeploy/0.1\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('jwks_fetch_failed');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['keys'] ?? null)) {
            throw new RuntimeException('jwks_payload_invalid');
        }
        file_put_contents($cache . '.tmp', $raw, LOCK_EX);
        @chmod($cache . '.tmp', 0600);
        rename($cache . '.tmp', $cache);
        return $decoded;
    }

    private function jwkToPublicKey(array $jwk): OpenSSLAsymmetricKey
    {
        if (!empty($jwk['x5c'][0]) && is_string($jwk['x5c'][0])) {
            $cert = "-----BEGIN CERTIFICATE-----\n" .
                chunk_split($jwk['x5c'][0], 64, "\n") .
                "-----END CERTIFICATE-----\n";
            $key = openssl_pkey_get_public($cert);
            if ($key instanceof OpenSSLAsymmetricKey) {
                return $key;
            }
        }

        if (($jwk['kty'] ?? '') !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
            throw new RuntimeException('jwk_rsa_invalid');
        }
        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);
        $rsa = $this->asn1Sequence($this->asn1Integer($modulus) . $this->asn1Integer($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $bitString = "\x03" . $this->asn1Length(strlen($rsa) + 1) . "\x00" . $rsa;
        $spki = $this->asn1Sequence($algorithm . $bitString);
        $pem = "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($spki), 64, "\n") .
            "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('jwk_public_key_invalid');
        }
        return $key;
    }

    private function asn1Integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $this->asn1Length(strlen($bytes)) . $bytes;
    }

    private function asn1Sequence(string $bytes): string
    {
        return "\x30" . $this->asn1Length(strlen($bytes)) . $bytes;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $out = '';
        while ($length > 0) {
            $out = chr($length & 0xff) . $out;
            $length >>= 8;
        }
        return chr(0x80 | strlen($out)) . $out;
    }
}
