"""Deterministic synthetic dataset for the parity-baseline harness.

Everything the FastAPI app queries is exercised by a small, fixed dataset so
the captured baseline JSON is stable and the Laravel side can be diffed
against it row-for-row. Includes a tiny real DBF tree (no FPT memos needed by
these tables) so F-11 POST /sync and F-10 /set-db run end-to-end.

Table shapes mirror the REAL company DBs (tests/parity/fixtures/real_schema.json,
regenerated via tools/dump_real_schema.py): the sync owns these schemas from
FoxPro field metadata, so the dataset carries only columns production actually
has — a query against a phantom column must fail here exactly as it does in
production (the INVOICE.CONTAINERN lesson, docs/decisions/
invoice-container-schema-drift.md).

Layout: data dir with cmt.db (default) + CME.db (switcher target); dbf dir
with CMT/ (DATA/ + INIT/) and CME/ (flat) company subdirs.
"""
from __future__ import annotations

import sqlite3
import struct
import sys
from pathlib import Path

_REPO_ROOT = Path(__file__).resolve().parents[2]
_TOOLS = _REPO_ROOT / "tools"
if str(_TOOLS) not in sys.path:
    sys.path.insert(0, str(_TOOLS))

from schema import _unified_select_sql  # noqa: E402  (single SoT for view SQL)

# Deterministic fixture constants (imported by the harness).
DEFAULTS = {
    "ref": "REF-TRISCO-001",          # Trisco-owned shipment (invoice + OPR + DN)
    "ref2": "REF-TRISCO-002",         # Trisco-owned shipment, NO invoices (unissued filter)
    "ref3": "REF-OTHER-001",          # foreign shipment (negative test)
    "customer": "TRISCO",
    "other_customer": "MAERSK LINE",
    "invoice": "INV-0001",
    "invoice_foreign": "INV-0002",     # on ref3: exercises the customer detail guard reject
    "account": "1101",
    "account_name": "CASH IN BANK",
    "bad_date_ref": "JE-BADDATE-1",
}

_NA_COLS = (
    "UNIK SALES THN BLNO3 DATE REFSHITMEN AGENT CODE KD_CONSIG CONSIGNEE "
    "CONTAINERN DIMENSI GODOWN PORTOFLOAD CITY_DISCH PORTOFDISC KD_SHIPPER "
    "SHIPPER KD_NOTIFY NOTIFYPART SHIPPINGMA GW NW MEAST MEAST_SAT SAIDTOCONT "
    "KD_SATUAN PREPAID OPR PRINT PRINTDATE READO BL_NO MBL_NO "
    "KD_EXVESSE EXVOYAGE ETA ENTRY PBM CONS_NOTI CITY_FROM DESCRIPTIO "
    "TOT_EUR TOT_USD TOT_IDR"
).split()

_CARGO_COLS = (
    "UNIK SALES THN BLNO3 DATE REFSHITMEN AGENT CODE KD_CONSIG CONSIGNEE "
    "CONTAINERN DIMENSI GODOWN PORTOFLOAD CITY_DISCH PORTOFDISC KD_SHIPPER "
    "SHIPPER KD_NOTIFY NOTIFYPART SHIPPINGMA GW NW MEAST MEAST_SAT SAIDTOCONT "
    "KD_SATUAN PREPAID OPR PRINT PRINTDATE READO TYPETRAN ETDSIN ETADES ARRIVAL_TI"
).split()

_NA_TYPES = {c: "REAL" for c in ("GW", "NW", "MEAST", "TOT_EUR", "TOT_USD", "TOT_IDR")}
_REAL_COLS = {
    "OPRCOST": ("EXRATE", "GRAND_USD", "GRAND_RP"),
    "OPRCOST_R": ("QTY", "TSRATE", "JUMLAH"),
    "INVOICE": ("EXRATE", "GRAND_USD", "GRAND_RP", "BYR_USD", "BYR_RP", "SELISIH_US", "SELISIH_ID", "DIRPKAN", "DIUSDKAN"),
    "INVOICE_R": ("QTY", "SELLING", "JUMLAH", "NO"),
    "DRCRFULL": ("TOTAL", "TOTAL_IDR", "BAYAR", "DIRPKAN", "BAYAR_IDR", "DIUSDKAN"),
    "DRCRFULL_R": ("QTY", "UNITPRICE", "JUMLAH"),
    "JE": ("TOT_DR", "TOT_CR"),
    "JE_D": ("JUMLAH", "EXRATE"),
}


