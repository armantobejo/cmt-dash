"""Rate by Destination report page + API contract tests (F-28 row 5)."""
import pytest
import sqlite3


@pytest.fixture
def logged_in(client):
    from app.auth import STORE, _hash
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


def _seed_quotation(conn, unik, quota, tangg, shipper="SHP1"):
    conn.execute(
        "INSERT INTO QUOTATION (UNIK, KD_QUOTA, TANGGAL, EXPIRED, KD_SHIPPER) VALUES (?,?,?,?,?)",
        (unik, quota, tangg, "2027-12-31", shipper),
    )


def _seed_quot_r(conn, unik, agent, city, desc, lcl=10.0, minv=20.0, d20=30.0, d40=40.0, d40hq=50.0, d45=60.0):
    conn.execute(
        "INSERT INTO QUOTATION_R (UNIK, CODE_AGENT, KD_CITY, DESC_CITY, LCL, MIN, D20, D40, D40HQ, D45, PRINT) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        (unik, agent, city, desc, lcl, minv, d20, d40, d40hq, d45, "Y"),
    )


def _seed_agent(conn, agent_code, city_code, name):
    """AGENT: AGENT=agent_code, CODE=city_code (JOIN a.AGENT=r.CODE_AGENT AND a.CODE=r.KD_CITY)."""
    conn.execute(
        "INSERT OR IGNORE INTO AGENT (CODE, NAME, AGENT, AGENTINV, RATE, KD_ACCOUNT, ACCT_CR, TELP, FAX, PIC) VALUES (?,?,?,?,?,?,?,?,?,?)",
        (city_code, name, agent_code, "", "", "", "", "", "", ""),
    )


@pytest.fixture(autouse=True)
def _clean_tables():
    """Isolate tests: the DB handle is session-scoped, so wipe the three tables."""
    import app.db as db
    conn = db.get_db()
    try:
        for t in ("QUOTATION_R", "QUOTATION", "AGENT"):
            conn.execute(f"DELETE FROM {t}")
        conn.commit()
    finally:
        conn.close()


@pytest.fixture
def seeded_rate_dest(logged_in):
    """5 agents x 3 cities = 15 unique agent x city pairs."""
    import app.db as db
    conn = db.get_db()
    try:
        agents = [f"AG {i:03d}" for i in range(5)]
        cities = [f"CITY {j:03d}" for j in range(3)]
        for i, agent in enumerate(agents):
            for city in cities:
                _seed_agent(conn, agent, city, f"Agent {i}")
        for i, agent in enumerate(agents):
            for j, city in enumerate(cities):
                unik = f"QUO{i}{j}"
                _seed_quotation(conn, unik, f"QUOTA{i}{j}", f"2026-{j + 1:02d}-15", f"SHP{i}")
                _seed_quot_r(conn, unik, agent, city, f"{city} Name",
                             10.0 + i * 10 + j, 20.0, 30.0, 40.0, 50.0, 60.0)
        conn.commit()
    finally:
        conn.close()
    return logged_in


@pytest.fixture
def seeded_unresolved(logged_in):
    """QUOTATION_R row with no matching AGENT -> unresolved_agent=1."""
    import app.db as db
    conn = db.get_db()
    try:
        _seed_quotation(conn, "QUNRES", "QUOTA-UNRES", "2026-01-01", "SHP1")
        _seed_quot_r(conn, "QUNRES", "NOPE", "NO CITY", "Unknown",
                     100.0, 200.0, 300.0, 400.0, 500.0, 600.0)
        conn.commit()
    finally:
        conn.close()
    return logged_in


