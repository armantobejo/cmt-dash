"""Unpaid-shipments table: pagination, search, unpaid-only filter, dash fix."""
from __future__ import annotations

import os
import sqlite3

from app import queries as q


def _restore_view(conn: sqlite3.Connection) -> None:
    """Restore UnifiedShipment as a VIEW (conftest default) after TABLE-based tests."""
    from schema import _unified_select_sql
    cur = conn.cursor()
    cur.execute("DROP TABLE IF EXISTS UnifiedShipment")
    cur.execute(f"CREATE VIEW UnifiedShipment AS {_unified_select_sql()}")
    conn.commit()


def _seed_unpaid_data():
    """UP-001 (no invoice), UP-002 (invoice unpaid — no payments),
    UP-003 (invoice paid — has payment amounts), UP-004 (invoice unpaid,
    but DNCN has payments → paid).

    Clean-first so re-running within one session never duplicates rows.
    Rebuilds UnifiedShipment table after inserts since it's a snapshot.
    """
    conn = sqlite3.connect(os.environ["DATABASE_URL"])
    try:
        conn.executescript(
            """
            DELETE FROM NA WHERE UNIK LIKE 'UP-%';
            DELETE FROM INVOICE WHERE UNIK LIKE 'UP-%';
            DELETE FROM DRCRFULL WHERE UNIK LIKE 'UP-%';
            DELETE FROM CONSIGNEE WHERE KD_CONSIG = 'C-1';
            INSERT INTO CONSIGNEE (KD_CONSIG, NAME) VALUES ('C-1', 'PT UNPAID TEST');

            -- UP-001: no invoice, no DNCN → unpaid
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG)
                VALUES ('UP-1', 'UP-001', '2026-08-10', 'I', 'C-1');

            -- UP-002: invoice exists, no payment amounts → unpaid
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG)
                VALUES ('UP-2', 'UP-002', '2026-08-09', 'I', 'C-1');
            INSERT INTO INVOICE (UNIK, REFSHITMEN, GRAND_USD, GRAND_RP,
                                 BYR_USD, DIRPKAN, BYR_RP, DIUSDKAN)
                VALUES ('UP-INV-2', 'UP-002', 100.00, 1600000.00,
                        NULL, NULL, NULL, NULL);

            -- UP-003: invoice has payment amounts → paid (excluded)
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG)
                VALUES ('UP-3', 'UP-003', '2026-08-08', 'I', 'C-1');
            INSERT INTO INVOICE (UNIK, REFSHITMEN, GRAND_USD, GRAND_RP,
                                 BYR_USD, DIRPKAN)
                VALUES ('UP-INV-3', 'UP-003', 200.00, 3200000.00, 200.00, 0);

            -- UP-004: invoice unpaid, but DNCN has payments → paid (excluded)
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG)
                VALUES ('UP-4', 'UP-004', '2026-08-07', 'I', 'C-1');
            INSERT INTO INVOICE (UNIK, REFSHITMEN, GRAND_USD, GRAND_RP,
                                 BYR_USD, DIRPKAN)
                VALUES ('UP-INV-4', 'UP-004', 150.00, 2400000.00, NULL, NULL);
            INSERT INTO DRCRFULL (UNIK, REFSHITMEN, TOTAL, TOTAL_IDR,
                                  BAYAR, DIRPKAN, BAYAR_IDR, DIUSDKAN)
                VALUES ('UP-DN-4', 'UP-004', 80.00, 1280000.00, 50.00, 30.00, 800000.00, 480000.00);
            """
        )
        conn.commit()
        # Rebuild UnifiedShipment table (it's a snapshot, not a live view)
        from schema import create_unified_table
        create_unified_table(conn)
    finally:
        _restore_view(conn)
        conn.close()


def test_unpaid_only_excludes_paid_shipments():
    _seed_unpaid_data()
    result = q.get_unpaid_shipments(search="UP-")
    refs = {r["REFSHITMEN"] for r in result["rows"]}
    assert refs == {"UP-001", "UP-002"}, f"unexpected rows: {refs}"
    assert result["total"] == 2
    # Row values must stay raw: UP-002 has an invoice amount, UP-001 has none.
    by_ref = {r["REFSHITMEN"]: r for r in result["rows"]}
    assert by_ref["UP-002"]["INV_USD"] == 100.00
    assert by_ref["UP-001"]["INV_USD"] is None
    # DNCN: UP-001 has none → must be NULL (template renders dash).
    assert by_ref["UP-001"]["DNCN_USD"] is None
    assert by_ref["UP-001"]["DNCN_IDR"] is None
    # Inv payment: UP-002 has no payments → NULL
    assert by_ref["UP-002"]["INV_PAY_USD"] is None
    assert by_ref["UP-001"]["INV_PAY_USD"] is None
    # DNCN payments: UP-001 has no DNCN → NULL
    assert by_ref["UP-001"]["DNCN_PAY_USD"] is None
    assert by_ref["UP-001"]["DNCN_PAY_IDR"] is None