def _table(conn, name, cols, real_cols=()):
    types = {c: ("REAL" if c in real_cols else "TEXT") for c in cols}
    # rowid_ INTEGER PRIMARY KEY mirrors the sync's create_schema() layout:
    # every synced table gets one, and queries.py selects i.rowid_ on INVOICE.
    col_defs = ['"rowid_" INTEGER PRIMARY KEY']
    col_defs += [f'"{c}" {types[c]}' for c in cols]
    conn.execute(f'DROP TABLE IF EXISTS "{name}"')
    conn.execute(f'CREATE TABLE "{name}" ({", ".join(col_defs)})')


def _insert(conn, name, cols, rows):
    if not rows:
        return
    quoted = ",".join(f'"{c}"' for c in cols)
    ph = ",".join(["?"] * len(cols))
    conn.executemany(f'INSERT INTO "{name}" ({quoted}) VALUES ({ph})', rows)


def _base_tables(conn):
    """Create the schema every company DB needs (subset the app queries)."""
    _table(conn, "NA", _NA_COLS, set(_NA_TYPES))
    _table(conn, "CARGO", _CARGO_COLS)
    _table(conn, "VESSEL", ["KD_VESSEL", "NM_VESSEL"])
    _table(conn, "CONSIGNEE", ["KD_CONSIG", "NAME"])
    _table(conn, "SHIPPER", ["KD_SHIPPER", "NAME"])
    _table(conn, "AGENT", ["AGENT", "CODE", "NAME"])
    _table(conn, "OPRCOST", ["UOPRCOST", "REFSHITMEN", "NOMOR", "TANGGAL", "EXRATE", "GRAND_USD", "GRAND_RP"], _REAL_COLS["OPRCOST"])
    _table(conn, "OPRCOST_R", ["UOPRCOST", "JENISCOST", "DESCRIPTIO", "CURRENCY", "QTY", "TSRATE", "JUMLAH", "INVOICE_NO", "INV_TGL", "KETERANGAN"], _REAL_COLS["OPRCOST_R"])
    _table(conn, "INVOICE",
           ["UNIK", "K_INVOICE", "NOINVOICE", "REFSHITMEN", "EI", "DATEOFISSU", "SHIPPER",
            "EXRATE", "GRAND_USD", "GRAND_RP", "BYR_USD", "BYR_RP", "SELISIH_US", "SELISIH_ID",
            "LUNAS", "REMARK", "DIRPKAN", "DIUSDKAN",
            "NOTEINV", "BLNO", "BLNO3", "PORTOFLOAD", "PORTOFDISC",
            "VESSELNAME", "ETDDATE", "ETADATE"],
           _REAL_COLS["INVOICE"])
    _table(conn, "INVOICE_R", ["NOINVOICE", "K_INVOICE", "NO", "JENISCOST", "DESCRIPTIO", "CURRENCY", "QTY", "SELLING", "JUMLAH", "NOTE", "KD_SATUAN"], _REAL_COLS["INVOICE_R"])
    _table(conn, "SATUAN", ["KD_SATUAN", "SATUAN"])
    _table(conn, "DRCRFULL",
           ["UNIK", "NO_REF", "REFSHITMEN", "TANGGAL", "TONAME", "MODE", "BLNO", "BLNO3",
            "TOTAL", "TOTAL_IDR", "BAYAR", "DIRPKAN", "BAYAR_IDR", "DIUSDKAN", "LUNAS", "ACCT_DRCR"],
           _REAL_COLS["DRCRFULL"])
    _table(conn, "DRCRFULL_R", ["UNIK", "JENISCOST", "DESCRIPTIO", "CURRENCY", "QTY", "UNITPRICE", "JUMLAH"], _REAL_COLS["DRCRFULL_R"])
    _table(conn, "ACCOUNT", ["KD_ACCOUNT", "NM_ACCOUNT", "SLD_NORMAL", "TYPE", "GROUP", "ACTIVE"])
    _table(conn, "JE", ["UJE", "TANGGAL", "REF_NO", "KETERANGAN", "MODUL", "TOT_DR", "TOT_CR"], _REAL_COLS["JE"])
    _table(conn, "JE_D", ["UJE", "KD_ACCOUNT", "URAIAN", "JUMLAH", "DK", "CURRENCY", "EXRATE", "MODUL"], _REAL_COLS["JE_D"])


