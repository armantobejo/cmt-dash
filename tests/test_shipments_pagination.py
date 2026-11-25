"""Pagination/search/sort for the /shipments listing + customer company filter."""
from __future__ import annotations

import os
import sqlite3

from app import queries as q


def _seed_shipments():
    """Four shipments under the SP- prefix; two belong to company TRISCO.

    The query joins CONSIGNEE/SHIPPER lookup tables via KD_CONSIG/KD_SHIPPER,
    so we seed those tables and wire the codes into the NA rows.
    Import rows (I) use KD_CONSIG for the customer; export/domestic use KD_SHIPPER.
    """
    conn = sqlite3.connect(os.environ["DATABASE_URL"])
    try:
        conn.executescript(
            """
            DELETE FROM NA WHERE UNIK LIKE 'SP-%';
            DELETE FROM CONSIGNEE WHERE KD_CONSIG IN ('SPC1','SPC2','SPC3','SPC4');
            DELETE FROM SHIPPER WHERE KD_SHIPPER IN ('SPS1','SPS2','SPS3','SPS4');
            INSERT INTO CONSIGNEE (KD_CONSIG, NAME) VALUES ('SPC1', 'PT ALPHA');
            INSERT INTO CONSIGNEE (KD_CONSIG, NAME) VALUES ('SPC2', 'PT TRISCO JAYA');
            INSERT INTO CONSIGNEE (KD_CONSIG, NAME) VALUES ('SPC3', 'PT GAMMA');
            INSERT INTO CONSIGNEE (KD_CONSIG, NAME) VALUES ('SPC4', 'PT EPSILON');
            INSERT INTO SHIPPER (KD_SHIPPER, NAME) VALUES ('SPS1', 'PT ALPHA');
            INSERT INTO SHIPPER (KD_SHIPPER, NAME) VALUES ('SPS2', 'PT TRISCO JAYA');
            INSERT INTO SHIPPER (KD_SHIPPER, NAME) VALUES ('SPS3', 'PT GAMMA');
            INSERT INTO SHIPPER (KD_SHIPPER, NAME) VALUES ('SPS4', 'PT EPSILON');
            -- SP-1: Import (customer from CONSIGNEE = PT ALPHA)
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG, KD_SHIPPER, BLNO3)
                VALUES ('SP-1', 'SP-001', '2026-08-10', 'I', 'SPC1', 'SPS1', 'BL-1');
            -- SP-2: Export (customer from SHIPPER = PT TRISCO JAYA)
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG, KD_SHIPPER, BLNO3)
                VALUES ('SP-2', 'SP-002', '2026-08-09', 'E', 'SPC2', 'SPS2', 'BL-2');
            -- SP-3: Import (customer from CONSIGNEE = PT GAMMA)
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG, KD_SHIPPER, BLNO3)
                VALUES ('SP-3', 'SP-003', '2026-08-08', 'I', 'SPC3', 'SPS3', 'BL-3');
            -- SP-4: Domestic (customer from SHIPPER = PT TRISCO JAYA)
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG, KD_SHIPPER, BLNO3)
                VALUES ('SP-4', 'SP-004', '2026-08-07', 'D', 'SPC4', 'SPS2', 'BL-4');
            """
        )
        conn.commit()
    finally:
        conn.close()


def test_shipments_pagination_offsets():
    _seed_shipments()
    page1 = q.get_shipments(search="SP-", per_page=2, page=1)
    page2 = q.get_shipments(search="SP-", per_page=2, page=2)
    assert page1["total"] == 4 and page1["pages"] == 2
    assert [r["REFSHITMEN"] for r in page1["rows"]] == ["SP-001", "SP-002"]
    assert [r["REFSHITMEN"] for r in page2["rows"]] == ["SP-003", "SP-004"]


def test_shipments_search_matches_reference_customer_and_bl():
    _seed_shipments()
    by_ref = q.get_shipments(search="SP-003")
    assert [r["REFSHITMEN"] for r in by_ref["rows"]] == ["SP-003"]
    by_customer = q.get_shipments(search="gamma")
    assert [r["REFSHITMEN"] for r in by_customer["rows"]] == ["SP-003"]
    by_bl = q.get_shipments(search="BL-2")
    assert [r["REFSHITMEN"] for r in by_bl["rows"]] == ["SP-002"]
    none = q.get_shipments(search="ZZZ-NOPE")
    assert none["total"] == 0 and none["rows"] == []


def test_shipments_sort_and_direction():
    _seed_shipments()
    asc = q.get_shipments(search="SP-", sort="reference", sort_dir="asc")
    assert [r["REFSHITMEN"] for r in asc["rows"]] == ["SP-001", "SP-002", "SP-003", "SP-004"]
    desc = q.get_shipments(search="SP-", sort="reference", sort_dir="desc")
    assert [r["REFSHITMEN"] for r in desc["rows"]] == ["SP-004", "SP-003", "SP-002", "SP-001"]
    # Sort by customer name
    by_cust = q.get_shipments(search="SP-", sort="customer", sort_dir="asc")
    customers = [r["CUSTOMER"] or '' for r in by_cust["rows"]]
    assert customers == sorted(customers)


def test_shipments_customer_company_filter():
    """Customer role must only see rows matching their company."""
    _seed_shipments()
    # SP-002 (export) and SP-004 (domestic) use KD_SHIPPER for customer → PT TRISCO JAYA
    # SP-001 (import) uses KD_CONSIG → PT ALPHA, so it does NOT match
    rows = q.get_shipments(company_filter="TRISCO")["rows"]
    assert {r["REFSHITMEN"] for r in rows} == {"SP-002", "SP-004"}
    # Search and company filter combine.
    rows = q.get_shipments(company_filter="TRISCO", search="SP-002")["rows"]
    assert [r["REFSHITMEN"] for r in rows] == ["SP-002"]


def test_shipments_returns_financial_columns():
    """Shipments rows should include the same financial columns as the unpaid card."""
    _seed_shipments()
    rows = q.get_shipments(search="SP-001")["rows"]
    assert len(rows) == 1
    row_keys = set(rows[0].keys())
    expected = {"OPR_USD", "OPR_RP", "INV_USD", "INV_RP", "PAID",
                "INV_PAY_USD", "INV_PAY_IDR", "DNCN_USD", "DNCN_IDR",
                "DNCN_PAY_USD", "DNCN_PAY_IDR"}
    missing = expected - row_keys
    assert not missing, f"Missing columns: {missing}"
