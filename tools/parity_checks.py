"""Deterministic parity-gate check suite.

Pure functions only: no I/O, no randomness, no external state. The clock is
injected through the ``now`` parameter, so results are fully deterministic.
"""

from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass
from datetime import datetime, timezone

from jsonschema import Draft202012Validator, FormatChecker

TTL_SECONDS = 86_400  # artifact TTL: 24 h
_HEX64 = re.compile(r"[0-9a-f]{64}\Z")


@dataclass(frozen=True)
class CheckResult:
    ok: bool
    code: str
    detail: str


def canonical_domains_json(domains: object) -> bytes:
    """Canonical UTF-8 JSON of ``domains``: recursively sorted keys, compact separators."""
    return json.dumps(
        domains,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=False,
        allow_nan=False,
    ).encode("utf-8")


def content_hash(domains: object) -> str:
    """SHA-256 hex digest over the canonical JSON of ``domains``."""
    return hashlib.sha256(canonical_domains_json(domains)).hexdigest()


def validate_checksum(artifact: object) -> CheckResult:
    """Recompute sha256 over canonical domains JSON; compare to meta.content_hash.

    Codes: OK, MALFORMED_ARTIFACT, MISSING_META, MISSING_CONTENT_HASH,
    MALFORMED_CONTENT_HASH, MALFORMED_DOMAINS, CHECKSUM_MISMATCH.
    """
    if not isinstance(artifact, dict):
        return CheckResult(False, "MALFORMED_ARTIFACT", "artifact is not a JSON object")
    meta = artifact.get("meta")
    domains = artifact.get("domains")
    if not isinstance(meta, dict):
        return CheckResult(False, "MISSING_META", "meta block absent")
    if "content_hash" not in meta:
        return CheckResult(False, "MISSING_CONTENT_HASH", "meta.content_hash absent")
    expected = meta["content_hash"]
    if not isinstance(expected, str) or not _HEX64.match(expected):
        return CheckResult(False, "MALFORMED_CONTENT_HASH", "meta.content_hash is not a sha256 hex digest")
    if not isinstance(domains, dict):
        return CheckResult(False, "MALFORMED_DOMAINS", "domains is not an object")
    actual = content_hash(domains)
    if actual != expected:
        return CheckResult(False, "CHECKSUM_MISMATCH", f"expected {expected}, computed {actual}")
    return CheckResult(True, "OK", "checksum matches meta.content_hash")


def validate_schema(artifact: object, schema: object) -> CheckResult:
    """JSON Schema draft 2020-12 conformance of ``artifact`` against ``schema``.

    Codes: OK, MALFORMED_SCHEMA, SCHEMA_VIOLATION.
    """
    if not isinstance(schema, dict):
        return CheckResult(False, "MALFORMED_SCHEMA", "schema is not a JSON object")
    validator = Draft202012Validator(schema, format_checker=FormatChecker())
    errors = list(validator.iter_errors(artifact))
    if not errors:
        return CheckResult(True, "OK", "schema conformant")
    first = errors[0]
    path = "/".join(str(p) for p in first.path) or "(root)"
    return CheckResult(False, "SCHEMA_VIOLATION", f"{path}: {first.message}")


def _parse_instant(value: object) -> datetime | None:
    if not isinstance(value, str):
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None


def _as_utc(instant: datetime) -> datetime:
    return instant if instant.tzinfo is not None else instant.replace(tzinfo=timezone.utc)


def validate_invariants(
    artifact: object,
    now: datetime,
    ttl_seconds: int = TTL_SECONDS,
) -> CheckResult:
    """Semantic invariants.

    - meta.domains == set(domains keys)
    - every domain/fixture_key: status non-null integer, error str-or-null, data_strings non-empty list on 200
    - captured_at present, ISO 8601, within ``ttl_seconds`` of ``now``

    Codes: OK, MALFORMED_ARTIFACT, INVARIANT_VIOLATION.
    """
    if not isinstance(artifact, dict):
        return CheckResult(False, "MALFORMED_ARTIFACT", "artifact is not a JSON object")
    meta = artifact.get("meta")
    domains = artifact.get("domains")
    problems: list[str] = []

    if not isinstance(meta, dict):
        problems.append("meta block absent")
    else:
        declared = meta.get("domains")
        if not isinstance(declared, list) or not all(isinstance(d, str) for d in declared):
            problems.append("meta.domains is not a list of strings")
        elif len(declared) != len(set(declared)):
            problems.append("meta.domains contains duplicates")

    if not isinstance(domains, dict):
        problems.append("domains is not an object")
    else:
        if isinstance(meta, dict) and isinstance(meta.get("domains"), list):
            if set(meta["domains"]) != set(domains):
                problems.append("meta.domains does not match domains keys")
        for name in sorted(domains):
            entry = domains[name]
            if not isinstance(entry, dict):
                problems.append(f"{name}: domain is not an object")
                continue
            for fk in sorted(entry):
                sig = entry[fk]
                if not isinstance(sig, dict):
                    problems.append(f"{name}/{fk}: signature is not an object")
                    continue
                status = sig.get("status")
                if (
                    status is None
                    or not isinstance(status, int)
                    or isinstance(status, bool)
                ):
                    problems.append(f"{name}/{fk}: status must be a non-null integer")
                error = sig.get("error")
                if status is None and error is None:
                    problems.append(f"{name}/{fk}: null status requires a non-null error")
                if error is not None and not isinstance(error, str):
                    problems.append(f"{name}/{fk}: error must be a string or null")
                data_strings = sig.get("data_strings")
                if status == 200 and (not isinstance(data_strings, list) or not data_strings):
                    problems.append(f"{name}/{fk}: 200 response needs a non-empty body (data_strings)")

    if isinstance(meta, dict):
        captured_at = _parse_instant(meta.get("captured_at"))
        if captured_at is None:
            problems.append("captured_at missing or not ISO 8601")
        else:
            now_utc = _as_utc(now)
            delta = abs((now_utc - _as_utc(captured_at)).total_seconds())
            if delta > ttl_seconds:
                problems.append(
                    f"captured_at outside TTL ({delta:.0f}s > {ttl_seconds}s)"
                )

    if problems:
        return CheckResult(False, "INVARIANT_VIOLATION", "; ".join(problems))
    return CheckResult(True, "OK", "all semantic invariants hold")


def run_all(
    artifact: object,
    schema: object,
    now: datetime,
    ttl_seconds: int = TTL_SECONDS,
) -> tuple[dict[str, CheckResult], bool]:
    """Run checksum, schema, and invariant checks. Returns (results, all_ok)."""
    results = {
        "checksum": validate_checksum(artifact),
        "schema": validate_schema(artifact, schema),
        "invariants": validate_invariants(artifact, now, ttl_seconds),
    }
    return results, all(result.ok for result in results.values())