def _unified_view(conn):
    """UnifiedShipment as a VIEW so seed inserts are visible without a rebuild."""
    conn.execute("DROP VIEW IF EXISTS UnifiedShipment")
    conn.execute("DROP TABLE IF EXISTS UnifiedShipment")
    conn.execute(f"CREATE VIEW UnifiedShipment AS {_unified_select_sql()}")


def _seed_business(conn, d):
    na_row = (  # import shipment (TYPETRAN=I via the view) — Trisco consignee
        "U1", "S001", "2026", "BL-001", "2026-03-15", d["ref"], "AG1", "C01",
        "C1", d["customer"], "1x20", "20FT", "GDN1", "SIN", "ID-JKT", "JKT",
        "S1", d["other_customer"], "N1", "NP1", "EVERGREEN", 10.0, 8.0, 12.0,
        "CBM", "SAID", "SAT1", "PP", "O1", "Y", "2026-03-01", "0",
        None, None, None, None, None, None, None, None, None,
        "Gen cargo", 0.0, 1000.0, 15000000.0,
    )
    cargo_row = (  # export shipment — Trisco shipper
        "U2", "S002", "2026", "BL-002", "2026-04-20", d["ref2"], "AG1", "C01",
        "C2", "PT MAJU JAYA", "1x40", "40FT", "GDN1", "JKT", "", "SIN",
        "S1", d["customer"], "N1", "NP1", "EVERGREEN", 5.0, 4.0, 6.0,
        "CBM", "SAID", "SAT1", "CC", "O2", "N", "", "0",
        "E", None, None, None,
    )
    cargo_row2 = (  # foreign shipment (negative test)
        "U3", "S003", "2026", "BL-003", "2026-05-05", d["ref3"], "AG2", "C02",
        "C3", d["other_customer"], "1x20", "20FT", "GDN2", "SIN", "", "NLRTM",
        "S2", "PT Sumber Rejeki", "N2", "NP2", "MAERSK", 8.0, 7.0, 9.0,
        "CBM", "SAID", "SAT1", "CC", "O3", "N", "", "0",
        "E", None, None, None,
    )
    _insert(conn, "NA", _NA_COLS, [na_row])
    _insert(conn, "CARGO", _CARGO_COLS, [cargo_row, cargo_row2])
    _insert(conn, "CONSIGNEE", ["KD_CONSIG", "NAME"],
            [("C1", d["customer"]), ("C2", "PT MAJU JAYA"), ("C3", d["other_customer"])])
    _insert(conn, "SHIPPER", ["KD_SHIPPER", "NAME"],
            [("S1", d["other_customer"]), ("S2", "PT Sumber Rejeki")])
    _insert(conn, "AGENT", ["AGENT", "CODE", "NAME"], [("AG1", "C01", "Evergreen Agent"), ("AG2", "C02", "Maersk Agent")])
    _insert(conn, "VESSEL", ["KD_VESSEL", "NM_VESSEL"], [("KV1", "MV OCEAN STAR")])

    # OPR costs (on ref only)
    _insert(conn, "OPRCOST", ["UOPRCOST", "REFSHITMEN", "NOMOR", "TANGGAL", "EXRATE", "GRAND_USD", "GRAND_RP"],
            [("O1", d["ref"], "OPR-1", "2026-03-16", 15000.0, 200.0, 3000000.0)])
    _insert(conn, "OPRCOST_R", ["UOPRCOST", "JENISCOST", "DESCRIPTIO", "CURRENCY", "QTY", "TSRATE", "JUMLAH", "INVOICE_NO", "INV_TGL", "KETERANGAN"],
            [("O1", "THC", "Terminal handling", "USD", 1.0, 200.0, 200.0, "TI-1", "2026-03-16", "THC fee")])

    # Invoice: ref PAID (pay == grand); ref2 has none (unissued filter)
    _insert(conn, "INVOICE",
            ["UNIK", "K_INVOICE", "NOINVOICE", "REFSHITMEN", "EI", "DATEOFISSU", "SHIPPER",
             "EXRATE", "GRAND_USD", "GRAND_RP", "BYR_USD", "BYR_RP", "SELISIH_US", "SELISIH_ID",
             "LUNAS", "REMARK", "DIRPKAN", "DIUSDKAN",
             "NOTEINV", "BLNO", "BLNO3", "PORTOFLOAD", "PORTOFDISC",
             "VESSELNAME", "ETDDATE", "ETADATE"],
            [("I1", d["invoice"], d["invoice"], d["ref"], "I", "2026-03-18", "S1",
              15000.0, 1200.0, 18000000.0, 1200.0, 18000000.0, 0.0, 0.0,
              1, "paid in full", 0.0, 0.0,
              "cust note", "BL-001", "BL-001", "SIN", "JKT",
              "MV OCEAN STAR", "2026-03-15", "2026-03-20")])
    _insert(conn, "INVOICE_R", ["NOINVOICE", "K_INVOICE", "NO", "JENISCOST", "DESCRIPTIO", "CURRENCY", "QTY", "SELLING", "JUMLAH", "NOTE", "KD_SATUAN"],
            [(d["invoice"], d["invoice"], 1.0, "FREIGHT", "Ocean freight", "USD", 1.0, 1200.0, 1200.0, "freight", "SAT1"),
             (d["invoice_foreign"], d["invoice_foreign"], 1.0, "DOC FEE", "Documentation", "USD", 1.0, 30.0, 30.0, "doc fee", None)])
    _insert(conn, "SATUAN", ["KD_SATUAN", "SATUAN"], [("SAT1", "CBM")])

    # Foreign invoice on ref3 (Sumber Rejeki shipment, unpaid) — customer-role
    # guard reject target; also makes the issued_unpaid filter non-empty.
    _insert(conn, "INVOICE",
            ["UNIK", "K_INVOICE", "NOINVOICE", "REFSHITMEN", "EI", "DATEOFISSU", "SHIPPER",
             "EXRATE", "GRAND_USD", "GRAND_RP", "BYR_USD", "BYR_RP", "SELISIH_US", "SELISIH_ID",
             "LUNAS", "REMARK", "DIRPKAN", "DIUSDKAN",
             "NOTEINV", "BLNO", "BLNO3", "PORTOFLOAD", "PORTOFDISC",
             "VESSELNAME", "ETDDATE", "ETADATE"],
            [("I2", d["invoice_foreign"], d["invoice_foreign"], d["ref3"], "E", "2026-05-06", "S2",
              15000.0, 90.0, 1350000.0, 0.0, 0.0, 0.0, 0.0,
              0, "", 0.0, 0.0,
              None, "BL-003", "BL-003", "SIN", "NLRTM",
              "MAERSK ATLANTIC", "2026-05-05", "2026-05-25")])

    # DN/CN: positive total -> CN bucket (per get_shipment_detail classification)
    _insert(conn, "DRCRFULL",
            ["UNIK", "NO_REF", "REFSHITMEN", "TANGGAL", "TONAME", "MODE", "BLNO", "BLNO3",
             "TOTAL", "TOTAL_IDR", "BAYAR", "DIRPKAN", "BAYAR_IDR", "DIUSDKAN", "LUNAS", "ACCT_DRCR"],
            [("D1", "DN-1", d["ref"], "2026-03-20", d["customer"], "DN", "BL-001", "BL-001",
              50.0, 750000.0, 0.0, 0.0, 0.0, 0.0, 0, "")])
    _insert(conn, "DRCRFULL_R", ["UNIK", "JENISCOST", "DESCRIPTIO", "CURRENCY", "QTY", "UNITPRICE", "JUMLAH"],
            [("D1", "FEE", "Doc fee", "USD", 1.0, 50.0, 50.0)])

    # GL: one balanced voucher, one unbalanced, one pre-2000 date, one stray DK
    _insert(conn, "ACCOUNT", ["KD_ACCOUNT", "NM_ACCOUNT", "SLD_NORMAL", "TYPE", "GROUP", "ACTIVE"],
            [(d["account"], d["account_name"], "D", "D", "1", "1"),
             ("9999", "SUSPENSE", "D", "D", "1", "1"),
             ("4000", "REVENUE", "K", "G", "4", "1")])
    _insert(conn, "JE", ["UJE", "TANGGAL", "REF_NO", "KETERANGAN", "MODUL", "TOT_DR", "TOT_CR"],
            [("J1", "2026-02-01", "JV-001", "Opening", "GL", 5000000.0, 5000000.0),
             ("J2", "2026-02-02", "JV-002", "Off by 1", "GL", 100.0, 99.0),
             ("J3", "1999-12-31", d["bad_date_ref"], "Legacy date", "GL", 10.0, 10.0)])
    _insert(conn, "JE_D", ["UJE", "KD_ACCOUNT", "URAIAN", "JUMLAH", "DK", "CURRENCY", "EXRATE", "MODUL"],
            [("J1", d["account"], "Deposit", 5000000.0, "D", "RP", None, "GL"),
             ("J1", "4000", "Revenue", 5000000.0, "K", "RP", None, "GL"),
             ("J2", d["account"], "Dr side", 100.0, "D", "USD", 15000.0, "GL"),
             ("J2", "9999", "Cr side", 99.0, "K", "USD", 15000.0, "GL"),
             ("J3", "9999", "Bad date entry", 10.0, "X", "RP", None, "GL")])


