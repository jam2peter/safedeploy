<?php
declare(strict_types=1);

final class DeployExecutor
{
    private BundleValidator $validator;
    private string $stateDir;

    public function __construct(
        private array $config,
        private StateStore $store
    ) {
        $this->validator = new BundleValidator($config);
        $this->stateDir = rtrim((string)($config['state_dir'] ?? ''), '/');
        if ($this->stateDir === '' || !str_starts_with($this->stateDir, '/')) {
            throw new RuntimeException('state_dir_invalid');
        }
        $this->mkdirPrivate($this->stateDir);
    }

    public function stage(array $bundle, array $auth, string $requestId): array
    {
        $meta = $this->validator->validate($bundle, false);
        $job = $this->store->getOrCreateJob($bundle);
        $this->store->audit($requestId, 'deploy.stage', 'PASS', [
            'job_id' => (int)$job['id'],
            'target' => $meta['target'],
            'manifest_sha256' => $meta['manifest_sha256'],
            'file_count' => $meta['file_count'],
            'bytes' => $meta['bytes'],
            'repository' => $auth['repository'] ?? '',
        ]);
        return [
            'ok' => true,
            'job_id' => (int)$job['id'],
            'status' => (string)$job['status'],
            'target' => $meta['target'],
            'manifest_sha256' => $meta['manifest_sha256'],
        ];
    }

    public function apply(int $jobId, array $auth, string $requestId): array
    {
        $existing = $this->store->findDeploymentByJob($jobId);
        if ($existing && (string)$existing['status'] === 'ACTIVE') {
            $this->store->audit($requestId, 'deploy.apply', 'PASS', [
                'idempotent' => true,
                'deployment_id' => (int)$existing['id'],
                'job_id' => $jobId,
            ]);
            return [
                'ok' => true,
                'idempotent' => true,
                'deployment_id' => (int)$existing['id'],
                'target' => (string)$existing['target_rel'],
                'status' => 'ACTIVE',
                'source_commit' => (string)$existing['source_commit'],
                'manifest_sha256' => (string)$existing['manifest_sha256'],
            ];
        }

        $job = $this->store->getJob($jobId);
        if ((string)$job['status'] !== 'PENDING' || !is_string($job['payload_json']) || $job['payload_json'] === '') {
            throw new RuntimeException('job_not_pending');
        }

        $bundle = json_decode($job['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($bundle)) {
            throw new RuntimeException('job_payload_invalid');
        }
        $meta = $this->validator->validate($bundle, true);
        $resolved = $this->validator->resolveTarget($meta['target']);
        $target = $resolved['absolute'];
        $root = $resolved['root'];

        $this->ensureRoot($root);
        if (is_link($target) || (file_exists($target) && !is_dir($target))) {
            throw new RuntimeException('target_type_invalid');
        }

        $stagingRoot = $this->stateDir . '/staging';
        $this->mkdirPrivate($stagingRoot);
        $staging = $stagingRoot . '/job-' . $jobId . '-' . bin2hex(random_bytes(6));
        if (!mkdir($staging, 0700, true)) {
            throw new RuntimeException('staging_create_failed');
        }

        $backupPath = null;
        try {
            foreach ($meta['files'] as $path => $data) {
                $destination = $staging . '/' . $path;
                $dir = dirname($destination);
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('staging_dir_failed');
                }
                if (file_put_contents($destination, $data, LOCK_EX) === false) {
                    throw new RuntimeException('staging_write_failed');
                }
                chmod($destination, 0644);
            }

            $marker = [
                'format' => 'SAFEDEPLOY_RELEASE_MARKER_V1',
                'job_id' => $jobId,
                'target' => $meta['target'],
                'source_commit' => $meta['source_commit'],
                'manifest_sha256' => $meta['manifest_sha256'],
                'promoted_at_utc' => gmdate('c'),
            ];
            if (file_put_contents(
                $staging . '/' . BundleValidator::MARKER,
                json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
                LOCK_EX
            ) === false) {
                throw new RuntimeException('marker_write_failed');
            }
            chmod($staging . '/' . BundleValidator::MARKER, 0644);

            $this->assertSameFilesystem($stagingRoot, $root);

            $backupRoot = $this->stateDir . '/backups/deploy/' .
                str_replace('/', '__', $meta['target']);
            $this->mkdirPrivate($backupRoot);

            if (is_dir($target)) {
                $backupPath = $backupRoot . '/' . gmdate('Ymd\THis\Z') . '-job-' . $jobId;
                if (!rename($target, $backupPath)) {
                    throw new RuntimeException('backup_rename_failed');
                }
            }

            if (!rename($staging, $target)) {
                if ($backupPath !== null && is_dir($backupPath) && !file_exists($target)) {
                    @rename($backupPath, $target);
                }
                throw new RuntimeException('promote_failed');
            }
            chmod($target, 0755);

            try {
                $deploymentId = $this->store->createDeployment(
                    $jobId,
                    $meta['target'],
                    $meta['source_commit'],
                    $meta['manifest_sha256'],
                    $backupPath,
                    (string)($auth['actor'] ?? 'github-actions')
                );
                $this->store->markJobDone($jobId);
            } catch (Throwable $dbError) {
                $failedRoot = $this->stateDir . '/failed';
                $this->mkdirPrivate($failedRoot);
                $failed = $failedRoot . '/job-' . $jobId . '-' . gmdate('Ymd\THis\Z');
                @rename($target, $failed);
                if ($backupPath !== null && is_dir($backupPath)) {
                    @rename($backupPath, $target);
                }
                throw $dbError;
            }

            $this->store->audit($requestId, 'deploy.apply', 'PASS', [
                'deployment_id' => $deploymentId,
                'job_id' => $jobId,
                'target' => $meta['target'],
                'manifest_sha256' => $meta['manifest_sha256'],
                'file_count' => $meta['file_count'],
                'bytes' => $meta['bytes'],
            ]);

            return [
                'ok' => true,
                'idempotent' => false,
                'deployment_id' => $deploymentId,
                'release_job_id' => $jobId,
                'target' => $meta['target'],
                'status' => 'ACTIVE',
                'source_commit' => $meta['source_commit'],
                'manifest_sha256' => $meta['manifest_sha256'],
            ];
        } catch (Throwable $e) {
            if (is_dir($staging)) {
                $this->removeTree($staging, $stagingRoot);
            }
            throw $e;
        }
    }

