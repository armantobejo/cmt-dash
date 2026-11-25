#!/usr/bin/env python3
"""Inventory the real DBF tree: which tables exist, where, how big, with what fields.

    python tools/dbf_inventory.py --json docs/report-dbf-coverage/inventory.json
    python tools/dbf_inventory.py --company CMT --fields AR AP ARS SOA

This is the evidence engine behind docs/report-dbf-coverage.md. The coverage matrix
must not assert what is in the DBF tree; it reads it. Reads are header-only
(dbfread load=False), so record counts do not stream the data.

Reported per company: every *.dbf under DBF_DIR/<company>/{DATA,INIT}, its record count,
and its field list. Also derives the union of table names, which are common to all
companies, and which tables are empty in every company (vestigial tables — a report
cannot be built on them).

Exit 0 on success; exit 1 if DBF_DIR is missing or unreadable.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
DEFAULT_DBF_DIR = REPO / "DBF"
SUBDIRS = ("DATA", "INIT")


def parse_args() -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Inventory the DBF tree per company.")
    ap.add_argument("--dbf-dir", default=str(DEFAULT_DBF_DIR),
                    help=f"root holding one directory per company (default: {DEFAULT_DBF_DIR})")
    ap.add_argument("--company", action="append",
                    help="limit to this company (repeatable); default: all")
    ap.add_argument("--json", metavar="PATH", help="also write the full inventory as JSON")
    ap.add_argument("--no-fields", action="store_true", help="skip reading field lists")
    ap.add_argument("--fields", nargs="*", metavar="TABLE",
                    help="print the field list of these tables and exit")
    ap.add_argument("--quiet", action="store_true", help="suppress the per-company summary")
    return ap.parse_args()


def read_table(path: Path, with_fields: bool) -> dict:
    """Header-only read: record count, and optionally the field list."""
    from dbfread import DBF

    entry: dict = {"subdir": path.parent.name}
    try:
        table = DBF(str(path), load=False, ignore_missing_memofile=True)
        entry["records"] = len(table)
        if with_fields:
            entry["fields"] = [f.name for f in table.fields]
    except Exception as exc:  # locked/truncated DBF: record it, do not abort the scan
        entry["error"] = f"{type(exc).__name__}: {exc}"
    return entry


def scan(root: Path, companies: list[str], with_fields: bool) -> dict:
    inv: dict = {"dbf_dir": str(root), "subdirs": list(SUBDIRS), "companies": {}}
    for company in companies:
        tables: dict = {}
        for sub in SUBDIRS:
            directory = root / company / sub
            if not directory.is_dir():
                continue
            for f in sorted(directory.iterdir()):
                if f.is_file() and f.suffix.lower() == ".dbf":
                    # DATA wins over INIT when a name appears in both.
                    name = f.stem.upper()
                    if name in tables and tables[name]["subdir"] == "DATA":
                        continue
                    tables[name] = read_table(f, with_fields)
        inv["companies"][company] = {"tables": tables, "count": len(tables)}
    return inv


def derive(inv: dict) -> None:
    """Derive cross-company facts over the companies actually scanned."""
    sets = {c: set(v["tables"]) for c, v in inv["companies"].items()}
    allnames = set().union(*sets.values()) if sets else set()
    common = set.intersection(*sets.values()) if sets else set()
    inv["scanned"] = sorted(sets)
    inv["union"] = sorted(allnames)
    inv["common_to_all"] = sorted(common)
    inv["not_common"] = {n: sorted(c for c in sets if n in sets[c])
                         for n in sorted(allnames - common)}
    empty = []
    for n in sorted(allnames):
        counts = []
        for c, v in inv["companies"].items():
            entry = v["tables"].get(n)
            if entry is None:
                continue
            counts.append(entry.get("records"))
        if counts and all(c == 0 for c in counts):
            empty.append(n)
    inv["empty_in_all"] = empty


def print_field_lists(inv: dict, wanted: list[str]) -> int:
    for name in wanted:
        target = name.upper()
        rows = []
        for company, v in inv["companies"].items():
            entry = v["tables"].get(target)
            if entry is None:
                rows.append(f"{company}=absent")
            else:
                rows.append(f"{company}={entry.get('records', '?')}")
        print(f"{target:16s} {' '.join(rows)}")
        first = next((v["tables"][target] for v in inv["companies"].values()
                      if target in v["tables"]), None)
        if first and "fields" in first:
            print(f"{'':16s} {','.join(first['fields'])}")
    return 0


def main() -> int:
    args = parse_args()
    root = Path(args.dbf_dir)
    if not root.is_dir():
        print(f"FAIL: DBF dir not found: {root}", file=sys.stderr)
        return 1

    available = sorted(d.name for d in root.iterdir() if d.is_dir())
    companies = args.company or available
    inv = scan(root, companies, not args.no_fields)
    derive(inv)

    if args.fields:
        return print_field_lists(inv, args.fields)

    if not args.quiet:
        for c, v in inv["companies"].items():
            print(f"{c}: {v['count']} tables")
        print(f"union: {len(inv['union'])} tables; common to all: {len(inv['common_to_all'])}")
        if inv["not_common"]:
            print("not common to all:")
            for n, who in inv["not_common"].items():
                print(f"  {n} -> {', '.join(who)}")
        if inv["empty_in_all"]:
            # Only claim "every company" when every company was scanned.
            scope = "every company" if sorted(companies) == available \
                else f"every scanned company ({len(companies)}/{len(available)})"
            print(f"empty in {scope} ({len(inv['empty_in_all'])}): "
                  f"{', '.join(inv['empty_in_all'])}")

    if args.json:
        out = Path(args.json)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(inv, indent=2), encoding="utf-8")
        print(f"wrote {out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
