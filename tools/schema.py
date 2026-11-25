"""Shared module for schema definitions used by both the app and the sync script.

Single source of truth for the UnifiedShipment view SQL (previously duplicated
in src/app/queries.py and tools/sync_db.py).
"""
from __future__ import annotations

import sqlite3

# NA (import) branch of the unified view, in column order.
_NA_SELECT_PARTS = [
    "UNIK", "SALES", "THN", "BLNO3", "DATE", "REFSHITMEN", "AGENT", "CODE",
    "KD_CONSIG", "CONSIGNEE", "CONTAINERN", "DIMENSI", "GODOWN", "PORTOFLOAD",
    "CITY_DISCH", "PORTOFDISC", "KD_SHIPPER", "SHIPPER", "KD_NOTIFY",
    "NOTIFYPART", "SHIPPINGMA", "GW", "NW", "MEAST", "MEAST_SAT", "SAIDTOCONT",
    "KD_SATUAN", "PREPAID", "OPR", "PRINT", "PRINTDATE", "READO",
    # NA-only fields
    "'I' AS TYPETRAN", "BL_NO", "MBL_NO", "KD_EXVESSE", "EXVOYAGE", "ETA",
    "NULL AS ETD", "NULL AS ATA",
    "ENTRY", "PBM", "CONS_NOTI", "CITY_FROM", "DESCRIPTIO", "TOT_EUR",
    "TOT_USD", "TOT_IDR"
]

_CARGO_SELECT_PARTS = [
    "UNIK", "SALES", "THN", "BLNO3", "DATE", "REFSHITMEN", "AGENT", "CODE",
    "KD_CONSIG", "CONSIGNEE", "CONTAINERN", "DIMENSI", "GODOWN", "PORTOFLOAD",
    "CITY_DISCH", "PORTOFDISC", "KD_SHIPPER", "SHIPPER", "KD_NOTIFY",
    "NOTIFYPART", "SHIPPINGMA", "GW", "NW", "MEAST", "MEAST_SAT", "SAIDTOCONT",
    "KD_SATUAN", "PREPAID", "OPR", "PRINT", "PRINTDATE", "READO",
    # CARGO-specific equivalent of TYPETRAN
    "TYPETRAN",
    # NA-only fields set to NULL for CARGO
    "NULL AS BL_NO", "NULL AS MBL_NO", "NULL AS KD_EXVESSE", "NULL AS EXVOYAGE",
    "NULL AS ETA", "ETDSIN AS ETD", "ARRIVAL_TI AS ATA",
    "NULL AS ENTRY", "NULL AS PBM", "NULL AS CONS_NOTI",
    "NULL AS CITY_FROM", "NULL AS DESCRIPTIO", "NULL AS TOT_EUR",
    "NULL AS TOT_USD", "NULL AS TOT_IDR"
]


def _unified_select_sql(tables: set[str] | None = None) -> str:
    """Inner SELECT that merges NA + CARGO into one row per REFSHITMEN.

    *tables* is the set of available source table names (e.g. {'NA', 'CARGO'}).
    If ``None``, assumes both are present (backward-compatible default).
    """
    if tables is None:
        tables = {'NA', 'CARGO'}
    na_cols = _NA_SELECT_PARTS
    cargo_cols = _CARGO_SELECT_PARTS
    parts: list[str] = []
    if 'NA' in tables:
        parts.append(f"SELECT {', '.join(na_cols)} FROM NA")
    if 'CARGO' in tables:
        parts.append(f"SELECT {', '.join(cargo_cols)} FROM CARGO")
    if not parts:
        return "SELECT 1 WHERE 0"  # empty result, should never be used
    union = "\nUNION ALL\n".join(parts)
    return (
        "SELECT UNIK, SALES, THN, BLNO3, DATE, REFSHITMEN, AGENT, CODE, KD_CONSIG, "
        "CONSIGNEE, CONTAINERN, DIMENSI, GODOWN, PORTOFLOAD, CITY_DISCH, PORTOFDISC, "
        "KD_SHIPPER, SHIPPER, KD_NOTIFY, NOTIFYPART, SHIPPINGMA, "
        "_GW AS GW, _NW AS NW, _MEAST AS MEAST, "
        "MEAST_SAT, SAIDTOCONT, KD_SATUAN, PREPAID, OPR, PRINT, PRINTDATE, READO, "
        "TYPETRAN, BL_NO, MBL_NO, KD_EXVESSE, EXVOYAGE, ETA, ETD, ATA, ENTRY, PBM, CONS_NOTI, "
        "CITY_FROM, DESCRIPTIO, TOT_EUR, TOT_USD, TOT_IDR\n"
        "FROM (\n"
        "  SELECT *, "
        "ROW_NUMBER() OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN, ''), UNIK) "
        "ORDER BY UNIK) AS _rn, "
        "SUM(GW) OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN, ''), UNIK)) AS _GW, "
        "SUM(NW) OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN, ''), UNIK)) AS _NW, "
        "SUM(MEAST) OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN, ''), UNIK)) AS _MEAST\n"
        "  FROM (\n"
        f"    {union}\n"
        "  )\n"
        ") WHERE _rn = 1"
    )


def create_unified_table(conn: sqlite3.Connection) -> None:
    """Drop + recreate UnifiedShipment as a real TABLE.

    A table is faster than a view with window functions, and the sync
    script rebuilds it atomically per company.

    Skips gracefully if required source tables (NA, CARGO) are missing.
    """
    cur = conn.cursor()
    # Check that required source tables exist
    existing = {
        r[0] for r in cur.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('NA','CARGO')"
        ).fetchall()
    }
    if not existing:
        print("  [schema] Skipping UnifiedShipment: neither NA nor CARGO table found")
        return
    # Drop whatever exists — check type first to avoid SQLite errors
    row = cur.execute("SELECT type FROM sqlite_master WHERE name='UnifiedShipment'").fetchone()
    if row:
        cur.execute(f"DROP {row[0].upper()} UnifiedShipment")
    cur.execute(f"CREATE TABLE UnifiedShipment AS {_unified_select_sql(existing)}")
    # Index on REFSHITMEN for fast lookups by shipment reference.
    cur.execute("CREATE INDEX IF NOT EXISTS idx_us_ref ON UnifiedShipment(REFSHITMEN)")
    conn.commit()


def create_unified_view(conn: sqlite3.Connection) -> None:
    """Backward-compatible alias for create_unified_table."""
    create_unified_table(conn)


def build_unified_view_sql() -> str:
    """Backward-compatible alias."""
    return f"CREATE TABLE UnifiedShipment AS {_unified_select_sql()}"


def unified_view_columns() -> list[str]:
    """Column names of the NA (left) branch of the view, as created."""
    return list(_NA_SELECT_PARTS)