@pytest.fixture
def seeded_desc_null(logged_in):
    """DESC_CITY NULL / empty -> city_label falls back to KD_CITY."""
    import app.db as db
    conn = db.get_db()
    try:
        _seed_agent(conn, "AGX", "CITYX", "Agent X")
        _seed_agent(conn, "AGX", "CITYY", "Agent X")
        _seed_quotation(conn, "QN1", "QUOTA-N1", "2026-01-01", "SHP1")
        _seed_quot_r(conn, "QN1", "AGX", "CITYX", None, 10.0, 20.0, 30.0, 40.0, 50.0, 60.0)
        _seed_quotation(conn, "QN2", "QUOTA-N2", "2026-02-01", "SHP1")
        _seed_quot_r(conn, "QN2", "AGX", "CITYY", "", 15.0, 25.0, 35.0, 45.0, 55.0, 65.0)
        conn.commit()
    finally:
        conn.close()
    return logged_in


@pytest.fixture
def seeded_pages(logged_in):
    """25 distinct agent x city pairs -> 3 pages at per_page=10."""
    import app.db as db
    conn = db.get_db()
    try:
        for i in range(25):
            agent = f"AG {i:03d}"
            city = f"CITY {i:03d}"
            _seed_agent(conn, agent, city, f"Agent {i}")
            unik = f"QP{i:03d}"
            _seed_quotation(conn, unik, unik, "2026-01-15", "SHP1")
            _seed_quot_r(conn, unik, agent, city, f"{city} Name",
                         float(i), 20.0, 30.0, 40.0, 50.0, 60.0)
        conn.commit()
    finally:
        conn.close()
    return logged_in


@pytest.fixture
def seeded_multi_quotations(logged_in):
    """Two quotations for one pair -> only the latest by TANGGAL appears."""
    import app.db as db
    conn = db.get_db()
    try:
        agent, city = "AGMULTI", "CITYMULTI"
        _seed_agent(conn, agent, city, "Multi Agent")
        _seed_quotation(conn, "QOLD", "QUOTA-OLD", "2025-01-01", "SHP1")
        _seed_quot_r(conn, "QOLD", agent, city, "Old Rate", 10.0, 20.0, 30.0, 40.0, 50.0, 60.0)
        _seed_quotation(conn, "QNEW", "QUOTA-NEW", "2026-01-01", "SHP1")
        _seed_quot_r(conn, "QNEW", agent, city, "New Rate", 999.0, 20.0, 30.0, 40.0, 50.0, 60.0)
        conn.commit()
    finally:
        conn.close()
    return logged_in


class TestRateByDestinationPage:
    def test_page_renders(self, logged_in):
        logged_in.cookies.set("cmt_lang", "en")
        r = logged_in.get("/rate-by-destination")
        assert r.status_code == 200
        assert "LCL" in r.text

    def test_page_requires_login(self, client):
        client.cookies.clear()
        r = client.get("/rate-by-destination", follow_redirects=False)
        assert r.status_code in (303, 307, 401, 403)

    def test_page_renders_with_search_param(self, logged_in):
        r = logged_in.get("/rate-by-destination", params={"search": "surabaya"})
        assert r.status_code == 200
        assert 'name="search"' in r.text

    def test_page_renders_with_sort_and_page(self, logged_in):
        r = logged_in.get("/rate-by-destination",
                          params={"sort": "city", "sort_dir": "desc", "page": 2, "per_page": 25})
        assert r.status_code == 200

    def test_page_renders_id_language(self, logged_in):
        logged_in.cookies.set("cmt_lang", "id")
        assert logged_in.get("/rate-by-destination").status_code == 200

    def test_page_renders_zh_language(self, logged_in):
        logged_in.cookies.set("cmt_lang", "zh")
        assert logged_in.get("/rate-by-destination").status_code == 200


