"""T002: UnifiedShipment view SQL is single-sourced in tools/schema.py."""
from __future__ import annotations

import sqlite3
import sys
from pathlib import Path

import pytest

# Allow importing tools/ modules regardless of pytest rootdir.
_TOOLS = Path(__file__).resolve().parent.parent / "tools"
sys.path.insert(0, str(_TOOLS))

from schema import build_unified_view_sql, create_unified_view, unified_view_columns  # noqa: E402


def test_view_sql_is_constant():
    """SQL builder must return identical output on repeated calls (constant)."""
    assert build_unified_view_sql() == build_unified_view_sql()


def test_view_sql_has_both_branches():
    sql = build_unified_view_sql()
    assert "FROM NA" in sql
    assert "FROM CARGO" in sql
    assert "UNION ALL" in sql
    assert "CREATE TABLE UnifiedShipment AS" in sql


def _create_minimal_tables(conn: sqlite3.Connection) -> None:
    """Create minimal NA and CARGO tables with all columns needed by the unified view."""
    # All columns from _NA_SELECT_PARTS (imported from schema.py)
    na_columns = {
        'UNIK TEXT', 'SALES TEXT', 'THN TEXT', 'BLNO3 TEXT', 'DATE TEXT',
        'REFSHITMEN TEXT', 'AGENT TEXT', 'CODE TEXT', 'KD_CONSIG TEXT',
        'CONSIGNEE TEXT', 'CONTAINERN TEXT', 'DIMENSI TEXT', 'GODOWN TEXT',
        'PORTOFLOAD TEXT', 'CITY_DISCH TEXT', 'PORTOFDISC TEXT', 'KD_SHIPPER TEXT',
        'SHIPPER TEXT', 'KD_NOTIFY TEXT', 'NOTIFYPART TEXT', 'SHIPPINGMA TEXT',
        'GW REAL', 'NW REAL', 'MEAST REAL', 'MEAST_SAT REAL', 'SAIDTOCONT REAL',
        'KD_SATUAN TEXT', 'PREPAID TEXT', 'OPR TEXT', 'PRINT TEXT', 'PRINTDATE TEXT',
        'READO TEXT', 'BL_NO TEXT', 'MBL_NO TEXT', 'KD_EXVESSE TEXT', 'EXVOYAGE TEXT',
        'ETA TEXT', 'ENTRY TEXT', 'PBM TEXT', 'CONS_NOTI TEXT', 'CITY_FROM TEXT',
        'DESCRIPTIO TEXT', 'TOT_EUR REAL', 'TOT_USD REAL', 'TOT_IDR REAL'
    }
    cargo_columns = {
        'UNIK TEXT', 'SALES TEXT', 'THN TEXT', 'BLNO3 TEXT', 'DATE TEXT',
        'REFSHITMEN TEXT', 'AGENT TEXT', 'CODE TEXT', 'KD_CONSIG TEXT',
        'CONSIGNEE TEXT', 'CONTAINERN TEXT', 'DIMENSI TEXT', 'GODOWN TEXT',
        'PORTOFLOAD TEXT', 'CITY_DISCH TEXT', 'PORTOFDISC TEXT', 'KD_SHIPPER TEXT',
        'SHIPPER TEXT', 'KD_NOTIFY TEXT', 'NOTIFYPART TEXT', 'SHIPPINGMA TEXT',
        'GW REAL', 'NW REAL', 'MEAST REAL', 'MEAST_SAT REAL', 'SAIDTOCONT REAL',
        'KD_SATUAN TEXT', 'PREPAID TEXT', 'OPR TEXT', 'PRINT TEXT', 'PRINTDATE TEXT',
        'READO TEXT', 'TYPETRAN TEXT', 'ETDSIN TEXT', 'ETADES TEXT', 'ARRIVAL_TI TEXT'  # CARGO has TYPETRAN instead of the literal 'I'
    }
    conn.execute(f"CREATE TABLE NA ({', '.join(sorted(na_columns))})")
    conn.execute(f"CREATE TABLE CARGO ({', '.join(sorted(cargo_columns))})")


def test_create_unified_view_on_empty_db():
    """Table creation must succeed on a DB with empty NA/CARGO tables."""
    conn = sqlite3.connect(":memory:")
    _create_minimal_tables(conn)
    create_unified_view(conn)
    cur = conn.cursor()
    row = cur.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='UnifiedShipment'"
    ).fetchone()
    assert row is not None, "UnifiedShipment table should exist after creation"
    conn.close()


def test_view_column_count_matches_na_branch():
    cols = unified_view_columns()
    # 31 common + 17 NA-only (including the literal 'I' AS TYPETRAN, ETD, ATA) = 48 cols.
    assert len(cols) == 48, f"expected 48 columns, got {len(cols)}"
    assert "TYPETRAN" in [c.split()[-1] for c in cols]


def _restore_view(conn: sqlite3.Connection) -> None:
    """Restore the UnifiedShipment as a VIEW (conftest default) after TABLE-based tests."""
    from schema import _unified_select_sql
    cur = conn.cursor()
    cur.execute("DROP TABLE IF EXISTS UnifiedShipment")
    cur.execute(f"CREATE VIEW UnifiedShipment AS {_unified_select_sql()}")
    conn.commit()


def test_view_deduplicates_by_refshitmen():
    """A shipment stored once per house BL/container must appear exactly once,
    with GW/NW/MEAST summed across the group."""
    import os
    import sqlite3

    conn = sqlite3.connect(os.environ["DATABASE_URL"])
    try:
        conn.execute("DELETE FROM NA WHERE UNIK LIKE 'DUP-%'")
        conn.executescript(
            """
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, BLNO3, GW, NW, MEAST, TYPETRAN)
                VALUES ('DUP-1', 'DUP-REF-1', '2026-08-01', 'B1', 100, 50, 10, 'I');
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, BLNO3, GW, NW, MEAST, TYPETRAN)
                VALUES ('DUP-2', 'DUP-REF-1', '2026-08-01', 'B2', 200, 70, 20, 'I');
            """
        )
        conn.commit()
        # Rebuild UnifiedShipment table after inserting test data
        from schema import create_unified_table
        create_unified_table(conn)
        row = conn.execute(
            "SELECT COUNT(*) AS n, SUM(GW) AS gw, SUM(NW) AS nw, SUM(MEAST) AS meast "
            "FROM UnifiedShipment WHERE REFSHITMEN = 'DUP-REF-1'"
        ).fetchone()
        n, gw, nw, meast = row
        assert n == 1, f"expected 1 row per REFSHITMEN, got {n}"
        assert gw == 300
        assert nw == 120
        assert meast == 30
    finally:
        conn.execute("DELETE FROM NA WHERE UNIK LIKE 'DUP-%'")
        conn.commit()
        _restore_view(conn)
        conn.close()
