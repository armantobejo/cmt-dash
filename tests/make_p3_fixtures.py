#!/usr/bin/env python3
"""One-shot generator for the committed P3 DBF fixtures (laravel/tests/fixtures/dbf).

Uses the parity dataset's minimal dBASE-III writer. Column sets mirror
tools/schema.py exactly:

  NA    = common 25 (UNIK..MEAST_SAT incl. GW/NW/MEAST numerics) + 7
          (SAIDTOCONT..READO) + 13 NA-only (BL_NO..TOT_IDR) = 45 fields;
          TYPETRAN is the literal 'I' in the unified SELECT, not a column.
  CARGO = common 25 + 7 + TYPETRAN + ETDSIN + ARRIVAL_TI = 35 fields; the
          NULLed unified columns are literals, not columns.

The fixtures are tiny (two rows per table) but exercise: nested DATA/ + INIT/
layout (CMT), legacy flat layout (CME), a corrupt D-type date cell (R4
tolerance: NULL after conversion, never a crash), and decimal numerics.

Re-run only when the fixture set itself must change:
    python tests/make_p3_fixtures.py
"""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests" / "parity"))
from parity_dataset import write_dbf  # noqa: E402

OUT = ROOT / "laravel" / "tests" / "fixtures" / "dbf"

_COMMON = (
    "UNIK", "SALES", "THN", "BLNO3", "DATE", "REFSHITMEN", "AGENT", "CODE",
    "KD_CONSIG", "CONSIGNEE", "CONTAINERN", "DIMENSI", "GODOWN", "PORTOFLOAD",
    "CITY_DISCH", "PORTOFDISC", "KD_SHIPPER", "SHIPPER", "KD_NOTIFY",
    "NOTIFYPART", "SHIPPINGMA", "GW", "NW", "MEAST", "MEAST_SAT",
    "SAIDTOCONT", "KD_SATUAN", "PREPAID", "OPR", "PRINT", "PRINTDATE", "READO",
)
_NA_TAIL = ("BL_NO", "MBL_NO", "KD_EXVESSE", "EXVOYAGE", "ETA",
            "ENTRY", "PBM", "CONS_NOTI", "CITY_FROM", "DESCRIPTIO",
            "TOT_EUR", "TOT_USD", "TOT_IDR")
_NUMS = {"GW", "NW", "MEAST", "TOT_EUR", "TOT_USD", "TOT_IDR"}


def _fields(names: tuple[str, ...]) -> list[tuple[str, str, int, int]]:
    fields = []
    for n in names:
        if n == "DATE":
            fields.append((n, "D", 8, 0))
        elif n in _NUMS:
            fields.append((n, "N", 12, 3 if n == "MEAST" else 2))
        else:
            fields.append((n, "C", 14, 0))
    return fields


def _na_tail(fill: str) -> list:
    vals = [f"{fill}-N-{i:03d}" for i in range(10)]  # BL_NO..DESCRIPTIO
    vals += [100.0, 20.0, 300000.0]                    # TOT_EUR, TOT_USD, TOT_IDR
    return vals


def _base(fill: str, date: str, gw: float) -> list:
    vals = [f"{fill}-{i:03d}" for i in range(21)]
    vals[4] = date  # DATE
    return vals + [gw, 1200.50, 23.5, "CBM"] + [f"{fill}-S-{i:03d}" for i in range(7)]


def main() -> None:
    cmt = OUT / "CMT" / "DATA"
    init = OUT / "CMT" / "INIT"
    cmt.mkdir(parents=True, exist_ok=True)
    init.mkdir(parents=True, exist_ok=True)

    # NA: row 2 carries a corrupt (all-zero) D-type date — the R4 tolerance case.
    na_rows = [
        tuple(_base("NA1", "2026-03-15", 1000.0) + _na_tail("NA1")),
        tuple(_base("NA2", "00000000", 2500.0) + _na_tail("NA2")),
    ]
    write_dbf(cmt / "NA.DBF", _fields(_COMMON + _NA_TAIL), na_rows)

    # CARGO: TYPETRAN + the two datetime-ish C columns.
    cargo_rows = []
    for fill, date, gw, tt in [("CG1", "2026-04-20", 800.25, "E"), ("CG2", "2026-05-11", 300.0, "T")]:
        cargo_rows.append(tuple(_base(fill, date, gw) + [tt, date, date]))
    write_dbf(cmt / "CARGO.DBF", _fields(_COMMON + ("TYPETRAN", "ETDSIN", "ARRIVAL_TI")), cargo_rows)

    # INIT/ layout: chart of accounts — exercises the second subdir.
    acct_fields = [("KD_ACCOUNT", "C", 10, 0), ("NM_ACCOUNT", "C", 20, 0),
                   ("SLD_NORMAL", "C", 1, 0), ("TYPE", "C", 1, 0),
                   ("GROUP", "C", 2, 0), ("ACTIVE", "C", 1, 0)]
    acct_rows = [("1101", "CASH IN BANK", "D", "A", "01", "Y"),
                 ("2101", "ACCOUNTS PAYABLE", "K", "L", "02", "Y")]
    write_dbf(init / "ACCOUNT.DBF", acct_fields, acct_rows)

    # CME: legacy flat layout (no DATA/ subdir) — one table only, so the
    # sync must skip UnifiedShipment gracefully for this company.
    cme = OUT / "CME"
    cme.mkdir(parents=True, exist_ok=True)
    write_dbf(cme / "ACCOUNT.DBF", acct_fields, acct_rows)

    for p in sorted(OUT.rglob("*.DBF")):
        print(f"{p.relative_to(OUT)}  {p.stat().st_size} bytes")


if __name__ == "__main__":
    main()
