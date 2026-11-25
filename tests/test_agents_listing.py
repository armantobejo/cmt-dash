import pytest
import sqlite3

@pytest.fixture
def logged_in(client):
    from app.auth import _hash
    import app.db as db
    db.init_schema()
    conn = db.get_db()
    try:
        conn.execute(
            "INSERT OR IGNORE INTO users (username, display_name, role, pw_hash, salt, customer_company) VALUES (?,?,?,?,?,?)",
            ("tester", "Tester", "staff", _hash("pw", "s"), "s", ""),
        )
        conn.commit()
    finally:
        conn.close()
    resp = client.post("/login", data={"username": "tester", "password": "pw"}, follow_redirects=False)
    assert resp.status_code == 302
    return client


def _seed_agents(conn):
    conn.execute(
        "INSERT INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
        ("A01", "Alpha Agen", "PT Alpha", "INV01", "RATE1", "4001", "4002", "021-1", "021-2", "John"),
    )
    conn.execute(
        "INSERT INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
        ("A02", "Beta Agen", "PT Beta", "INV02", "RATE2", "4002", "4003", "021-3", "021-4", "Jane"),
    )
    conn.commit()


@pytest.fixture(autouse=True)
def _clean_tables():
    """Isolate tests: the DB handle is session-scoped, so wipe the AGENT table."""
    import app.db as db
    conn = db.get_db()
    try:
        conn.execute("DELETE FROM AGENT")
        conn.commit()
    finally:
        conn.close()


@pytest.fixture
def seeded_agents(logged_in):
    """2 agents for testing."""
    import app.db as db
    conn = db.get_db()
    try:
        _seed_agents(conn)
        conn.commit()
    finally:
        conn.close()
    return logged_in


