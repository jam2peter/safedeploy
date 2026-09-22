# Security

## Security contract

Normal deployment writes require a GitHub Actions OIDC token. No long-lived
server token is required in the consumer repository.

The host validates the token's:

- GitHub Actions issuer;
- RS256 signature using GitHub's JWKS;
- exact configured audience;
- expiration and not-before times;
- repository;
- repository ID when configured;
- owner;
- Git ref;
- event name;
- optional `job_workflow_ref`.

Write requests also consume a replay fingerprint tied to the OIDC identity.

## Authorization

OIDC authentication never implies arbitrary host access.

Targets must match exactly:

```text
<configured-root>/<slug>
```

For example, if only `apps` is configured, `apps/demo` may be valid while
`public_html/demo`, `../demo`, absolute paths and unknown roots are rejected.

## Bundle rules

SafeDeploy rejects:

- symbolic links;
- absolute paths;
- path traversal;
- null bytes;
- empty path segments;
- reserved release marker injection;
- invalid gzip/base64 encoding;
- per-file SHA-256 mismatches;
- manifest mismatches;
- excessive file count;
- excessive total size.

## Logs

Audit records may contain repository identity, workflow/run identity, target,
manifest, action and result. They must never include raw OIDC tokens, database
passwords or file bodies.

## Recovery

V0.1 does not include a general static recovery token in the normal API. Host
recovery remains a local administrative responsibility.

## Reporting

Do not open a public issue containing credentials or exploit material. For a
security-sensitive report, use the private contact channel listed on the
maintainer's GitHub profile until a dedicated security advisory process is
enabled.
