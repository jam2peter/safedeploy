CREATE TABLE IF NOT EXISTS safedeploy_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  idempotency_key VARCHAR(96) NOT NULL,
  target_rel VARCHAR(190) NOT NULL,
  source_commit CHAR(40) NOT NULL,
  manifest_sha256 CHAR(64) NOT NULL,
  payload_json MEDIUMTEXT NULL,
  status VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_safedeploy_jobs_idempotency (idempotency_key),
  KEY idx_safedeploy_jobs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS safedeploy_deployments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NOT NULL,
  target_rel VARCHAR(190) NOT NULL,
  source_commit CHAR(40) NOT NULL,
  manifest_sha256 CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL,
  backup_path VARCHAR(512) NULL,
  rollback_copy_path VARCHAR(512) NULL,
  actor VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL,
  promoted_at DATETIME NULL,
  rolled_back_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_safedeploy_deployments_job (job_id),
  KEY idx_safedeploy_deployments_target_status (target_rel,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS safedeploy_oidc_replay (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_fingerprint CHAR(64) NOT NULL,
  run_id VARCHAR(64) NOT NULL,
  action_name VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_safedeploy_replay_token (token_fingerprint),
  KEY idx_safedeploy_replay_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS safedeploy_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) NOT NULL,
  action_name VARCHAR(64) NOT NULL,
  result VARCHAR(32) NOT NULL,
  details_json TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_safedeploy_audit_created (created_at),
  KEY idx_safedeploy_audit_action (action_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
