<?php
declare(strict_types=1);

final class BundleValidator
{
    public const FORMAT = 'SAFEDEPLOY_BUNDLE_V1';
    public const MARKER = '.safedeploy-release.json';

    public function __construct(private array $config) {}

    public function validate(array $bundle, bool $decodeFiles = false): array
    {
        if (($bundle['format'] ?? '') !== self::FORMAT) {
            throw new RuntimeException('bundle_format_invalid');
        }

        $target = trim((string)($bundle['target'] ?? ''));
        $this->resolveTarget($target);

        $commit = strtolower((string)($bundle['source_commit'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$/', $commit)) {
            throw new RuntimeException('source_commit_invalid');
        }

        $manifestExpected = strtolower((string)($bundle['manifest_sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $manifestExpected)) {
            throw new RuntimeException('manifest_invalid');
        }

        $files = $bundle['files'] ?? null;
        $limits = (array)($this->config['limits'] ?? []);
        $maxFiles = max(1, min(10000, (int)($limits['max_files'] ?? 2000)));
        $maxBytes = max(1, (int)($limits['max_bytes'] ?? 67108864));

        if (!is_array($files) || count($files) < 1 || count($files) > $maxFiles) {
            throw new RuntimeException('file_count_invalid');
        }

        $keys = array_keys($files);
        sort($keys, SORT_STRING);
        $manifestMaterial = '';
        $total = 0;
        $decoded = [];

        foreach ($keys as $path) {
            if (!is_string($path) || !$this->safePath($path) || !is_array($files[$path])) {
                throw new RuntimeException('file_path_invalid');
            }
            $sha = strtolower((string)($files[$path]['sha256'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
                throw new RuntimeException('file_hash_invalid');
            }
            $data = $this->decodeItem($files[$path]);
            $total += strlen($data);
            if ($total > $maxBytes) {
                throw new RuntimeException('bundle_too_large');
            }
            if (!hash_equals($sha, hash('sha256', $data))) {
                throw new RuntimeException('file_hash_mismatch');
            }
            $manifestMaterial .= $path . "\0" . $sha . "\n";
            if ($decodeFiles) {
                $decoded[$path] = $data;
            }
        }

        $manifestActual = hash('sha256', $manifestMaterial);
        if (!hash_equals($manifestExpected, $manifestActual)) {
            throw new RuntimeException('manifest_hash_mismatch');
        }

        $idempotencyExpected = 'deploy:' . hash(
            'sha256',
            $target . "\0" . $commit . "\0" . $manifestExpected
        );
        if (!hash_equals($idempotencyExpected, (string)($bundle['idempotency_key'] ?? ''))) {
            throw new RuntimeException('idempotency_key_invalid');
        }

        return [
            'target' => $target,
            'source_commit' => $commit,
            'manifest_sha256' => $manifestExpected,
            'idempotency_key' => $idempotencyExpected,
            'file_count' => count($keys),
            'bytes' => $total,
            'files' => $decoded,
        ];
    }

    public function resolveTarget(string $target): array
    {
        if (!preg_match('#^([a-z0-9][a-z0-9._-]{0,63})/([a-z0-9][a-z0-9._-]{0,63})$#D', $target, $m)) {
            throw new RuntimeException('target_invalid');
        }
        $roots = (array)($this->config['deploy_roots'] ?? []);
        $rootName = $m[1];
        if (!isset($roots[$rootName]) || !is_string($roots[$rootName])) {
            throw new RuntimeException('target_root_denied');
        }
        $root = rtrim($roots[$rootName], '/');
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new RuntimeException('target_root_invalid');
        }
        return [
            'root_name' => $rootName,
            'root' => $root,
            'slug' => $m[2],
            'absolute' => $root . '/' . $m[2],
        ];
    }

    private function safePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 240 || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }
        if ($path === self::MARKER) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return preg_match('#^[A-Za-z0-9._/-]+$#D', $path) === 1;
    }

    private function decodeItem(array $item): string
    {
        if (($item['encoding'] ?? '') !== 'gzip+base64') {
            throw new RuntimeException('bundle_encoding_invalid');
        }
        $packed = base64_decode((string)($item['base64'] ?? ''), true);
        if ($packed === false || !function_exists('gzdecode')) {
            throw new RuntimeException('bundle_decode_invalid');
        }
        $data = gzdecode($packed);
        if ($data === false) {
            throw new RuntimeException('bundle_gzip_invalid');
        }
        return $data;
    }
}
