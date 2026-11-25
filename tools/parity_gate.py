#!/usr/bin/env python3
"""P5 parity gate: validate capture artifact (meta block + checksum + schema)
then diff against the Python baseline.

Exit codes (ADR docs/decisions/p5-parity-gate.md):
  0 = pass, no differences
  1 = pass but schema/checksum mismatch (manual review required)
  2 = schema violation or I/O failure
  3 = baseline diff detected

Usage:
    python tools/parity_gate.py [candidate] [--baseline baseline.json] [--schema schema.json]

candidate defaults to laravel/storage/app/parity/laravel.json
baseline defaults to tests/parity/baseline/python.json
schema defaults to laravel/resources/schemas/parity-capture.schema.json

Hermetic: reads only committed repo files, no network, deterministic output.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
SCHEMA_PATH = REPO / "laravel" / "resources" / "schemas" / "parity-capture.schema.json"
DEFAULT_CANDIDATE = REPO / "laravel" / "storage" / "app" / "parity" / "laravel.json"
DEFAULT_BASELINE = REPO / "tests" / "parity" / "baseline" / "python.json"

TTL_SECONDS = 24 * 3600


def _report(code: int, msg: str) -> int:
    print(f"PARITY-GATE: {msg}")
    return code


def canonical(obj) -> str:
    return json.dumps(obj, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def _sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def semantic_invariants(cand: dict) -> list[str]:
    """Return list of violated invariants (empty = ok)."""
    problems: list[str] = []
    domains = cand.get("domains", {})
    if not isinstance(domains, dict) or not domains:
        problems.append("domains empty or missing")
        return problems
    # Domain count is validated by meta.domains == domains.keys() check below;
    # no hardcoded count since F-22..F-32 expanded the domain set beyond 9.
    for name, sig in domains.items():
        if not isinstance(sig, dict):
            problems.append(f"{name}: signature not an object")
            continue
        # Domain value is fixture => signature; check every fixture.
        for fixture, fsig in sig.items():
            if not isinstance(fsig, dict):
                problems.append(f"{name}/{fixture}: signature not an object")
                continue
            status = fsig.get("status")
            if status is None and not fsig.get("error"):
                problems.append(f"{name}/{fixture}: status null without error")
            # Body expected only on successful (2xx) responses. Redirects (3xx)
            # carry a Location, 404s/errors carry no content — no body required.
            if isinstance(status, int) and 200 <= status < 300:
                body = fsig.get("json") or fsig.get("data_strings") or fsig.get("rows_sample")
                if body is None:
                    body = fsig.get("body")  # legacy raw capture uses a string body
                if not body:
                    problems.append(f"{name}/{fixture}: no body (json/data_strings/rows_sample/body missing)")
    meta = cand.get("meta")
    if isinstance(meta, dict):
        if not meta.get("source"):
            problems.append("meta.source missing")
        if not meta.get("generator"):
            problems.append("meta.generator missing")
        if not meta.get("gate"):
            problems.append("meta.gate missing")
        roles = meta.get("roles")
        if not isinstance(roles, list) or not roles:
            problems.append("meta.roles missing or empty")
        declared = meta.get("domains")
        if isinstance(declared, list) and set(declared) != set(domains.keys()):
            problems.append("meta.domains != domains keys")
        captured_at = meta.get("captured_at")
        if captured_at:
            try:
                ts = datetime.fromisoformat(captured_at.replace("Z", "+00:00"))
                age = (datetime.now(timezone.utc) - ts).total_seconds()
                if abs(age) > TTL_SECONDS:
                    problems.append(f"captured_at outside TTL ({abs(age):.0f}s old)")
            except ValueError:
                problems.append("captured_at not ISO 8601")
    return problems


def schema_validate(cand: dict, schema: dict) -> list[str]:
    try:
        import jsonschema  # type: ignore[import-not-found]
    except ImportError:  # pragma: no cover - gate env must carry jsonschema
        return ["jsonschema not installed — cannot validate schema"]
    validator = jsonschema.Draft202012Validator(schema)
    errors = sorted(validator.iter_errors(cand), key=lambda e: list(e.path))
    return [
        f"{'/'.join(str(p) for p in e.path) or '<root>'}: {e.message}"
        for e in errors
    ]


def checksum_ok(cand: dict) -> bool:
    meta = cand.get("meta", {})
    expected = meta.get("content_hash")
    if not expected:
        return True  # no checksum declared → skip
    domains = cand.get("domains", {})
    actual = _sha256_hex(canonical(domains).encode("utf-8"))
    return actual == expected


def main() -> int:
    ap = argparse.ArgumentParser(description="P5 parity gate")
    ap.add_argument("candidate", type=Path, nargs="?", default=DEFAULT_CANDIDATE)
    ap.add_argument("--baseline", type=Path, default=DEFAULT_BASELINE)
    ap.add_argument("--schema", type=Path, default=SCHEMA_PATH)
    args = ap.parse_args()

    try:
        cand = load_json(args.candidate)
    except FileNotFoundError:
        return _report(2, f"candidate not found: {args.candidate}")
    except (json.JSONDecodeError, OSError) as exc:
        return _report(2, f"candidate unreadable ({exc})")

    # ── load meta block ──────────────────────────────────────────────
    meta = cand.get("meta") if isinstance(cand.get("meta"), dict) else {}
    schema_version = cand.get("schema_version")

    # ── deterministic checks ─────────────────────────────────────────
    if schema_version:
        try:
            schema = load_json(args.schema)
        except (FileNotFoundError, json.JSONDecodeError, OSError) as exc:
            return _report(2, f"schema unreadable ({exc})")
        schema_errors = schema_validate(cand, schema)
        if schema_errors:
            return _report(2, "schema violation:\n  " + "\n  ".join(schema_errors))

        if not checksum_ok(cand):
            return _report(
                1,
                "checksum mismatch (meta.content_hash != sha256(canonical domains JSON))",
            )

        invariant_errors = semantic_invariants(cand)
        if invariant_errors:
            return _report(
                1, "semantic invariant violations:\n  " + "\n  ".join(invariant_errors)
            )
    else:
        # legacy artifact (no schema_version): invariants best-effort only
        invariant_errors = semantic_invariants(cand)
        if invariant_errors:
            print(
                f"PARITY-GATE: warning: legacy artifact — invariant issues: "
                f"{', '.join(invariant_errors)}"
            )

    # ── baseline comparison ──────────────────────────────────────────
    try:
        baseline = load_json(args.baseline)
    except FileNotFoundError:
        return _report(2, f"baseline not found: {args.baseline}")
    except (json.JSONDecodeError, OSError) as exc:
        return _report(2, f"baseline unreadable ({exc})")

    # version skew: candidate major must not exceed baseline major (schema v1)
    base_version = baseline.get("schema_version") if isinstance(baseline, dict) else None
    if schema_version and base_version:
        cand_major = int(str(schema_version).split(".")[0])
        base_major = int(str(base_version).split(".")[0])
        if cand_major > base_major:
            return _report(2, f"version skew: candidate {schema_version} > baseline {base_version}")

    sys.path.insert(0, str(REPO / "tools"))
    from compare_parity import compare  # noqa: E402

    diffs = compare(args.baseline, args.candidate)
    if diffs:
        print(f"PARITY-GATE: {len(diffs)} baseline difference(s):")
        for d in diffs[:20]:
            print(f"  [{d['kind']}] {d['path']}")
        return _report(3, "baseline diff detected")

    if schema_version and (not checksum_ok(cand) or semantic_invariants(cand)):
        return _report(
            1,
            "passed comparison but schema/checksum/invariant issues remain (manual review)",
        )

    print("PARITY-GATE: candidate matches baseline — all checks pass")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
