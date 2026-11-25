"""Recent Shipments card must show Opr Cost, Invoice, Paid (no BL No)."""
from __future__ import annotations

import os
import sqlite3

from app import db


def _seed_join_data():
    """Insert one shipment + cost + invoice row sharing REFSHITMEN 'RS-001'."""
    conn = sqlite3.connect(os.environ["DATABASE_URL"])
    try:
        conn.executescript(
            """
            -- clean-first so re-runs within a session never double the sums
            DELETE FROM NA WHERE UNIK LIKE 'NA-%';
            DELETE FROM OPRCOST WHERE UNIK LIKE 'OC-%';
            DELETE FROM INVOICE WHERE UNIK LIKE 'INV-%';
            DELETE FROM DRCRFULL WHERE UNIK LIKE 'DN-%';
            -- one shipment in UnifiedShipment
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN)
                VALUES ('NA-1', 'RS-001', '2026-08-01', 'I');
            INSERT INTO OPRCOST (UNIK, REFSHITMEN, GRAND_USD, GRAND_RP)
                VALUES ('OC-1', 'RS-001', 123.45, 1950000.00);
            INSERT INTO INVOICE (UNIK, REFSHITMEN, GRAND_USD, GRAND_RP, READO, LUNAS,
                                 BYR_USD, DIRPKAN, BYR_RP, DIUSDKAN)
                VALUES ('INV-1', 'RS-001', 200.00, 3200000.00, 1, 1, 80.00, 20.00, 1000000.00, 500000.00);
            INSERT INTO DRCRFULL (UNIK, REFSHITMEN, TOTAL, TOTAL_IDR,
                                  BAYAR, DIRPKAN, BAYAR_IDR, DIUSDKAN)
                VALUES ('DN-1', 'RS-001', 300.00, 4800000.00, 100.00, 50.00, 1000000.00, 500000.00);
            """
        )
        conn.commit()
    finally:
        conn.close()


def test_recent_shipments_returns_cost_and_invoice():
    """get_recent_shipments() must expose Opr Cost / Invoice / Paid per row."""
    _seed_join_data()

    from app import queries as q

    rows = q.get_recent_shipments()
    assert rows, "expected at least one recent shipment row"
    row = rows[0]
    expected_keys = {
        "OPR_USD", "OPR_RP", "INV_USD", "INV_RP", "PAID",
        "DNCN_USD", "DNCN_IDR", "DNCN_PAY_USD", "DNCN_PAY_IDR",
        "INV_PAY_USD", "INV_PAY_IDR",
    }
    missing = expected_keys - set(row.keys())
    assert not missing, f"missing columns in row: {missing}; got {list(row.keys())}"
    assert row["OPR_USD"] == 123.45
    assert row["OPR_RP"] == 1950000.00
    assert row["INV_USD"] == 200.00
    assert row["INV_RP"] == 3200000.00
    assert row["DNCN_USD"] == 300.00
    assert row["DNCN_IDR"] == 4800000.00
    # Payments: BYR_USD+DIRPKAN / BYR_RP+DIUSDKAN for the invoice,
    # BAYAR+DIRPKAN / BAYAR_IDR+DIUSDKAN for the DN/CN register.
    assert row["INV_PAY_USD"] == 100.00
    assert row["INV_PAY_IDR"] == 1500000.00
    assert row["DNCN_PAY_USD"] == 150.00
    assert row["DNCN_PAY_IDR"] == 1500000.00


def test_paid_flag_set_from_invoice_lunas():
    """Paid must be True when INVOICE.LUNAS is 1."""
    _seed_join_data()
    from app import queries as q

    rows = q.get_recent_shipments()
    assert rows
    assert rows[0]["PAID"] == 1


def test_index_template_does_not_render_bl_no():
    """The dashboard card must not render BL No."""
    from pathlib import Path

    html = Path("src/app/templates/index.html").read_text(encoding="utf-8")
    card_section = html.split("Unpaid Shipments")[-1]
    assert "BLNO3" not in card_section, "BLNO3 still rendered in the dashboard card"


def test_index_template_renders_new_columns():
    """The dashboard card must show Opr Cost and Invoice headers (Paid was
    dropped because the card now lists unpaid shipments only)."""
    from pathlib import Path

    html = Path("src/app/templates/index.html").read_text(encoding="utf-8")
    card_section = html.split("Unpaid Shipments")[-1]
    for header in ("Opr Cost", "Invoice", "DNCN", "Search", "Previous", "Next"):
        assert header in card_section, f"missing header/control {header!r} in dashboard card"