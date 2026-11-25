"""KG-5 / KG-6 regression tests.

KG-5: /invoices/detail 500s — q.get_invoice_detail was called (main.py) but
      never defined in queries.py. This pins the new implementation: header +
      line items, import/export customer-name branch, SATUAN lookup, 404-path,
      and the customer-role company guard (testable only now the page renders).
KG-6: customer-role dashboard 500s — get_summary(company_filter=...) built
      invalid SQL (missing ' AND ' separator before the company condition).
"""
from __future__ import annotations

import sqlite3

import pytest

from app import db
from app.auth import STORE, _hash

_PREFIX = "KGT-"  # distinctive seed prefix; clean-first keeps re-runs honest


# Table -> columns that can carry the KGT- prefix (only real columns queried).
_CLEANUP_PREDICATES = {
    "NA": ("UNIK",),
    "CARGO": ("UNIK",),
    "INVOICE": ("UNIK", "K_INVOICE"),
    "INVOICE_R": ("K_INVOICE",),
    "CONSIGNEE": ("KD_CONSIG",),
    "SHIPPER": ("KD_SHIPPER",),
    "SATUAN": ("KD_SATUAN",),
}


def _cleanup() -> None:
    """Remove every KGT- row. Teardown matters: the session DB is shared, and
    leftover rows would displace rows other test modules assert on."""
    conn = sqlite3.connect(db.DB_PATH)
    try:
        for table, cols in _CLEANUP_PREDICATES.items():
            conds = " OR ".join(f"{c} LIKE ?" for c in cols)
            conn.execute(f"DELETE FROM {table} WHERE {conds}", (_PREFIX + "%",) * len(cols))
        conn.commit()
    finally:
        conn.close()


def _seed() -> None:
    """One import shipment + one export shipment, each with an invoice + line."""
    _cleanup()
    conn = sqlite3.connect(db.DB_PATH)
    try:
        conn.executescript(
            f"""
            DELETE FROM NA WHERE UNIK LIKE '{_PREFIX}%';
            DELETE FROM CARGO WHERE UNIK LIKE '{_PREFIX}%';
            DELETE FROM INVOICE WHERE UNIK LIKE '{_PREFIX}%';
            DELETE FROM INVOICE_R WHERE K_INVOICE LIKE '{_PREFIX}%';
            DELETE FROM CONSIGNEE WHERE KD_CONSIG LIKE '{_PREFIX}%';
            DELETE FROM SHIPPER WHERE KD_SHIPPER LIKE '{_PREFIX}%';
            DELETE FROM SATUAN WHERE KD_SATUAN LIKE '{_PREFIX}%';

            INSERT INTO CONSIGNEE (KD_CONSIG, NAME) VALUES ('{_PREFIX}C1', 'PT MAJU JAYA');
            INSERT INTO SHIPPER (KD_SHIPPER, NAME) VALUES ('{_PREFIX}S1', 'ACME SHIP CO');

            -- import shipment (customer = consignee)
            INSERT INTO NA (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG, KD_SHIPPER)
                VALUES ('{_PREFIX}NA1', '{_PREFIX}RS1', '2026-09-01', 'I', '{_PREFIX}C1', '{_PREFIX}S1');
            -- export shipment (customer = shipper)
            INSERT INTO CARGO (UNIK, REFSHITMEN, DATE, TYPETRAN, KD_CONSIG, KD_SHIPPER)
                VALUES ('{_PREFIX}CG1', '{_PREFIX}RS2', '2026-09-02', 'E', '{_PREFIX}C1', '{_PREFIX}S1');

            INSERT INTO INVOICE (UNIK, K_INVOICE, NOINVOICE, REFSHITMEN, EI, DATEOFISSU,
                                 SHIPPER, EXRATE, GRAND_USD, GRAND_RP, BYR_USD, BYR_RP,
                                 DIRPKAN, DIUSDKAN, SELISIH_US, SELISIH_ID, LUNAS, REMARK,
                                 NOTEINV, BLNO, BLNO3, PORTOFLOAD, PORTOFDISC,
                                 VESSELNAME, ETDDATE, ETADATE)
                VALUES ('{_PREFIX}I1', '{_PREFIX}K1', 'INV-9001', '{_PREFIX}RS1', 'I', '2026-09-10',
                        '{_PREFIX}S1', 15500.0, 1200.0, 18600000.0, 1200.0, 18600000.0,
                        0.0, 0.0, 0.0, 0.0, 1, 'paid in full',
                        'cust note', 'BL-77', 'BL3-77', 'JKT', 'SIN',
                        'MV TEST', '2026-09-01', '2026-09-05');

            INSERT INTO INVOICE_R (K_INVOICE, NOINVOICE, NO, JENISCOST, DESCRIPTIO,
                                   CURRENCY, QTY, SELLING, JUMLAH, KD_SATUAN)
                VALUES ('{_PREFIX}K1', 'INV-9001', 1, 'FREIGHT', 'Ocean freight',
                        'USD', 1.0, 1200.0, 1200.0, '{_PREFIX}U1');
            INSERT INTO SATUAN (KD_SATUAN, SATUAN) VALUES ('{_PREFIX}U1', 'Pieces');
            """
        )
        conn.commit()
    finally:
        conn.close()


