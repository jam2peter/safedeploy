# Installation

SafeDeploy V0.1 targets PHP 8.1+, PDO MySQL and OpenSSL.

## 1. Create database

Create an empty MySQL/MariaDB database and a user restricted to that database.

## 2. Prepare private configuration

Copy:

```text
server/config.example.php
```

to a location outside the webroot, edit it and protect it as mode `0600` where
your hosting environment supports Unix permissions.

Important settings:

- `audience`: exact public SafeDeploy endpoint;
- `trust.repository`;
- `trust.repository_id` (recommended);
- `trust.owner`;
- allowed `refs`;
- allowed `events`;
- optional `job_workflow_refs`;
- database DSN/user/password;
- `state_dir`;
- `deploy_roots`.

Example target mapping:

```php
'deploy_roots' => [
    'apps' => '/home/account/public_html/apps',
],
```

Then `apps/demo` can deploy only to
`/home/account/public_html/apps/demo`.

## 3. Install

From a checkout of SafeDeploy:

```bash
php server/install.php \
  --public-dir=/home/account/public_html/safedeploy \
  --private-dir=/home/account/safedeploy-private \
  --config=/home/account/safedeploy-config.php
```

The installer:

- copies server classes to the private directory;
- copies the private config;
- creates the state directory;
- applies the MySQL migration;
- installs only the public API front controller into the public endpoint.

By default the front controller discovers the private directory as:

```text
<parent-of-document-root>/safedeploy-private
```

If your hosting layout differs, set the `SAFEDEPLOY_PRIVATE` environment
variable in the web/PHP runtime.

## 4. Verify public health

```bash
python3 client/safedeploy.py doctor \
  --endpoint=https://deploy.example.com/safedeploy/
```

Expected:

```text
SAFEDEPLOY_DOCTOR=PASS
API_VERSION=0.1
```

## 5. Configure consumer workflow

Add `permissions.id-token=write` and the SafeDeploy action shown in the main
README.

The endpoint should normally be stored as a GitHub Actions variable, not a
secret. It is public by design.

## Filesystem requirement

V0.1 uses directory rename for promotion/rollback. The configured `state_dir`
and deployment roots must be on the same filesystem.

## Updating

Until v1.0, treat upgrades as explicit maintenance operations: back up the
private SafeDeploy state/config, install the new version and run the test suite
before promoting it to a critical host.