def _seed_gl_only(conn, d):
    """CME.db: GL tables only — exercises the switcher with sparse data."""
    _table(conn, "ACCOUNT", ["KD_ACCOUNT", "NM_ACCOUNT", "SLD_NORMAL", "TYPE", "GROUP", "ACTIVE"])
    _table(conn, "JE", ["UJE", "TANGGAL", "REF_NO", "KETERANGAN", "MODUL", "TOT_DR", "TOT_CR"], _REAL_COLS["JE"])
    _table(conn, "JE_D", ["UJE", "KD_ACCOUNT", "URAIAN", "JUMLAH", "DK", "CURRENCY", "EXRATE"], _REAL_COLS["JE_D"])
    _insert(conn, "ACCOUNT", ["KD_ACCOUNT", "NM_ACCOUNT", "SLD_NORMAL", "TYPE", "GROUP", "ACTIVE"],
            [(d["account"], "CME CASH", "D", "D", "1", "1")])
    _insert(conn, "JE", ["UJE", "TANGGAL", "REF_NO", "KETERANGAN", "MODUL", "TOT_DR", "TOT_CR"],
            [("K1", "2026-01-15", "CME-1", "CME opening", "GL", 1000.0, 1000.0)])
    _insert(conn, "JE_D", ["UJE", "KD_ACCOUNT", "URAIAN", "JUMLAH", "DK", "CURRENCY", "EXRATE"],
            [("K1", d["account"], "CME line", 1000.0, "D", "RP", None)])


