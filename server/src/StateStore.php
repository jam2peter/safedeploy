<?php
declare(strict_types=1);

final class StateStore
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function audit(string $requestId, string $action, string $result, array $details = []): void
    {
        $safe = [];
        foreach ($details as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safe[(string)$key] = $value;
            }
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO safedeploy_audit
             (request_id,action_name,result,details_json,created_at)
             VALUES (?,?,?,?,?)"
        );
        $stmt->execute([
            $requestId,
            $action,
            $result,
            json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function consumeReplay(string $fingerprint, string $runId, string $action): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO safedeploy_oidc_replay
                 (token_fingerprint,run_id,action_name,created_at)
                 VALUES (?,?,?,?)"
            );
            $stmt->execute([$fingerprint, $runId, $action, gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('replay_detected');
            }
            throw $e;
        }
    }

    public function getOrCreateJob(array $bundle): array
    {
        $key = (string)$bundle['idempotency_key'];
        $existing = $this->findJobByKey($key);
        if ($existing) {
            return $existing;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO safedeploy_jobs
             (idempotency_key,target_rel,source_commit,manifest_sha256,payload_json,status,created_at)
             VALUES (?,?,?,?,?,'PENDING',?)"
        );

        try {
            $stmt->execute([
                $key,
                $bundle['target'],
                $bundle['source_commit'],
                $bundle['manifest_sha256'],
                json_encode($bundle, JSON_UNESCAPED_SLASHES),
                gmdate('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            $existing = $this->findJobByKey($key);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }

        return $this->getJob((int)$this->pdo->lastInsertId());
    }

    public function findJobByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM safedeploy_jobs WHERE idempotency_key=? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function getJob(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM safedeploy_jobs WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('job_not_found');
        }
        return $row;
    }

    public function markJobDone(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE safedeploy_jobs
             SET status='DONE', payload_json=NULL, finished_at=?
             WHERE id=? AND status='PENDING'"
        );
        $stmt->execute([gmdate('Y-m-d H:i:s'), $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('job_state_race');
        }
    }

    public function findDeploymentByJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM safedeploy_deployments WHERE job_id=? LIMIT 1");
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function createDeployment(
        int $jobId,
        string $target,
        string $commit,
        string $manifest,
        ?string $backupPath,
        string $actor
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO safedeploy_deployments
             (job_id,target_rel,source_commit,manifest_sha256,status,backup_path,actor,created_at,promoted_at)
             VALUES (?,?,?,?,'ACTIVE',?,?,?,?)"
        );
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([$jobId, $target, $commit, $manifest, $backupPath, $actor, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getDeployment(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM safedeploy_deployments WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('deployment_not_found');
        }
        return $row;
    }

    public function markRolledBack(int $id, string $rollbackCopy): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE safedeploy_deployments
             SET status='ROLLED_BACK', rollback_copy_path=?, rolled_back_at=?
             WHERE id=? AND status='ACTIVE'"
        );
        $stmt->execute([$rollbackCopy, gmdate('Y-m-d H:i:s'), $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('rollback_state_race');
        }
    }

    public function listDeployments(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->query(
            "SELECT id,job_id,target_rel,source_commit,manifest_sha256,status,actor,created_at,promoted_at,rolled_back_at
             FROM safedeploy_deployments
             ORDER BY id DESC
             LIMIT " . $limit
        );
        return $stmt->fetchAll() ?: [];
    }
}
