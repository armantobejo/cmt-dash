"""Automated tests for A/R and A/P aging reports."""
from __future__ import annotations

import os
import sqlite3
from datetime import date, timedelta

import pytest

from app import aging_service as ag
from app.aging_buckets import BUCKETS, bucket_for


def test_bucket_boundaries():
    assert bucket_for(0) == "0-30"
    assert bucket_for(30) == "0-30"
    assert bucket_for(31) == "31-60"
    assert bucket_for(60) == "31-60"
    assert bucket_for(61) == "61-90"
    assert bucket_for(90) == "61-90"
    assert bucket_for(91) == "90+"
    assert bucket_for(365) == "90+"


def _seed_ar():
    conn = sqlite3.connect(os.environ["DATABASE_URL"])
    try:
        conn.executescript(
            "DELETE FROM INVOICE WHERE UNIK LIKE 'AG-%'; DELETE FROM INVOICE_R WHERE NOINVOICE LIKE 'AG-%';"
        )
        today = date.today()
        recent = (today - timedelta(days=5)).isoformat()
        old = (today - timedelta(days=45)).isoformat()
        conn.execute(
            "INSERT INTO INVOICE (UNIK, NOINVOICE, SHIPP_PIUT, DATEOFISSU, GRAND_USD, BYR_USD) VALUES (?,?,?,?,?,?)",
            ("AG-1", "AG-INV1", "ACME", recent, 100.0, 0.0),
        )
        conn.execute(
            "INSERT INTO INVOICE (UNIK, NOINVOICE, SHIPP_PIUT, DATEOFISSU, GRAND_USD, BYR_USD) VALUES (?,?,?,?,?,?)",
            ("AG-2", "AG-INV2", "BETA", old, 200.0, 50.0),
        )
        # zero balance invoice excluded by >0 filter
        conn.execute(
            "INSERT INTO INVOICE (UNIK, NOINVOICE, SHIPP_PIUT, DATEOFISSU, GRAND_USD, BYR_USD) VALUES (?,?,?,?,?,?)",
            ("AG-3", "AG-INV3", "ZERO", recent, 50.0, 50.0),
        )
        conn.commit()
    finally:
        conn.close()


def test_ar_report_buckets_totals_filters():
    _seed_ar()
    as_of = date.today().isoformat()
    rep = ag.get_aging_report("ar", as_of, company_filter="", min_amount=None, counterparty=None)
    assert rep["empty"] is False
    assert rep["total"] == 2
    assert rep["buckets"] == BUCKETS
    assert rep["totals"]["grand"] == 100.0 + 150.0
    assert rep["totals"]["0-30"] == 100.0
    assert rep["totals"]["31-60"] == 150.0
    # min_amount filter
    rep2 = ag.get_aging_report("ar", as_of, min_amount=120.0)
    assert rep2["total"] == 1
    # counterparty filter
    rep3 = ag.get_aging_report("ar", as_of, counterparty="acme")
    assert rep3["total"] == 1
    assert rep3["rows"][0]["counterparty"] == "ACME"


def test_ar_pagination():
    _seed_ar()
    as_of = date.today().isoformat()
    r = ag.get_aging_report("ar", as_of, per_page=1, page=1)
    assert r["pages"] == 2
    assert len(r["rows"]) == 1
    r2 = ag.get_aging_report("ar", as_of, per_page=1, page=2)
    assert len(r2["rows"]) == 1
    # page clamping
    r3 = ag.get_aging_report("ar", as_of, per_page=1, page=999)
    assert r3["page"] == 2


def test_empty_report():
    # clear
    conn = sqlite3.connect(os.environ["DATABASE_URL"])
    conn.executescript("DELETE FROM INVOICE WHERE UNIK LIKE 'AG-%'; DELETE FROM INVOICE_R WHERE NOINVOICE LIKE 'AG-%'; DELETE FROM OPRCOST; DELETE FROM OPRCOST_R; DELETE FROM DRCRFULL; DELETE FROM DRCRFULL_R;")
    conn.commit(); conn.close()
    as_of = date.today().isoformat()
    rep = ag.get_aging_report("ar", as_of)
    assert rep["empty"] is True
    assert rep["rows"] == []
    assert rep["totals"]["grand"] == 0.0
    rep2 = ag.get_aging_report("ap", as_of)
    assert rep2["empty"] is True


def test_ap_report_orphan_exclusion():
    conn = sqlite3.connect(os.environ["DATABASE_URL"])
    try:
        conn.executescript("DELETE FROM OPRCOST; DELETE FROM OPRCOST_R;")
        conn.execute("INSERT INTO OPRCOST (UNIK, NOMOR, TANGGAL, GRAND_USD, UOPRCOST) VALUES (?,?,?,?,?)",
                     ("O1", "OP1", date.today().isoformat(), 300.0, "U1"))
        # orphan row (no matching OPRCOST) excluded
        conn.execute("INSERT INTO OPRCOST_R (INVOICE_NO, INV_TGL, KD_SHIPLIN, JUMLAH, BAYAR, UOPRCOST) VALUES (?,?,?,?,?,?)",
                     ("ORPH", date.today().isoformat(), "CP", 50.0, 0.0, "NOPE"))
        # valid row
        conn.execute("INSERT INTO OPRCOST_R (INVOICE_NO, INV_TGL, KD_SHIPLIN, JUMLAH, BAYAR, UOPRCOST) VALUES (?,?,?,?,?,?)",
                     ("OPR1", date.today().isoformat(), "CP", 80.0, 10.0, "U1"))
        conn.commit()
    finally:
        conn.close()
    as_of = date.today().isoformat()
    rep = ag.get_aging_report("ap", as_of)
    nos = {r["document_no"] for r in rep["rows"]}
    assert "ORPH" not in nos
    assert "OPR1" in nos


@pytest.mark.parametrize("kind", ["ar", "ap"])
def test_page_rendering(client, kind):
    # login
    from app.auth import STORE, _hash
    import app.db as db
    db.init_schema()
    conn = db.get_db()
    try:
        conn.execute("INSERT OR IGNORE INTO users (username, display_name, role, pw_hash, salt, customer_company) VALUES (?,?,?,?,?,?)",
                     ("tester", "Tester", "staff", _hash("pw", "s"), "s", ""))
        conn.commit()
    finally:
        conn.close()
    resp = client.post("/login", data={"username": "tester", "password": "pw"}, follow_redirects=False)
    assert resp.status_code == 302
    c = client
    r = c.get(f"/aging/{kind}")
    assert r.status_code == 200


@pytest.mark.parametrize("kind", ["ar", "ap"])
def test_api_endpoint_requires_company(client, kind):
    # unauth — clear session cookie left by earlier login tests (client is session-scoped)
    c = client
    c.cookies.clear()
    r = c.get(f"/api/reports/aging/{kind}", follow_redirects=False)
    assert r.status_code in (303, 307, 401, 403)
