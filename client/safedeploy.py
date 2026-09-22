#!/usr/bin/env python3
"""SafeDeploy GitHub Actions client (stdlib only)."""

from __future__ import annotations

import argparse
import base64
import gzip
import hashlib
import json
import os
import pathlib
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

FORMAT = "SAFEDEPLOY_BUNDLE_V1"
MARKER = ".safedeploy-release.json"
SAFE_PATH = re.compile(r"^[A-Za-z0-9._/-]+$")
TARGET_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}/[a-z0-9][a-z0-9._-]{0,63}$")
MAX_FILES = 2000
MAX_BYTES = 64 * 1024 * 1024


def die(message: str) -> "NoReturn":
    raise SystemExit(f"ERROR: {message}")


def _safe_rel(path: str) -> bool:
    if not path or len(path) > 240 or path.startswith("/") or "\x00" in path:
        return False
    if path == MARKER:
        return False
    parts = path.split("/")
    if any(p in {"", ".", ".."} for p in parts):
        return False
    return SAFE_PATH.fullmatch(path) is not None


def build_bundle(source: str, target: str, source_commit: str) -> dict:
    if not TARGET_RE.fullmatch(target):
        die("target must match <root>/<slug>")

    root = pathlib.Path(source).resolve()
    if not root.is_dir():
        die(f"source directory not found: {root}")

    entries: list[tuple[str, bytes, str]] = []
    total = 0

    for p in sorted(root.rglob("*")):
        if p.is_symlink():
            die(f"symlink not allowed: {p}")
        if not p.is_file():
            continue
        rel = p.relative_to(root).as_posix()
        if not _safe_rel(rel):
            die(f"unsafe/reserved file path: {rel}")
        data = p.read_bytes()
        total += len(data)
        if total > MAX_BYTES:
            die("bundle exceeds 64 MiB")
        sha = hashlib.sha256(data).hexdigest()
        entries.append((rel, data, sha))

    if not entries:
        die("bundle is empty")
    if len(entries) > MAX_FILES:
        die("bundle exceeds file-count limit")

    material = b"".join(
        rel.encode("utf-8") + b"\0" + sha.encode("ascii") + b"\n"
        for rel, _, sha in entries
    )
    manifest = hashlib.sha256(material).hexdigest()
    idem_material = (target + "\0" + source_commit + "\0" + manifest).encode()
    idem = "deploy:" + hashlib.sha256(idem_material).hexdigest()

    files = {}
    for rel, data, sha in entries:
        files[rel] = {
            "sha256": sha,
            "encoding": "gzip+base64",
            "base64": base64.b64encode(gzip.compress(data, 9)).decode("ascii"),
        }

    return {
        "format": FORMAT,
        "source_commit": source_commit,
        "target": target,
        "manifest_sha256": manifest,
        "idempotency_key": idem,
        "files": files,
    }


def _request_json(url: str, *, method: str = "GET", headers: dict | None = None, payload: dict | None = None) -> tuple[int, dict]:
    data = None
    request_headers = {"Accept": "application/json", "User-Agent": "SafeDeploy/0.1"}
    if headers:
        request_headers.update(headers)
    if payload is not None:
        data = json.dumps(payload, separators=(",", ":")).encode()
        request_headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, method=method, headers=request_headers)
    try:
        with urllib.request.urlopen(req, timeout=45) as response:
            raw = response.read().decode("utf-8")
            return response.status, json.loads(raw or "{}")
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            obj = json.loads(raw or "{}")
        except json.JSONDecodeError:
            obj = {"error": "http_error", "body": raw[:500]}
        return exc.code, obj


def get_oidc_token(audience: str) -> str:
    request_url = os.environ.get("ACTIONS_ID_TOKEN_REQUEST_URL", "")
    request_token = os.environ.get("ACTIONS_ID_TOKEN_REQUEST_TOKEN", "")
    if not request_url or not request_token:
        die("GitHub Actions OIDC environment is unavailable; permissions.id-token=write is required")
    sep = "&" if "?" in request_url else "?"
    url = request_url + sep + "audience=" + urllib.parse.quote(audience, safe="")
    status, data = _request_json(
        url,
        headers={"Authorization": f"bearer {request_token}"},
    )
    if status != 200 or not data.get("value"):
        die(f"failed to obtain GitHub OIDC token (HTTP {status})")
    return str(data["value"])


def api_call(endpoint: str, action: str, *, method: str = "GET", audience: str, payload: dict | None = None, query: dict | None = None) -> dict:
    token = get_oidc_token(audience)
    params = {"action": action}
    if query:
        params.update({k: str(v) for k, v in query.items()})
    url = endpoint.rstrip("?") + ("&" if "?" in endpoint else "?") + urllib.parse.urlencode(params)
    status, data = _request_json(
        url,
        method=method,
        headers={"X-SafeDeploy-GitHub-OIDC": token},
        payload=payload,
    )
    token = ""
    if status not in (200, 201):
        reason = data.get("error") or data.get("message") or "unknown"
        die(f"SafeDeploy {action} failed: HTTP {status}: {reason}")
    return data


