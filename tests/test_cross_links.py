"""Cross-link tests between F-28 listing (agents) and F-29 reference (performance)."""
from __future__ import annotations

from app.auth import _hash
import app.db as db


def _login(client):
    """Log in as test user."""
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


def _seed_agents(conn):
    """Seed test agents."""
    conn.execute("DELETE FROM AGENT")
    conn.execute(
        "INSERT INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
        ("A01", "Alpha Agen", "PT Alpha", "INV01", "RATE1", "4001", "4002", "021-1", "021-2", "John"),
    )
    conn.execute(
        "INSERT INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
        ("A02", "Beta Agen", "PT Beta", "INV02", "RATE2", "4002", "4003", "021-3", "021-4", "Jane"),
    )
    conn.commit()


def test_cross_link_agents_to_performance(client):
    """From agents page, agent link navigates to performance page with correct group_by."""
    _login(client)
    client.cookies.set("cmt_lang", "en")

    conn = db.get_db()
    try:
        _seed_agents(conn)
    finally:
        conn.close()

    agents_resp = client.get("/agents")
    assert agents_resp.status_code == 200
    assert "PT Alpha" in agents_resp.text
    assert "PT Beta" in agents_resp.text
    assert '/performance?group_by=agent' in agents_resp.text

    perf_resp = client.get("/performance", params={"group_by": "agent"})
    assert perf_resp.status_code == 200
    assert "Performance Tracker" in perf_resp.text
    assert "Grouped by dimension" in perf_resp.text
    assert "By Agent" in perf_resp.text
    assert "Back to Agents" in perf_resp.text


def test_cross_link_performance_to_agents(client):
    """From performance page (grouped by agent), back link returns to agents page."""
    _login(client)
    client.cookies.set("cmt_lang", "en")

    perf_resp = client.get("/performance", params={"group_by": "agent"})
    assert perf_resp.status_code == 200
    assert "Performance Tracker" in perf_resp.text
    assert "By Agent" in perf_resp.text
    assert "Back to Agents" in perf_resp.text

    back_resp = client.get("/agents")
    assert back_resp.status_code == 200
    assert "List Agent" in back_resp.text


def test_cross_link_preserves_state(client):
    """Cross-links preserve listing state where the app supports it."""
    _login(client)
    client.cookies.set("cmt_lang", "en")

    conn = db.get_db()
    try:
        _seed_agents(conn)
    finally:
        conn.close()

    agents_resp = client.get("/agents", params={"q": "Alpha", "sort": "name", "dir": "asc"})
    assert agents_resp.status_code == 200
    assert "Alpha Agen" in agents_resp.text
    assert "Beta Agen" not in agents_resp.text

    perf_resp = client.get("/performance", params={"group_by": "agent"})
    assert perf_resp.status_code == 200

    back_resp = client.get("/agents")
    assert back_resp.status_code == 200
    assert "List Agent" in back_resp.text