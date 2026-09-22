# Architecture

SafeDeploy treats deployment as a constrained domain operation, not as a remote
shell.

## Trust path

```text
GitHub repository
  -> GitHub Actions
  -> ephemeral OIDC token
  -> SafeDeploy HTTPS endpoint
  -> RS256/JWKS verification
  -> audience + repository/workflow/ref/event policy
  -> replay gate
  -> bundle validation
  -> private staging
  -> checkpoint
  -> promotion
  -> release marker
  -> HTTPS readback
```

The server revalidates the complete bundle. Client-side validation is never the
security boundary.

## Bundle identity

Each file is represented by its relative path and SHA-256. The canonical
manifest is:

```text
path NUL sha256 LF
```

for every file sorted lexicographically by path. The release manifest is the
SHA-256 of that byte stream.

The idempotency key is derived from:

```text
target NUL source_commit NUL manifest_sha256
```

## Promotion

The new bundle is materialized in a private staging directory. If the target
already exists, it is moved to a private checkpoint. The staged directory is
then renamed into the configured target.

V0.1 requires staging/checkpoints and the deployment root to be on the same
filesystem, because the promotion model depends on rename semantics.

## Rollback

Every release carries `.safedeploy-release.json`. Rollback verifies the active
marker against the stored job and manifest before moving anything.

If the marker is missing or different, rollback fails closed. This avoids
overwriting a target changed by another mechanism.

## State

MySQL/MariaDB stores:

- pending/completed jobs;
- deployment records;
- consumed OIDC replay identities;
- sanitized audit events.

After a job is promoted, its encoded bundle payload is removed from the
database. Deployment metadata is retained.

## Non-goals

SafeDeploy is not a shell, SQL console, hosting panel, Kubernetes replacement or
general configuration-management system.