def test_pagination_offsets_correctly():
    _seed_unpaid_data()
    page1 = q.get_unpaid_shipments(search="UP-", per_page=1, page=1)
    page2 = q.get_unpaid_shipments(search="UP-", per_page=1, page=2)
    assert page1["total"] == 2 and page1["pages"] == 2
    assert [r["REFSHITMEN"] for r in page1["rows"]] == ["UP-001"]  # newest first
    assert [r["REFSHITMEN"] for r in page2["rows"]] == ["UP-002"]


def test_search_matches_reference_and_customer():
    _seed_unpaid_data()
    by_ref = q.get_unpaid_shipments(search="up-002")
    assert [r["REFSHITMEN"] for r in by_ref["rows"]] == ["UP-002"]
    by_customer = q.get_unpaid_shipments(search="unpaid test")
    assert by_customer["total"] == 2
    none = q.get_unpaid_shipments(search="NO-SUCH-SHIPMENT")
    assert none["total"] == 0 and none["rows"] == []


def test_sort_order_and_direction():
    _seed_unpaid_data()
    asc = q.get_unpaid_shipments(search="UP-", sort="reference", sort_dir="asc")
    assert [r["REFSHITMEN"] for r in asc["rows"]] == ["UP-001", "UP-002"]
    desc = q.get_unpaid_shipments(search="UP-", sort="reference", sort_dir="desc")
    assert [r["REFSHITMEN"] for r in desc["rows"]] == ["UP-002", "UP-001"]


def test_dash_only_when_both_currencies_missing():
    """The card must render a dash only when BOTH USD and RP are absent —
    an amount in either currency counts as a value."""
    from pathlib import Path

    html = Path("src/app/templates/index.html").read_text(encoding="utf-8")
    # Dash logic now lives in the dual() macro
    assert "usd is none and idr is none" in html
    # All five dual-currency fields are rendered via the macro
    assert "dual(s.OPR_USD, s.OPR_RP)" in html
    assert "dual(s.INV_USD, s.INV_RP)" in html
    assert "dual(s.INV_PAY_USD, s.INV_PAY_IDR)" in html
    assert "dual(s.DNCN_USD, s.DNCN_IDR)" in html
    assert "dual(s.DNCN_PAY_USD, s.DNCN_PAY_IDR)" in html
    # A real value must render even when only one currency exists.
    assert "|money" in html


def test_money_filter_currency_formatting():
    """The money filter must add thousands separators: commas for USD,
    Indonesian dots for IDR; None stays empty."""
    from app.main import _money

    assert _money(1171.50) == "$1,171.50"
    assert _money(2825370, "idr") == "Rp 2.825.370"
    assert _money(1234567.891, "idr") == "Rp 1.234.568"  # whole rupiah
    assert _money(0) == "$0.00"
    assert _money(None) == ""
    assert _money(None, "idr") == ""


def test_money_filter_accounting_format_for_negatives():
    """Negative amounts must be wrapped in parentheses, accounting style."""
    from app.main import _money

    assert _money(-30.0) == "($30.00)"
    assert _money(-1320.0) == "($1,320.00)"
    assert _money(-2825370, "idr") == "(Rp 2.825.370)"


def test_dash_fix_keeps_missing_values_null():
    """A shipment without OprCost/Invoice rows must yield None, not 0 —
    the template renders a dash only when there is genuinely no value."""
    _seed_unpaid_data()
    rows = q.get_recent_shipments()
    row = next(r for r in rows if r["REFSHITMEN"] == "UP-001")
    assert row["OPR_USD"] is None
    assert row["OPR_RP"] is None
    assert row["INV_USD"] is None
    assert row["INV_RP"] is None
    assert row["DNCN_USD"] is None
    assert row["DNCN_IDR"] is None
    assert row["DNCN_PAY_USD"] is None
    assert row["DNCN_PAY_IDR"] is None
    assert row["INV_PAY_USD"] is None
    assert row["INV_PAY_IDR"] is None