# ── Minimal DBF writer (dBASE III, no memo fields) ──────────────────────────
# Field spec: (name, type, length, decimals). Types used: C, N, D.

def _dbf_field_value(v, ftype, flen, fdec):
    if v is None:
        v = ""
    if ftype == "N":
        s = f"{v:.{fdec}f}" if fdec else str(int(v))
        return s.rjust(flen)
    if ftype == "D":
        return str(v).replace("-", "").ljust(flen)
    return str(v).encode("latin-1", "replace")[:flen].decode("latin-1").ljust(flen)


def write_dbf(path: Path, fields: list[tuple], rows: list[tuple]) -> None:
    """Write a minimal dBASE-III level-5 DBF file (header + records + EOF)."""
    path.parent.mkdir(parents=True, exist_ok=True)
    header_len = 32 + 32 * len(fields) + 1
    rec_len = 1 + sum(f[2] for f in fields)
    with open(path, "wb") as f:
        # version=0x03, date (YY since 1900, MM, DD), count, header_len, rec_len
        f.write(struct.pack("<BBBBIHH", 0x03, 126, 2, 10, len(rows), header_len, rec_len))
        f.write(b"\x00" * 20)
        for name, ftype, flen, fdec in fields:
            f.write(struct.pack("<11sc4sBB", name.encode("ascii")[:11].ljust(11, b"\x00"),
                                ftype.encode(), b"\x00" * 4, flen, fdec))
            f.write(b"\x00" * 14)
        f.write(b"\x0d")  # header terminator
        for row in rows:
            f.write(b" ")  # deletion flag: not deleted
            for (name, ftype, flen, fdec), v in zip(fields, row):
                f.write(_dbf_field_value(v, ftype, flen, fdec).encode("latin-1", "replace"))
        f.write(b"\x1a")  # EOF


