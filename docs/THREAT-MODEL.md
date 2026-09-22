# Threat model

## Protected assets

- deployment roots;
- previous releases/checkpoints;
- database state;
- GitHub-to-host trust relationship;
- application integrity.

## Primary threats and controls

| Threat | Control |
|---|---|
| Stolen long-lived CI server password | Normal path uses ephemeral GitHub OIDC |
| Valid token from wrong repository | Repository/repository_id trust policy |
| Valid token from wrong branch | Ref allowlist |
| Valid token from unexpected workflow | Optional job_workflow_ref allowlist |
| Token replay | OIDC fingerprint replay table |
| Path traversal | Strict logical target and relative-file validation |
| Symlink escape | Symlinks rejected client-side; server never receives symlink objects |
| Bundle modified in transit/client | Server recomputes file SHA-256 + manifest |
| Duplicate workflow retry | Deterministic idempotency key |
| Bad release | Checkpoint + explicit/automatic rollback |
| Rollback over external modification | Active release marker must match deployment |
| Arbitrary remote administration | No shell, SQL or free filesystem operations |

## Residual risks

- A compromised trusted GitHub repository/workflow can deploy content within its
  authorized target.
- A compromised hosting account or database administrator can bypass SafeDeploy.
- The public readback is HTTP-level validation; application-specific semantic
  health checks remain the consumer's responsibility.
- V0.1 depends on GitHub's OIDC issuer/JWKS availability for new authenticated
  operations.
