#!/usr/bin/env python3
"""One command that runs every automated parity/schema gate.

    python tools/run_gates.py          # full gate set (needs data/*.db)
    python tools/run_gates.py --fast   # skip the real-DB audit harnesses

Steps (order matters: suites first, byte-level checks after):
  1. pytest app suite             (includes tests/test_schema_gate.py:
                                   fixture fidelity, dataset subset-of-
                                   production, every query fn on production
                                   shape)
  2. pytest parity harness        (regenerates the baseline, then it is
                                   diffed in step 5)
  3. Laravel feature suite        (includes ProductionSchemaTest.php)
  4. parity vectors --check       (cross-app primitive vectors vs Python)
  5. committed-baseline diff      (the parity capture must not change — a
                                   diff means an intentional contract change
                                   that must be reviewed + committed)
  6. PRD scope gate             (PRD structure invariants; always runs,
                                 including hosted CI without data/*.db)
  7. production query audit       (Python + PHP harnesses on COPIES of the
                                 real data/*.db, every query branch;
                                 auto-skipped with a warning when data/
                                 is absent, e.g. hosted CI)

Exit 0 only when everything ran (or explicitly skipped) clean.
"""
from __future__ import annotations

import argparse
import os
import platform
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
LARAVEL = REPO / "laravel"
BASELINE = REPO / "tests" / "parity" / "baseline" / "python.json"
PHP = "php" if platform.system() != "Windows" else "php"


def run(cmd: list[str], cwd: Path | None = None, env: dict | None = None) -> int:
    merged = dict(os.environ)
    merged.setdefault("PYTHONIOENCODING", "utf-8")
    if env:
        merged.update(env)
    printable = " ".join(str(c) for c in cmd)
    print(f"\n==> {printable}" + (f"   (cwd: {cwd.name})" if cwd else ""))
    result = subprocess.run(cmd, cwd=cwd, env=merged)
    if result.returncode != 0:
        print(f"FAILED (exit {result.returncode}): {printable}")
    return result.returncode


def php_binary() -> str | None:
    """php on PATH — plus the pdo_sqlite/sqlite3 -d flags this build needs."""
    from shutil import which

    if which("php") is None:
        return None
    return "php"


def main() -> int:
    parser = argparse.ArgumentParser(description="Run all parity/schema gates.")
    parser.add_argument("--fast", action="store_true",
                        help="skip the real-DB production audit harnesses")
    args = parser.parse_args()

    failures: list[str] = []
    skips: list[str] = []
    passed: list[str] = []

    def check(name: str, code: int) -> None:
        if code != 0:
            failures.append(name)
        else:
            passed.append(name)

    # Snapshot the baseline BEFORE the parity run (the harness regenerates it
    # in place in step 2); step 5 fails if the fresh capture differs.
    baseline_before = BASELINE.read_bytes() if BASELINE.exists() else None

    # ── 1. Python app suite (schema gates included) ────────────────────────
    check("pytest app suite",
          run([sys.executable, "-m", "pytest", "tests/", "-q",
               "--ignore=tests/parity"], cwd=REPO))

    # ── 2. Parity harness (regenerates the baseline) ───────────────────────
    check("pytest parity harness",
          run([sys.executable, "-m", "pytest", "tests/parity", "-q"], cwd=REPO))

    # ── 3. Laravel suite (ProductionSchemaTest included) ───────────────────
    php = php_binary()
    php_flags = ["-d", "extension=pdo_sqlite", "-d", "extension=sqlite3"]
    if php is None:
        skips.append("laravel suite (php not on PATH)")
    else:
        check("laravel suite",
              run([php, *php_flags, "vendor/bin/phpunit"], cwd=LARAVEL))

    # ── 4. Cross-app primitive vectors ─────────────────────────────────────
    check("parity vectors check",
          run([sys.executable, "tools/gen_parity_vectors.py", "--check"], cwd=REPO))

    # ── 5. Committed baseline must equal the fresh capture ─────────────────
    if baseline_before is None:
        failures.append("baseline diff (tests/parity/baseline/python.json missing)")
    elif BASELINE.read_bytes() != baseline_before:
        failures.append(
            "baseline diff (parity capture changed — review + `make test-parity` "
            "+ commit the new tests/parity/baseline/python.json intentionally)"
        )

    # ── 6. PRD scope gate (doc gate; runs in CI even without data/*.db) ────
    check("PRD scope gate",
          run([sys.executable, "tools/check_prd_scope.py"], cwd=REPO))

    # ── 7. Production query audits on real DB copies ───────────────────────
    if args.fast:
        skips.append("production audits (--fast)")
    else:
        has_real_dbs = any((REPO / "data").glob("*.db"))
        if not has_real_dbs:
            skips.append("production audits (no data/*.db in this checkout)")
        else:
            check("python production audit",
                  run([sys.executable, "tools/audit_production_queries.py"], cwd=REPO))
    if php is None or args.fast:
        skips.append("php production audit" + (" (--fast)" if args.fast else " (php not on PATH)"))
    else:
        check("php production audit",
              run([php, *php_flags, "tools/audit_production_queries.php"], cwd=LARAVEL))

    # ── Summary ─────────────────────────────────────────────────────────────
    print("\n" + "=" * 64)
    for p in passed:
        print(f"PASSED: {p}")
    for s in skips:
        print(f"SKIPPED: {s}")
    if failures:
        print(f"GATES FAILED: {', '.join(failures)}")
        return 1
    print("ALL GATES PASSED" + (" (with skips)" if skips else ""))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