    public function inspect(string $target): array
    {
        $resolved = $this->validator->resolveTarget($target);
        $absolute = $resolved['absolute'];
        $marker = null;
        $markerPath = $absolute . '/' . BundleValidator::MARKER;
        if (is_file($markerPath)) {
            $decoded = json_decode((string)file_get_contents($markerPath), true);
            if (is_array($decoded)) {
                $marker = [
                    'job_id' => isset($decoded['job_id']) ? (int)$decoded['job_id'] : null,
                    'source_commit' => (string)($decoded['source_commit'] ?? ''),
                    'manifest_sha256' => (string)($decoded['manifest_sha256'] ?? ''),
                ];
            }
        }
        return [
            'ok' => true,
            'target' => $target,
            'exists' => is_dir($absolute),
            'marker' => $marker,
        ];
    }

    public function rollback(int $deploymentId, array $auth, string $requestId): array
    {
        $deployment = $this->store->getDeployment($deploymentId);
        if ((string)$deployment['status'] === 'ROLLED_BACK') {
            return [
                'ok' => true,
                'deployment_id' => $deploymentId,
                'status' => 'ROLLED_BACK',
                'idempotent' => true,
            ];
        }
        if ((string)$deployment['status'] !== 'ACTIVE') {
            throw new RuntimeException('deployment_not_active');
        }

        $targetRel = (string)$deployment['target_rel'];
        $resolved = $this->validator->resolveTarget($targetRel);
        $target = $resolved['absolute'];
        $markerPath = $target . '/' . BundleValidator::MARKER;
        if (!is_file($markerPath)) {
            throw new RuntimeException('release_marker_missing');
        }
        $marker = json_decode((string)file_get_contents($markerPath), true);
        if (
            !is_array($marker) ||
            (int)($marker['job_id'] ?? 0) !== (int)$deployment['job_id'] ||
            !hash_equals((string)$deployment['manifest_sha256'], (string)($marker['manifest_sha256'] ?? ''))
        ) {
            throw new RuntimeException('release_marker_mismatch');
        }

        $rollbackRoot = $this->stateDir . '/backups/rollback/' . str_replace('/', '__', $targetRel);
        $this->mkdirPrivate($rollbackRoot);
        $rollbackCopy = $rollbackRoot . '/' . gmdate('Ymd\THis\Z') . '-deployment-' . $deploymentId;

        if (!rename($target, $rollbackCopy)) {
            throw new RuntimeException('current_release_backup_failed');
        }

        $backupPath = is_string($deployment['backup_path'] ?? null) ? $deployment['backup_path'] : '';
        if ($backupPath !== '') {
            if (!is_dir($backupPath) || !rename($backupPath, $target)) {
                @rename($rollbackCopy, $target);
                throw new RuntimeException('previous_release_restore_failed');
            }
        }

        try {
            $this->store->markRolledBack($deploymentId, $rollbackCopy);
        } catch (Throwable $e) {
            if ($backupPath !== '' && is_dir($target) && !is_dir($backupPath)) {
                @rename($target, $backupPath);
            }
            @rename($rollbackCopy, $target);
            throw $e;
        }

        $this->store->audit($requestId, 'deploy.rollback', 'PASS', [
            'deployment_id' => $deploymentId,
            'target' => $targetRel,
            'restored_previous' => $backupPath !== '',
            'actor' => $auth['actor'] ?? '',
        ]);

        return [
            'ok' => true,
            'deployment_id' => $deploymentId,
            'target' => $targetRel,
            'status' => 'ROLLED_BACK',
            'restored_previous' => $backupPath !== '',
            'idempotent' => false,
        ];
    }

    private function ensureRoot(string $root): void
    {
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new RuntimeException('deploy_root_create_failed');
        }
        if (is_link($root)) {
            throw new RuntimeException('deploy_root_symlink_denied');
        }
    }

    private function mkdirPrivate(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('private_dir_create_failed');
        }
        chmod($dir, 0700);
    }

    private function assertSameFilesystem(string $a, string $b): void
    {
        $sa = @stat($a);
        $sb = @stat($b);
        if (!is_array($sa) || !is_array($sb) || ($sa['dev'] ?? null) !== ($sb['dev'] ?? null)) {
            throw new RuntimeException('cross_filesystem_promotion_not_supported');
        }
    }

    private function removeTree(string $path, string $requiredPrefix): void
    {
        $normalized = rtrim($path, '/');
        $prefix = rtrim($requiredPrefix, '/') . '/';
        if (!str_starts_with($normalized . '/', $prefix)) {
            throw new RuntimeException('unsafe_remove_boundary');
        }
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException('remove_failed');
            }
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            throw new RuntimeException('scan_failed');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $this->removeTree($path . '/' . $item, $requiredPrefix);
        }
        if (!@rmdir($path)) {
            throw new RuntimeException('rmdir_failed');
        }
    }
}
