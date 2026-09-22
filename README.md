# SafeDeploy

**SafeDeploy** is a narrow, auditable deployment primitive for GitHub Actions.

It lets a workflow deploy a static/PHP bundle to a PHP hosting account using an
**ephemeral GitHub Actions OIDC identity** instead of a long-lived FTP, SFTP,
SSH or server API token stored in GitHub.

> Status: `v0.1-beta` candidate.

## What it does

```text
GitHub repository
      |
      v
GitHub Actions
      |
      | ephemeral OIDC token
      v
SafeDeploy API
      |
      +--> GitHub OIDC signature + audience + trust policy
      +--> replay protection
      +--> target allowlist
      +--> closed file inventory
      +--> SHA-256 per file + manifest
      +--> private staging
      +--> checkpoint previous release
      +--> controlled promotion
      +--> release marker
      +--> audit trail
      |
      v
Public application
      |
      +--> HTTPS readback
      +--> explicit/automatic rollback on failure
```

## Why

Small web deployments often end up storing a broad server credential in CI.
SafeDeploy takes a different approach: the host trusts a specific GitHub
repository/workflow/ref and accepts only a small deployment protocol.

It deliberately does **not** provide:

- arbitrary shell;
- arbitrary SQL;
- arbitrary filesystem access;
- WordPress administration;
- DNS/e-mail administration;
- generic hosting-panel automation.

## Quick workflow

```yaml
name: Deploy

on:
  workflow_dispatch:
  push:
    branches: [main]

permissions:
  id-token: write
  contents: read

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: jam2peter/safedeploy@v0
        with:
          endpoint: ${{ vars.SAFEDEPLOY_ENDPOINT }}
          source: dist
          target: apps/my-app
          health-url: https://example.com/apps/my-app/
```

The normal path requires no long-lived server credential in GitHub.

## Host requirements

- PHP 8.1+
- OpenSSL extension
- PDO MySQL
- MySQL or MariaDB
- HTTPS
- private writable state directory
- configured deployment roots

See [docs/INSTALL.md](docs/INSTALL.md).

## Security model

Writes require GitHub Actions OIDC. The server validates:

- issuer;
- RS256 signature against GitHub JWKS;
- audience;
- token time bounds;
- repository;
- optional repository ID;
- repository owner;
- ref;
- event name;
- optional `job_workflow_ref`;
- replay identity.

Bundles are revalidated on the server. Client-side checks are only an early
failure mechanism.

See:

- [Architecture](docs/ARCHITECTURE.md)
- [Security contract](docs/SECURITY.md)
- [Threat model](docs/THREAT-MODEL.md)

## Commands

The included Python client supports:

```text
deploy
inspect
rollback
doctor
```

The GitHub Action uses `deploy`.

## Release marker

Every active release receives:

```text
.safedeploy-release.json
```

Rollback is refused if the active marker no longer matches the recorded
deployment. This prevents SafeDeploy from blindly overwriting an externally
modified target.

## Development

```bash
python3 -m unittest discover -s tests -p 'test_*.py' -v
php tests/test_oidc.php
php tests/test_executor.php
find server -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Origin

SafeDeploy is a clean-room product extraction of deployment mechanisms first
validated in the JamPeter environment. The public code is environment
independent and contains no private JamPeter host configuration or credentials.

## License

MIT