def _make_user(username, role="staff", company=None) -> None:
    db.init_schema()
    salt = f"{username}_salt"
    conn = db.get_db()
    try:
        conn.execute(
            "INSERT INTO users (username, display_name, role, pw_hash, salt, customer_company) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (username, username.title(), role, _hash("pw", salt), salt, company),
        )
        conn.commit()
    finally:
        conn.close()


def _login(client, username, password="pw"):
    return client.post(
        "/login",
        data={"username": username, "password": password},
        follow_redirects=False,
    )


# ---------------------------------------------------------------- KG-5 (unit)


def test_get_invoice_detail_header_and_items():
    _seed()
    from app import queries as q

    detail = q.get_invoice_detail(f"{_PREFIX}K1")
    assert detail is not None
    header = detail["header"]
    assert header["NOINVOICE"] == "INV-9001"
    # Import branch: customer name comes from the shipment's consignee
    assert header["CUSTOMER"] == "PT MAJU JAYA"
    assert header["VESSELNAME"] == "MV TEST"
    assert header["EXRATE"] == 15500.0
    items = detail["line_items"]
    assert len(items) == 1
    assert items[0]["DESCRIPTIO"] == "Ocean freight"
    assert items[0]["SATUAN_NAME"] == "Pieces"
    assert items[0]["JUMLAH"] == 1200.0


def test_get_invoice_detail_unknown_key_returns_none():
    from app import queries as q

    assert q.get_invoice_detail("no-such-invoice-key") is None


# ---------------------------------------------------------------- KG-5 (HTTP)


@pytest.fixture(scope="module", autouse=True)
def _leave_no_trace():
    """Yield-then-clean: the module's rows never outlive the module."""
    yield
    _cleanup()


@pytest.fixture(scope="module")
def detail_client():
    _seed()
    _make_user("kgt_staff", "staff")
    _make_user("kgt_cust_maju", "customer", company="MAJU")
    _make_user("kgt_cust_other", "customer", company="OTHERCO")
    from fastapi.testclient import TestClient
    from app.main import app

    with TestClient(app) as c:
        yield c


def test_invoice_detail_renders_for_staff(detail_client):
    c = detail_client
    resp = _login(c, "kgt_staff")
    assert resp.status_code in (302, 303), "login should redirect on success"
    resp = c.get(f"/invoices/detail?k={_PREFIX}K1")
    assert resp.status_code == 200
    assert "INV-9001" in resp.text


def test_invoice_detail_unknown_key_redirects(detail_client):
    c = detail_client
    _login(c, "kgt_staff")
    resp = c.get("/invoices/detail?k=does-not-exist", follow_redirects=False)
    assert resp.status_code == 303
    assert "/invoices" in resp.headers["location"]


def test_invoice_detail_customer_guard(detail_client):
    """Customer-role users: own company renders, other company redirects."""
    c = detail_client
    _login(c, "kgt_cust_maju")
    assert c.get(f"/invoices/detail?k={_PREFIX}K1").status_code == 200
    _login(c, "kgt_cust_other")
    resp = c.get(f"/invoices/detail?k={_PREFIX}K1", follow_redirects=False)
    assert resp.status_code == 303
    assert "/invoices" in resp.headers["location"]


# ---------------------------------------------------------------- KG-6 (unit)


def test_get_summary_company_filter_no_sql_error():
    """get_summary(company_filter=...) used to produce invalid SQL (KG-6)."""
    _seed()
    from app import queries as q

    summary = q.get_summary(company_filter="MAJU")
    assert summary["shipments"] >= 1
    assert summary["imports"] >= 1
    assert summary["exports"] >= 1
    assert summary["invoices"] >= 1


# ---------------------------------------------------------------- KG-6 (HTTP)


def test_customer_dashboard_no_500(detail_client):
    """Customer-role dashboard used to 500 on the filtered summary (KG-6)."""
    c = detail_client
    _login(c, "kgt_cust_maju")
    resp = c.get("/")
    assert resp.status_code == 200