class TestRateByDestinationAPI:
    def _call(self, client, **params):
        return client.get("/api/reports/rate-by-destination", params=params)

    # ── Empty state ──
    def test_contract_shape_empty(self, logged_in):
        r = self._call(logged_in)
        assert r.status_code == 200
        data = r.json()
        assert set(data.keys()) == {"rows", "total", "page", "per_page", "pages"}
        assert isinstance(data["rows"], list)
        assert data["total"] == 0
        assert data["page"] == 1
        assert data["per_page"] == 50
        assert data["pages"] == 1

    def test_empty_state_no_rows(self, logged_in):
        data = self._call(logged_in, page=1, per_page=10).json()
        assert data["rows"] == []
        assert data["total"] == 0

    def test_empty_state_with_search(self, logged_in):
        data = self._call(logged_in, search="nothing").json()
        assert data["total"] == 0
        assert data["rows"] == []

    # ── Happy path ──
    def test_happy_path_returns_rows(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest).json()
        assert data["rows"]
        assert data["total"] == 15
        assert data["page"] == 1
        assert data["per_page"] == 50
        assert data["pages"] == 1

    def test_happy_path_all_columns_present(self, seeded_rate_dest):
        row = self._call(seeded_rate_dest).json()["rows"][0]
        for col in ("UNIK", "CODE_AGENT", "KD_CITY", "DESC_CITY", "LCL", "MIN",
                    "D20", "D40", "D40HQ", "D45", "PRINT", "KD_QUOTA", "TANGGAL",
                    "EXPIRED", "KD_SHIPPER", "agent_name", "unresolved_agent", "city_label"):
            assert col in row, f"missing column {col}"

    def test_happy_path_one_row_per_agent_city(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest).json()
        pairs = {(r["CODE_AGENT"], r["KD_CITY"]) for r in data["rows"]}
        assert len(pairs) == 15 == data["total"]

    def test_happy_path_agent_name_resolved(self, seeded_rate_dest):
        for row in self._call(seeded_rate_dest).json()["rows"]:
            assert row["agent_name"].startswith("Agent ")
            assert row["unresolved_agent"] == 0

    def test_happy_path_city_label_from_desc_city(self, seeded_rate_dest):
        for row in self._call(seeded_rate_dest).json()["rows"]:
            assert row["city_label"] == f"{row['KD_CITY']} Name"

    def test_happy_path_rate_values_numeric(self, seeded_rate_dest):
        for row in self._call(seeded_rate_dest).json()["rows"]:
            for col in ("LCL", "MIN", "D20", "D40", "D40HQ", "D45"):
                assert isinstance(row[col], (int, float)), f"{col} not numeric"
                assert row[col] > 0

    def test_happy_path_metadata_fields_present(self, seeded_rate_dest):
        for row in self._call(seeded_rate_dest).json()["rows"]:
            assert row["TANGGAL"]
            assert row["EXPIRED"]
            assert row["KD_QUOTA"]
            assert row["UNIK"].startswith("QUO")
            assert row["KD_SHIPPER"].startswith("SHP")
            assert row["PRINT"] == "Y"

    # ── Latest quotation wins ──
    def test_latest_quotation_wins(self, seeded_multi_quotations):
        data = self._call(seeded_multi_quotations).json()
        assert data["total"] == 1
        row = data["rows"][0]
        assert row["UNIK"] == "QNEW"
        assert row["KD_QUOTA"] == "QUOTA-NEW"
        assert row["TANGGAL"] == "2026-01-01"
        assert row["LCL"] == 999.0

    # ── Unresolved agent ──
    def test_unresolved_agent_flag(self, seeded_unresolved):
        data = self._call(seeded_unresolved).json()
        assert data["total"] == 1
        row = data["rows"][0]
        assert row["agent_name"] is None
        assert row["unresolved_agent"] == 1
        assert row["CODE_AGENT"] == "NOPE"

    # ── City label fallback ──
    def test_city_label_fallback_null_and_empty(self, seeded_desc_null):
        data = self._call(seeded_desc_null).json()
        assert data["total"] == 2
        for row in data["rows"]:
            assert row["city_label"] == row["KD_CITY"]

    # ── Pagination ──
    def test_pagination_params(self, seeded_pages):
        data = self._call(seeded_pages, page=2, per_page=10).json()
        assert data["page"] == 2
        assert data["per_page"] == 10
        assert data["total"] == 25
        assert data["pages"] == 3
        assert len(data["rows"]) == 10

    def test_pagination_last_page_partial(self, seeded_pages):
        data = self._call(seeded_pages, page=3, per_page=10).json()
        assert data["page"] == 3
        assert len(data["rows"]) == 5

    def test_pagination_page_clamping(self, seeded_pages):
        data = self._call(seeded_pages, page=999, per_page=10).json()
        assert data["page"] == 3

    def test_pagination_no_duplicates_across_pages(self, seeded_pages):
        p1 = self._call(seeded_pages, page=1, per_page=10).json()["rows"]
        p2 = self._call(seeded_pages, page=2, per_page=10).json()["rows"]
        p3 = self._call(seeded_pages, page=3, per_page=10).json()["rows"]
        uniks = [r["UNIK"] for r in p1 + p2 + p3]
        assert len(uniks) == 25
        assert len(set(uniks)) == 25

    def test_page_zero_rejected(self, logged_in):
        assert self._call(logged_in, page=0).status_code == 422

    def test_page_negative_rejected(self, logged_in):
        assert self._call(logged_in, page=-1).status_code == 422

    def test_per_page_zero_rejected(self, logged_in):
        assert self._call(logged_in, per_page=0).status_code == 422

    def test_per_page_above_max_rejected(self, logged_in):
        assert self._call(logged_in, per_page=201).status_code == 422

    def test_per_page_one_accepted(self, logged_in):
        assert self._call(logged_in, per_page=1).json()["per_page"] == 1

    # ── Search ──
    def test_search_by_agent_code(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest, search="AG 001").json()
        assert data["total"] == 3
        for row in data["rows"]:
            assert row["CODE_AGENT"] == "AG 001"

    def test_search_by_city_code(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest, search="CITY 001").json()
        assert data["total"] == 5
        for row in data["rows"]:
            assert row["KD_CITY"] == "CITY 001"

    def test_search_by_city_desc(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest, search="CITY 002 Name").json()
        assert data["total"] == 5
        for row in data["rows"]:
            assert row["KD_CITY"] == "CITY 002"

    def test_search_by_quota_code(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest, search="QUOTA00").json()
        assert data["total"] == 1
        for row in data["rows"]:
            assert row["KD_QUOTA"] == "QUOTA00"

    def test_search_by_agent_name(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest, search="Agent 1").json()
        assert data["total"] == 3
        for row in data["rows"]:
            assert row["agent_name"] == "Agent 1"

    def test_search_case_insensitive(self, seeded_rate_dest):
        assert self._call(seeded_rate_dest, search="ag 001").json()["total"] == 3

    def test_search_empty_returns_all(self, seeded_rate_dest):
        assert self._call(seeded_rate_dest, search="").json()["total"] == 15

    def test_search_whitespace_returns_all(self, seeded_rate_dest):
        assert self._call(seeded_rate_dest, search="   ").json()["total"] == 15

    def test_search_no_results(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest, search="ZZZZZZ").json()
        assert data["total"] == 0
        assert data["rows"] == []
        assert data["pages"] == 1

    # ── Sort ──
    def test_sort_by_code_asc(self, seeded_rate_dest):
        rows = self._call(seeded_rate_dest, sort="code", sort_dir="asc").json()["rows"]
        keys = [(r["agent_name"] or r["CODE_AGENT"]) for r in rows]
        assert keys == sorted(keys)

    def test_sort_by_code_desc(self, seeded_rate_dest):
        rows = self._call(seeded_rate_dest, sort="code", sort_dir="desc").json()["rows"]
        keys = [(r["agent_name"] or r["CODE_AGENT"]) for r in rows]
        assert keys == sorted(keys, reverse=True)

    def test_sort_by_city_asc(self, seeded_rate_dest):
        cities = [r["city_label"] for r in self._call(seeded_rate_dest, sort="city", sort_dir="asc").json()["rows"]]
        assert cities == sorted(cities)

    def test_sort_by_city_desc(self, seeded_rate_dest):
        cities = [r["city_label"] for r in self._call(seeded_rate_dest, sort="city", sort_dir="desc").json()["rows"]]
        assert cities == sorted(cities, reverse=True)

    def test_sort_by_date_asc(self, seeded_rate_dest):
        dates = [r["TANGGAL"] for r in self._call(seeded_rate_dest, sort="date", sort_dir="asc").json()["rows"]]
        assert dates == sorted(dates)

    def test_sort_by_date_desc(self, seeded_rate_dest):
        dates = [r["TANGGAL"] for r in self._call(seeded_rate_dest, sort="date", sort_dir="desc").json()["rows"]]
        assert dates == sorted(dates, reverse=True)

    def test_sort_by_quota_accepted(self, seeded_rate_dest):
        rows = self._call(seeded_rate_dest, sort="quota", sort_dir="asc").json()["rows"]
        assert len(rows) == 15

    def test_sort_default_is_code_asc(self, seeded_rate_dest):
        rows = self._call(seeded_rate_dest).json()["rows"]
        keys = [(r["agent_name"] or r["CODE_AGENT"]) for r in rows]
        assert keys == sorted(keys)

    def test_invalid_sort_rejected(self, logged_in):
        r = self._call(logged_in, sort="invalid")
        assert r.status_code == 400
        assert "invalid sort" in r.json().get("detail", "").lower()

    def test_invalid_sort_dir_rejected(self, logged_in):
        r = self._call(logged_in, sort="code", sort_dir="up")
        assert r.status_code == 400
        assert "invalid sort_dir" in r.json().get("detail", "").lower()

    # ── Auth ──
    def test_api_requires_login(self, client):
        client.cookies.clear()
        r = client.get("/api/reports/rate-by-destination", follow_redirects=False)
        assert r.status_code in (303, 307, 401, 403)

    # ── Error state ──
    def test_db_error_returns_500(self, logged_in, monkeypatch):
        import app.queries as q
        from fastapi.testclient import TestClient
        from app.main import app
        def boom(*a, **k):
            raise sqlite3.OperationalError("boom")
        monkeypatch.setattr(q, "query", boom)
        nc = TestClient(app, raise_server_exceptions=False)
        nc.cookies = logged_in.cookies
        r = nc.get("/api/reports/rate-by-destination")
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
        r = nc.get("/api/reports/rate-by-destination")
        assert r.status_code == 500

    # ── Combined ──
    def test_search_with_pagination(self, seeded_pages):
        data = self._call(seeded_pages, search="CITY 01", page=1, per_page=2).json()
        assert data["page"] == 1
        assert data["per_page"] == 2
        assert len(data["rows"]) <= 2

    def test_sort_with_search(self, seeded_rate_dest):
        data = self._call(seeded_rate_dest, search="AG ", sort="city", sort_dir="asc").json()
        assert data["total"] == 15
        cities = [r["city_label"] for r in data["rows"]]
        assert cities == sorted(cities)

    # ── Type contract ──
    def test_response_types(self, logged_in):
        data = self._call(logged_in).json()
        assert isinstance(data["rows"], list)
        assert isinstance(data["total"], int)
        assert isinstance(data["page"], int)
        assert isinstance(data["per_page"], int)
        assert isinstance(data["pages"], int)

    # ── Edge: nullable rates ──
    def test_rate_columns_nullable(self, logged_in):
        import app.db as db
        conn = db.get_db()
        try:
            _seed_quotation(conn, "QNULL", "QUOTA-NULL", "2026-01-01", "SHP1")
            _seed_quot_r(conn, "QNULL", "AGNULL", "CITYNULL", "Null City",
                         None, None, None, None, None, None)
            conn.commit()
        finally:
            conn.close()
        data = self._call(logged_in).json()
        row = next(r for r in data["rows"] if r["CODE_AGENT"] == "AGNULL")
        for col in ("LCL", "MIN", "D20", "D40", "D40HQ", "D45"):
            assert row[col] is None, f"{col} should be None"
