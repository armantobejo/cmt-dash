"""Tests for the Reference/Listing report pages (F-28): agents, shippers, consignees."""
from __future__ import annotations


def _login(client):
    from app.auth import _hash
    import app.db as db
    db.init_schema()
    conn = db.get_db()
    try:
        conn.execute(
            "INSERT OR IGNORE INTO users (username, display_name, role, pw_hash, salt, customer_company) "
            "VALUES (?,?,?,?,?,?)",
            ("tester", "Tester", "staff", _hash("pw", "s"), "s", ""),
        )
        conn.commit()
    finally:
        conn.close()
    resp = client.post("/login", data={"username": "tester", "password": "pw"}, follow_redirects=False)
    assert resp.status_code == 302


def _seed(conn):
    conn.execute("DELETE FROM AGENT")
    conn.execute("DELETE FROM SHIPPER")
    conn.execute("DELETE FROM CONSIGNEE")
    conn.execute(
        "INSERT INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
        ("A01", "Alpha Agen", "PT Alpha", "INV01", "RATE1", "4001", "4002", "021-1", "021-2", "John"),
    )
    conn.execute(
        "INSERT INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
        ("A02", "Beta Agen", "PT Beta", "INV02", "RATE2", "4002", "4003", "021-3", "021-4", "Jane"),
    )
    conn.execute(
        "INSERT INTO SHIPPER (KD_SHIPPER, NAME, KD_BU, TELP, FAX, KD_JNSCUST, TERM, KD_ACCOUNT, NPWP) VALUES (?,?,?,?,?,?,?,?,?)",
        ("S01", "Shipper One", "BU1", "021-5", "021-6", "JNS1", "30", "5001", "12.345.678.9-012"),
    )
    conn.execute(
        "INSERT INTO CONSIGNEE (KD_CONSIG, NAME, ADDRESS1, ADDRESS2, ADDRESS3, TELP, FAX) VALUES (?,?,?,?,?,?,?)",
        ("C01", "Consignee One", "Addr 1", "Addr 2", "Addr 3", "021-7", "021-8"),
    )
    conn.commit()


def test_reference_pages_render(client):
    _login(client)
    import app.db as db
    conn = db.get_db()
    try:
        _seed(conn)
    finally:
        conn.close()

    for path, marker in (("/agents", "A01"), ("/shippers", "S01"), ("/consignees", "C01")):
        r = client.get(path)
        assert r.status_code == 200, path
        assert marker in r.text, f"{path} missing seeded row {marker}"
        # sortable headers link back with sort/dir params
        assert "sort=code" in r.text, f"{path} missing sort links"


def test_agents_sort_by_name(client):
    _login(client)
    import app.db as db
    conn = db.get_db()
    try:
        _seed(conn)
    finally:
        conn.close()

    asc = client.get("/agents", params={"sort": "name", "dir": "asc"})
    desc = client.get("/agents", params={"sort": "name", "dir": "desc"})
    assert asc.status_code == 200 and desc.status_code == 200
    assert asc.text.index("Alpha Agen") < asc.text.index("Beta Agen")
    assert desc.text.index("Alpha Agen") > desc.text.index("Beta Agen")


def test_agents_search_filters(client):
    _login(client)
    import app.db as db
    conn = db.get_db()
    try:
        _seed(conn)
    finally:
        conn.close()

    r = client.get("/agents", params={"q": "Beta"})
    assert r.status_code == 200
    assert "Beta Agen" in r.text
    assert "Alpha Agen" not in r.text


def test_agents_rejects_unknown_sort_column(client):
    """Sort allow-list must fall back, never reflect raw input into SQL."""
    _login(client)
    r = client.get("/agents", params={"sort": "CODE; DROP TABLE AGENT--", "dir": "asc"})
    assert r.status_code == 200


def test_reference_pages_require_login():
    from fastapi.testclient import TestClient
    from app.main import app

    with TestClient(app) as fresh:
        for path in ("/agents", "/shippers", "/consignees"):
            r = fresh.get(path, follow_redirects=False)
            assert r.status_code == 303, path