def readback(url: str) -> tuple[int, str, int]:
    req = urllib.request.Request(url, method="GET", headers={"User-Agent": "SafeDeploy/0.1 readback"})
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            body = response.read()
            return response.status, hashlib.sha256(body).hexdigest(), len(body)
    except urllib.error.HTTPError as exc:
        body = exc.read()
        return exc.code, hashlib.sha256(body).hexdigest(), len(body)
    except urllib.error.URLError:
        return 0, hashlib.sha256(b"").hexdigest(), 0


def write_output(key: str, value: object) -> None:
    path = os.environ.get("GITHUB_OUTPUT")
    if path:
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(f"{key}={value}\n")


def command_deploy(args: argparse.Namespace) -> int:
    endpoint = args.endpoint
    audience = args.audience or endpoint
    commit = os.environ.get("GITHUB_SHA", "")
    if not re.fullmatch(r"[a-fA-F0-9]{40}", commit):
        die("GITHUB_SHA is missing or invalid")

    bundle = build_bundle(args.source, args.target, commit.lower())
    staged = api_call(
        endpoint,
        "deploy.stage",
        method="POST",
        audience=audience,
        payload=bundle,
    )
    job_id = staged.get("job_id")
    if not isinstance(job_id, int) or job_id < 1:
        die("server did not return a valid job_id")

    applied = api_call(
        endpoint,
        "deploy.apply",
        method="POST",
        audience=audience,
        query={"job_id": job_id},
    )
    deployment_id = applied.get("deployment_id")
    if not isinstance(deployment_id, int) or deployment_id < 1:
        die("server did not return a valid deployment_id")

    code, body_sha, body_bytes = readback(args.health_url)
    print(f"SAFEDEPLOY_DEPLOYMENT_ID={deployment_id}")
    print(f"SAFEDEPLOY_MANIFEST_SHA256={bundle['manifest_sha256']}")
    print(f"SAFEDEPLOY_READBACK_HTTP={code}")
    print(f"SAFEDEPLOY_READBACK_SHA256={body_sha}")
    print(f"SAFEDEPLOY_READBACK_BYTES={body_bytes}")

    if code != 200:
        if args.auto_rollback.lower() == "true":
            api_call(
                endpoint,
                "deploy.rollback",
                method="POST",
                audience=audience,
                query={"deployment_id": deployment_id},
            )
            print("SAFEDEPLOY_AUTO_ROLLBACK=PASS")
        die("public HTTPS readback did not return HTTP 200")

    write_output("deployment_id", deployment_id)
    write_output("manifest_sha256", bundle["manifest_sha256"])
    return 0


def command_inspect(args: argparse.Namespace) -> int:
    audience = args.audience or args.endpoint
    data = api_call(
        args.endpoint,
        "deploy.inspect",
        audience=audience,
        query={"target": args.target},
    )
    print(json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


def command_rollback(args: argparse.Namespace) -> int:
    audience = args.audience or args.endpoint
    data = api_call(
        args.endpoint,
        "deploy.rollback",
        method="POST",
        audience=audience,
        query={"deployment_id": args.deployment_id},
    )
    print(json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


def command_doctor(args: argparse.Namespace) -> int:
    url = args.endpoint.rstrip("?") + ("&" if "?" in args.endpoint else "?") + "action=health"
    status, data = _request_json(url)
    if status != 200 or not data.get("ok"):
        die(f"health check failed: HTTP {status}")
    print("SAFEDEPLOY_DOCTOR=PASS")
    print(f"API_VERSION={data.get('api_version', 'UNKNOWN')}")
    return 0


def parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="safedeploy")
    sub = p.add_subparsers(dest="command", required=True)

    d = sub.add_parser("deploy")
    d.add_argument("--endpoint", required=True)
    d.add_argument("--source", required=True)
    d.add_argument("--target", required=True)
    d.add_argument("--health-url", required=True)
    d.add_argument("--audience", default="")
    d.add_argument("--auto-rollback", default="true")
    d.set_defaults(func=command_deploy)

    i = sub.add_parser("inspect")
    i.add_argument("--endpoint", required=True)
    i.add_argument("--target", required=True)
    i.add_argument("--audience", default="")
    i.set_defaults(func=command_inspect)

    r = sub.add_parser("rollback")
    r.add_argument("--endpoint", required=True)
    r.add_argument("--deployment-id", required=True, type=int)
    r.add_argument("--audience", default="")
    r.set_defaults(func=command_rollback)

    doc = sub.add_parser("doctor")
    doc.add_argument("--endpoint", required=True)
    doc.set_defaults(func=command_doctor)
    return p


def main() -> int:
    args = parser().parse_args()
    return int(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