_ACCT_FIELDS = [("KD_ACCOUNT", "C", 10, 0), ("NM_ACCOUNT", "C", 20, 0),
                ("SLD_NORMAL", "C", 1, 0), ("TYPE", "C", 1, 0),
                ("GROUP", "C", 2, 0), ("ACTIVE", "C", 1, 0)]
_CARGO_SHORT_FIELDS = [(c, "C", 15, 0) for c in _CARGO_COLS[:10]]
_NA_SHORT_FIELDS = [(c, "C", 15, 0) for c in _NA_COLS[:10]]


def _write_dbf_tree(dbf_dir: Path) -> None:
    """Two company dirs: CMT (DATA/ + INIT/) and CME (flat, legacy layout)."""
    write_dbf(dbf_dir / "CMT" / "DATA" / "CARGO.DBF", _CARGO_SHORT_FIELDS,
              [tuple(f"X{i}" for i in range(10))])
    write_dbf(dbf_dir / "CMT" / "DATA" / "NA.DBF", _NA_SHORT_FIELDS,
              [tuple(f"Y{i}" for i in range(10))])
    write_dbf(dbf_dir / "CMT" / "INIT" / "ACCOUNT.DBF", _ACCT_FIELDS,
              [("1101", "INIT CASH", "D", "D", "1", "1")])
    write_dbf(dbf_dir / "CME" / "ACCOUNT.DBF", _ACCT_FIELDS,
              [("1101", "CME INIT CASH", "D", "D", "1", "1")])


def build_dataset(data_dir: Path, dbf_dir: Path, defaults: dict) -> dict:
    """Create the data dir (cmt.db + CME.db), the DBF company tree, and return paths."""
    data_dir.mkdir(parents=True, exist_ok=True)
    dbf_dir.mkdir(parents=True, exist_ok=True)

    d = dict(defaults)

    # Default DB (auth + main business data)
    conn = sqlite3.connect(str(data_dir / "cmt.db"))
    try:
        _base_tables(conn)
        _seed_business(conn, d)
        _unified_view(conn)
        conn.commit()
    finally:
        conn.close()

    # Second company DB for the switcher + data-quality scoping
    conn = sqlite3.connect(str(data_dir / "CME.db"))
    try:
        _seed_gl_only(conn, d)
        conn.commit()
    finally:
        conn.close()

    _write_dbf_tree(dbf_dir)
    return {"data_dir": data_dir, "dbf_dir": dbf_dir, "defaults": d}
