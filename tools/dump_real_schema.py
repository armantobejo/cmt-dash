#!/usr/bin/env python3
"""Snapshot data/*.db schemas into tests/parity/fixtures/real_schema.json.

The parity dataset (tests/parity/parity_dataset.py) and the Laravel P2 test
seed are built to match this shape, and the schema gates
(tests/test_schema_gate.py, laravel/tests/Feature/ProductionSchemaTest.php)
assert the fixture stays true to the real company DBs.

The sync owns these schemas: they come from the DBF field metadata of the
FoxPro source (docs/decisions/dbf-reading.md). Never hand-edit a synced DB —
if production schemas legitimately change (new FoxPro fields), re-run this
tool and reconcile the dataset/query layers, then check the diff carefully.

Run:  python tools/dump_real_schema.py
"""
from __future__ import annotations

import glob
import json
import os
import sqlite3
import sys
from collections import OrderedDict
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
FIXTURE = REPO / "tests" / "parity" / "fixtures" / "real_schema.json"


def dump(data_dir: Path) -> OrderedDict:
    out: OrderedDict = OrderedDict()
    for db in sorted(data_dir.glob("*.db")):
        company = db.stem.upper()
        conn = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
        try:
            tables: OrderedDict = OrderedDict()
            rows = conn.execute(
                "SELECT name FROM sqlite_master WHERE type='table' "
                "AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )
            for (table,) in rows:
                cols: OrderedDict = OrderedDict()
                for col in conn.execute(f'PRAGMA table_info("{table}")'):
                    cols[col[1]] = col[2]
                tables[table] = cols
            out[company] = tables
        finally:
            conn.close()
    return out


def main() -> int:
    data_dir = REPO / "data"
    if not data_dir.is_dir():
        print(f"error: {data_dir} not found", file=sys.stderr)
        return 1
    schema = dump(data_dir)
    if not schema:
        print(f"error: no .db files under {data_dir}", file=sys.stderr)
        return 1

    FIXTURE.parent.mkdir(parents=True, exist_ok=True)
    with open(FIXTURE, "w", encoding="utf-8", newline="\n") as f:
        json.dump(schema, f, indent=1)
        f.write("\n")

    for company, tables in schema.items():
        inv = tables.get("INVOICE", {})
        flag = "yes" if "CONTAINERN" in inv else "no"
        print(f"{company}: {len(tables)} tables, INVOICE.CONTAINERN: {flag}")
    print(f"-> {FIXTURE.relative_to(REPO)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
