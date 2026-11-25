"""Baseline comparison engine.

Loads current artifact + baseline artifact, runs the deterministic check
suite on both, diffs check results and domain signatures, emits a
machine-readable JSON report.

Pure where possible: clock injected via ``now``; I/O only in ``load_artifact``
and ``main``.
"""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

# Ensure tools/ is on path for parity_checks import
TOOLS_DIR = Path(__file__).resolve().parent
if str(TOOLS_DIR) not in sys.path:
    sys.path.insert(0, str(TOOLS_DIR))

from parity_checks import (  # noqa: E402
    TTL_SECONDS,
    CheckResult,
    canonical_domains_json,
    content_hash,
    run_all,
    validate_checksum,
    validate_schema,
    validate_invariants,
)

CHECK_NAMES = ("checksum", "schema", "invariants")


def _major(version: Any) -> int | None:
    if not isinstance(version, str):
        return None
    try:
        return int(version.split(".")[0])
    except (ValueError, IndexError):
        return None


def load_artifact(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def meta_block(artifact: dict) -> dict:
    return artifact.get("meta", {}) if isinstance(artifact, dict) else {}


def schema_version_of(artifact: dict) -> str | None:
    return artifact.get("schema_version") if isinstance(artifact, dict) else None


def check_version_skew(current: dict, baseline: dict) -> tuple[bool, str | None, str | None]:
    """
    Returns (ok, code, detail).
    ok=True when no fatal skew; ok=False when candidate major > baseline major (VERSION_SKEW_FATAL).
    Legacy artifacts (no schema_version) -> ok=True, code=None, detail="legacy artifact (no schema_version)".
    """
    cur_ver = schema_version_of(current)
    base_ver = schema_version_of(baseline)
    cur_major = _major(cur_ver)
    base_major = _major(base_ver)

    if cur_major is None or base_major is None:
        return True, None, "legacy artifact (no schema_version) -- skew check skipped"
    if cur_major > base_major:
        return False, "VERSION_SKEW_FATAL", f"candidate major {cur_major} > baseline major {base_major}"
    return True, None, None


def diff_check_results(
    current_results: dict[str, CheckResult],
    baseline_results: dict[str, CheckResult],
) -> tuple[list[dict], list[dict]]:
    """
    Diffs per-check results.
    Returns (check_diffs, regressions_from_checks).
    check_diffs: list of {"check": name, "current": dict, "baseline": dict, "changed": bool}
    regressions: list of {"code": str, "path": str, "detail": str, "current": ..., "baseline": ...}
    """
    check_diffs = []
    regressions = []
    for name in CHECK_NAMES:
        cur = current_results.get(name)
        base = baseline_results.get(name)
        cur_d = asdict(cur) if cur else None
        base_d = asdict(base) if base else None
        changed = cur_d != base_d
        check_diffs.append(
            {"check": name, "current": cur_d, "baseline": base_d, "changed": changed}
        )
        if changed and cur and not cur.ok:
            regressions.append(
                {
                    "code": cur.code,
                    "path": f"checks.{name}",
                    "detail": cur.detail,
                    "current": cur_d,
                    "baseline": base_d,
                }
            )
    return check_diffs, regressions


def _short(v: Any, n: int = 90) -> str:
    if isinstance(v, str):
        s = v
    else:
        s = json.dumps(v, ensure_ascii=False, separators=(",", ":"))
    return s if len(s) <= n else s[: n - 3] + "..."


def _diff_objects(
    x: Any,
    y: Any,
    path: str,
    out: list[dict],
) -> None:
    """Recursive diff, appending diff entries to out with actionable codes."""
    if type(x) is not type(y):
        out.append(
            {
                "code": "TYPE_CHANGE",
                "path": path,
                "current": _short(x),
                "baseline": _short(y),
                "detail": f"type changed from {type(y).__name__} to {type(x).__name__}",
            }
        )
        return
    if isinstance(x, dict):
        for k in sorted(set(x) | set(y)):
            kp = f"{path}/{k}"
            if k not in x:
                out.append(
                    {
                        "code": "FIELD_REMOVED",
                        "path": kp,
                        "current": None,
                        "baseline": _short(y[k]),
                        "detail": f"field '{k}' removed",
                    }
                )
            elif k not in y:
                out.append(
                    {
                        "code": "FIELD_ADDED",
                        "path": kp,
                        "current": _short(x[k]),
                        "baseline": None,
                        "detail": f"field '{k}' added",
                    }
                )
            else:
                _diff_objects(x[k], y[k], kp, out)
    elif isinstance(x, list):
        if len(x) != len(y):
            out.append(
                {
                    "code": "LIST_LENGTH",
                    "path": path,
                    "current": len(x),
                    "baseline": len(y),
                    "detail": f"list length changed from {len(y)} to {len(x)}",
                }
            )
            return
        for i, (xi, yi) in enumerate(zip(x, y)):
            _diff_objects(xi, yi, f"{path}[{i}]", out)
    elif x != y:
        out.append(
            {
                "code": "VALUE_MISMATCH",
                "path": path,
                "current": _short(x),
                "baseline": _short(y),
                "detail": "value mismatch",
            }
        )


def diff_domains(current: dict, baseline: dict) -> tuple[dict, list[dict]]:
    """
    Structural diff of domains.
    Returns (domains_diff, regressions_from_domains).
    domains_diff: {"added": [...], "removed": [...], "changed": [{"domain": name, "diffs": [...]}]}
    regressions: list of diff entries with actionable codes.
    """
    cur_domains = current.get("domains", {}) if isinstance(current, dict) else {}
    base_domains = baseline.get("domains", {}) if isinstance(baseline, dict) else {}

    added = [d for d in cur_domains if d not in base_domains]
    removed = [d for d in base_domains if d not in cur_domains]
    common = set(cur_domains) & set(base_domains)

    changed = []
    all_domain_diffs = []
    for d in sorted(common):
        diffs = []
        _diff_objects(cur_domains[d], base_domains[d], f"domains/{d}", diffs)
        if diffs:
            changed.append({"domain": d, "diffs": diffs})
            all_domain_diffs.extend(diffs)

    regressions = []
    for d in added:
        regressions.append(
            {
                "code": "DOMAIN_ADDED",
                "path": f"domains/{d}",
                "detail": f"domain '{d}' present in current but not in baseline",
            }
        )
    for d in removed:
        regressions.append(
            {
                "code": "DOMAIN_REMOVED",
                "path": f"domains/{d}",
                "detail": f"domain '{d}' present in baseline but not in current",
            }
        )
    regressions.extend(all_domain_diffs)

    return (
        {"added": added, "removed": removed, "changed": changed},
        regressions,
    )


def compare(
    current: dict,
    baseline: dict,
    schema: dict | None = None,
    now: datetime | None = None,
    ttl_seconds: int = TTL_SECONDS,
) -> dict:
    """
    Full comparison: version skew + check diff + domain diff.
    Returns machine-readable report dict.
    """
    now = now or datetime.now(timezone.utc)

    # Version skew
    skew_ok, skew_code, skew_detail = check_version_skew(current, baseline)
    version_skew = {"ok": skew_ok, "code": skew_code, "detail": skew_detail}

    # Meta blocks
    cur_meta = meta_block(current)
    base_meta = meta_block(baseline)
    meta = {
        "current": {
            k: cur_meta.get(k)
            for k in ("source", "generator", "checklist", "gate", "roles", "domains", "captured_at", "content_hash")
        },
        "baseline": {
            k: base_meta.get(k)
            for k in ("source", "generator", "checklist", "gate", "roles", "domains", "captured_at", "content_hash")
        },
    }

    # Schema versions
    schema_version = {
        "current": schema_version_of(current),
        "baseline": schema_version_of(baseline),
    }

    # Run deterministic checks on both
    cur_results, cur_all_ok = run_all(current, schema or {}, now, ttl_seconds)
    base_results, base_all_ok = run_all(baseline, schema or {}, now, ttl_seconds)

    # Diff check results
    check_diffs, check_regressions = diff_check_results(cur_results, base_results)
    checks = {name: check_diffs[i] for i, name in enumerate(CHECK_NAMES)}

    # Diff domains
    domains_diff, domain_regressions = diff_domains(current, baseline)

    # Combine regressions
    regressions = check_regressions + domain_regressions
    if not skew_ok:
        regressions.append(
            {
                "code": "VERSION_SKEW_FATAL",
                "path": "schema_version",
                "detail": skew_detail,
            }
        )

    diff_empty = len(regressions) == 0

    summary = {
        "check_diffs": sum(1 for c in check_diffs if c["changed"]),
        "domain_diffs": len(domains_diff["added"])
        + len(domains_diff["removed"])
        + len(domains_diff["changed"]),
        "version_skew": not skew_ok,
        "total_regressions": len(regressions),
    }

    report = {
        "engine": "baseline_compare",
        "schema_version": schema_version,
        "version_skew": version_skew,
        "meta": meta,
        "checks": checks,
        "domains": domains_diff,
        "regressions": regressions,
        "diff_empty": diff_empty,
        "summary": summary,
    }
    return report


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Baseline comparison engine -- JSON report")
    ap.add_argument("current", type=Path, help="Current artifact JSON (candidate)")
    ap.add_argument("--baseline", type=Path, default=Path(TOOLS_DIR).parents[1] / "tests" / "parity" / "baseline" / "python.json", help="Baseline artifact JSON")
    ap.add_argument("--schema", type=Path, default=Path(TOOLS_DIR).parents[1] / "laravel" / "resources" / "schemas" / "parity-capture.schema.json", help="JSON Schema for validation")
    ap.add_argument("--now", type=str, help="ISO8601 time for determinism (default: now)")
    ap.add_argument("--ttl", type=int, default=TTL_SECONDS, help="TTL seconds for captured_at check")
    args = ap.parse_args(argv)

    try:
        current = load_artifact(args.current)
    except (FileNotFoundError, json.JSONDecodeError, OSError) as exc:
        print(f"PARITY: current unreadable ({exc})", file=sys.stderr)
        return 2
    try:
        baseline = load_artifact(args.baseline)
    except (FileNotFoundError, json.JSONDecodeError, OSError) as exc:
        print(f"PARITY: baseline unreadable ({exc})", file=sys.stderr)
        return 2
    try:
        schema = json.loads(args.schema.read_text(encoding="utf-8"))
    except (FileNotFoundError, json.JSONDecodeError, OSError) as exc:
        print(f"PARITY: schema unreadable ({exc})", file=sys.stderr)
        return 2

    now = datetime.fromisoformat(args.now.replace("Z", "+00:00")) if args.now else datetime.now(timezone.utc)

    report = compare(current, baseline, schema, now, args.ttl)
    json.dump(report, sys.stdout, ensure_ascii=False, indent=2)
    print()

    return 0 if report["diff_empty"] else 1


if __name__ == "__main__":
    raise SystemExit(main())