class TestAgentsListingPage:
    def test_page_renders(self, seeded_agents):
        seeded_agents.cookies.set("cmt_lang", "en")
        r = seeded_agents.get("/agents")
        assert r.status_code == 200
        assert "List Agent" in r.text
        assert "2 rows" in r.text
        assert "Alpha Agen" in r.text
        assert "Beta Agen" in r.text
        assert "PT Alpha" in r.text
        assert "PT Beta" in r.text
        assert "4001" in r.text
        assert "4002" in r.text

    def test_page_requires_login(self, client):
        client.cookies.clear()
        r = client.get("/agents", follow_redirects=False)
        assert r.status_code in (303, 307, 401, 403)

    def test_page_renders_with_search_param(self, seeded_agents):
        r = seeded_agents.get("/agents", params={"q": "Alpha"})
        assert r.status_code == 200
        assert "Alpha Agen" in r.text
        assert "Beta Agen" not in r.text

    def test_page_renders_with_sort_and_page(self, seeded_agents):
        r = seeded_agents.get("/agents", params={"sort": "name", "dir": "asc", "page": 1})
        assert r.status_code == 200
        assert "Alpha Agen" in r.text
        assert "Beta Agen" in r.text

    def test_page_renders_id_language(self, seeded_agents):
        seeded_agents.cookies.set("cmt_lang", "id")
        assert seeded_agents.get("/agents").status_code == 200

    def test_page_renders_zh_language(self, seeded_agents):
        seeded_agents.cookies.set("cmt_lang", "zh")
        assert seeded_agents.get("/agents").status_code == 200

    def test_sort_by_code_asc(self, seeded_agents):
        r = seeded_agents.get("/agents", params={"sort": "code", "dir": "asc"})
        assert r.status_code == 200
        idx_a01 = r.text.index("A01")
        idx_a02 = r.text.index("A02")
        assert idx_a01 < idx_a02

    def test_sort_by_code_desc(self, seeded_agents):
        r = seeded_agents.get("/agents", params={"sort": "code", "dir": "desc"})
        assert r.status_code == 200
        idx_a01 = r.text.index("A01")
        idx_a02 = r.text.index("A02")
        assert idx_a02 < idx_a01

    def test_sort_by_name_asc(self, seeded_agents):
        r = seeded_agents.get("/agents", params={"sort": "name", "dir": "asc"})
        assert r.status_code == 200
        idx_alpha = r.text.index("Alpha Agen")
        idx_beta = r.text.index("Beta Agen")
        assert idx_alpha < idx_beta

    def test_sort_by_name_desc(self, seeded_agents):
        r = seeded_agents.get("/agents", params={"sort": "name", "dir": "desc"})
        assert r.status_code == 200
        idx_alpha = r.text.index("Alpha Agen")
        idx_beta = r.text.index("Beta Agen")
        assert idx_beta < idx_alpha

    def test_sort_default_is_name_asc(self, seeded_agents):
        r = seeded_agents.get("/agents")
        assert r.status_code == 200
        idx_alpha = r.text.index("Alpha Agen")
        idx_beta = r.text.index("Beta Agen")
        assert idx_alpha < idx_beta
        assert r.text.count("Alpha Agen") == 1

    def test_search_case_insensitive(self, seeded_agents):
        assert seeded_agents.get("/agents", params={"q": "a01"}).text.count("Alpha Agen") == 1
        assert seeded_agents.get("/agents", params={"q": "ALPHA"}).text.count("Alpha Agen") == 1

    def test_search_empty_returns_all(self, seeded_agents):
        assert seeded_agents.get("/agents", params={"q": ""}).text.count("Alpha Agen") == 1
        assert seeded_agents.get("/agents", params={"q": ""}).text.count("Beta Agen") == 1

    def test_search_whitespace_returns_all(self, seeded_agents):
        assert seeded_agents.get("/agents", params={"q": "   "}).text.count("Alpha Agen") == 1
        assert seeded_agents.get("/agents", params={"q": "   "}).text.count("Beta Agen") == 1

    def test_search_no_results(self, seeded_agents):
        seeded_agents.cookies.set("cmt_lang", "en")
        r = seeded_agents.get("/agents", params={"q": "ZZZZZZ"})
        assert "No data" in r.text
        assert "Alpha Agen" not in r.text
        assert "Beta Agen" not in r.text

    def test_pagination_second_page(self, seeded_agents):
        seeded_agents.cookies.set("cmt_lang", "en")
        import app.db as db
        conn = db.get_db()
        try:
            # Insert 60 more agents so we have >50 rows (2+ pages at per_page=50)
            for i in range(3, 63):
                conn.execute(
                    "INSERT INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
                    (f"A{i:02d}", f"Agent {i}", f"PT {i}", f"INV{i}", f"RATE{i}", "4000", "4000", "021-0", "021-0", "Test"),
                )
            conn.commit()
        finally:
            conn.close()

        # Page 1
        r1 = seeded_agents.get("/agents", params={"page": 1})
        assert r1.status_code == 200
        assert "Page 1" in r1.text

        # Page 2
        r2 = seeded_agents.get("/agents", params={"page": 2})
        assert r2.status_code == 200
        assert "Page 2" in r2.text

        # Page 999 should clamp to last page (62 agents / 25 per page = 3 pages)
        r3 = seeded_agents.get("/agents", params={"page": 999})
        assert r3.status_code == 200
        assert "Page 3" in r3.text  # clamps to last page at per_page=25

    def test_pagination_no_duplicates_across_pages(self, seeded_agents):
        import app.db as db
        conn = db.get_db()
        try:
            for i in range(3, 6):
                conn.execute(
                    "INSERT INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
                    (f"A{i:02d}", f"Agent {i}", f"PT {i}", f"INV{i}", f"RATE{i}", "4000", "4000", "021-0", "021-0", "Test"),
                )
            conn.commit()
        finally:
            conn.close()

        p1 = seeded_agents.get("/agents", params={"page": 1, "per_page": 2}).text
        p2 = seeded_agents.get("/agents", params={"page": 2, "per_page": 2}).text
        p3 = seeded_agents.get("/agents", params={"page": 3, "per_page": 2}).text

        all_codes = set()
        for page_text in [p1, p2, p3]:
            for line in page_text.split("\n"):
                if "font-monospace" in line:
                    code = line.split(">")[1].split("<")[0]
                    all_codes.add(code)

        assert len(all_codes) == 5

    def test_empty_state(self, logged_in):
        logged_in.cookies.set("cmt_lang", "en")
        r = logged_in.get("/agents")
        assert r.status_code == 200
        assert "No data" in r.text

    def test_db_error_returns_500(self, logged_in, monkeypatch):
        import app.queries as q
        from fastapi.testclient import TestClient
        from app.main import app

        def boom(*a, **k):
            raise sqlite3.OperationalError("boom")
        monkeypatch.setattr(q, "query", boom)
        nc = TestClient(app, raise_server_exceptions=False)
        nc.cookies = logged_in.cookies
        r = nc.get("/agents")
        assert r.status_code == 500

    def test_db_error_on_count_returns_500(self, logged_in, monkeypatch):
        import app.queries as q
        from fastapi.testclient import TestClient
        from app.main import app

        def boom(*a, **k):
            raise sqlite3.OperationalError("boom")
        monkeypatch.setattr(q, "query_one", boom)
        nc = TestClient(app, raise_server_exceptions=False)
        nc.cookies = logged_in.cookies
        r = nc.get("/agents")
        assert r.status_code == 500


class TestAgentsListingContract:
    """Data contract: every field displayed matches the corresponding source field."""

    def test_code_field_matches(self, seeded_agents):
        r = seeded_agents.get("/agents")
        assert "font-monospace" in r.text
        assert "A01" in r.text
        assert "A02" in r.text

    def test_name_field_matches(self, seeded_agents):
        r = seeded_agents.get("/agents")
        assert "Alpha Agen" in r.text
        assert "Beta Agen" in r.text

    def test_agent_field_matches(self, seeded_agents):
        r = seeded_agents.get("/agents")
        assert "PT Alpha" in r.text
        assert "PT Beta" in r.text

    def test_telp_field_matches(self, seeded_agents):
        r = seeded_agents.get("/agents")
        assert "021-1" in r.text
        assert "021-3" in r.text

    def test_kd_account_field_matches(self, seeded_agents):
        r = seeded_agents.get("/agents")
        assert "4001" in r.text
        assert "4002" in r.text