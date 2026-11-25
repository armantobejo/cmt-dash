#!/usr/bin/env python3
"""P3 exit gate: byte-compare SQLite DBs produced by the Python and PHP syncs.

docs/decisions/dbf-reading.md §5.1: run both syncs over the same DBF tree,
then compare the resulting {company}.db files — equal table sets, equal row
counts, equal per-table content hashes.

Usage:
    python tools/compare_sync_dbs.py <python_data_dir> <php_data_dir> [--dbf-dir DBF_DIR]

Exit 0 = parity, 1 = mismatch, 2 = usage error.
"""
from __future__ import annotations

import hashlib
import sqlite3
import sys
from pathlib import Path

# App-internal tables whose content legitimately differs between runs
# (sessions) or that only exist in one output (cache/jobs are Laravel-side).
SKIP_TABLES = {"sqlite_sequence"}
DIFFERENT_CONTENT_OK = {"sessions"}


def table_names(conn: sqlite3.Connection) -> set[str]:
    return {
        r[0]
        for r in conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        ).fetchall()
    }


def table_hash(conn: sqlite3.Connection, table: str) -> tuple[int, str]:
    """Row count + order-independent content hash of *table*."""
    count = conn.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
    hasher = hashlib.sha256()
    cols = [r[1] for r in conn.execute(f'PRAGMA table_info("{table}")')]
    # Normalize REALs so 1.0 == 1 (SQLite may store either spelling); order
    # rows by PK-ish first column for stable hashing.
    rows = conn.execute(f'SELECT * FROM "{table}"').fetchall()
    col_idx = {c: i for i, c in enumerate(cols)}

    def norm(v):
        if isinstance(v, float) and v.is_integer():
            return int(v)
        return v

    for row in sorted(rows, key=lambda r: str(norm(r[0]))):
        hasher.update(repr(tuple(norm(v) for v in row)).encode())
    return count, hasher.hexdigest()


def compare(db_a: Path, db_b: Path) -> list[str]:
    """Return a list of mismatch descriptions (empty = parity)."""
    problems: list[str] = []
    a = sqlite3.connect(f"file:{db_a}?mode=ro", uri=True)
    b = sqlite3.connect(f"file:{db_b}?mode=ro", uri=True)
    try:
        ta, tb = table_names(a) - SKIP_TABLES, table_names(b) - SKIP_TABLES
        for t in sorted(ta - tb):
            problems.append(f"  table only in {db_a.name}: {t}")
        for t in sorted(tb - ta):
            problems.append(f"  table only in {db_b.name}: {t}")
        for t in sorted(ta & tb):
            (ca, ha), (cb, hb) = table_hash(a, t), table_hash(b, t)
            if ca != cb:
                problems.append(f"  {t}: row count {ca} vs {cb}")
            elif ha != hb and t not in DIFFERENT_CONTENT_OK:
                problems.append(f"  {t}: content hash differs ({ca} rows)")
        return problems
    finally:
        a.close()
        b.close()


def main() -> int:
    if len(sys.argv) < 3:
        print(__doc__)
        return 2
    dir_a, dir_b = Path(sys.argv[1]), Path(sys.argv[2])
    dbs = sorted(set(p.name for p in dir_a.glob("*.db")) | set(p.name for p in dir_b.glob("*.db")))
    if not dbs:
        print("no *.db files found")
        return 1

    failures = 0
    for name in dbs:
        a, b = dir_a / name, dir_b / name
        if not a.exists() or not b.exists():
            print(f"{name}: MISSING on one side (python={a.exists()}, php={b.exists()})")
            failures += 1
            continue
        problems = compare(a, b)
        if problems:
            failures += 1
            print(f"{name}: MISMATCH")
            for p in problems:
                print(p)
        else:
            print(f"{name}: OK")

    print()
    print("P3 BYTE-COMPARISON GATE:", "FAIL" if failures else "PASS")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
