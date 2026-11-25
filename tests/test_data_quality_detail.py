"""Data-quality detail: auth gates, allowlist, pagination, unit consistency."""
from __future__ import annotations

import sqlite3
import tempfile
from pathlib import Path

from app import db
from app.auth import _hash


def _insert_user(username, role="superadmin") -> None:
    db.init_schema()
    salt = "dq_salt"
    conn = db.get_db()
    try:
        conn.execute(
            "INSERT OR IGNORE INTO users (username, display_name, role, pw_hash, salt, customer_company) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (username, username.title(), role, _hash("pw", salt), salt, None),
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


def _client():
    from fastapi.testclient import TestClient
    from app.main import app

    return TestClient(app, raise_server_exceptions=False)


def test_detail_unauth_is_403():
    with _client() as c:
        r = c.get("/data-quality/detail?type=unbalanced", follow_redirects=False)
        assert r.status_code == 403


def test_detail_bad_type_redirects():
    _insert_user("dq_admin", "superadmin")
    with _client() as c:
        assert _login(c, "dq_admin").status_code == 302
        r = c.get("/data-quality/detail?type=bogus", follow_redirects=False)
        assert r.status_code == 303
        assert "/data-quality" in r.headers.get("location", "")


def test_detail_staff_forbidden():
    _insert_user("dq_staff", "staff")
    with _client() as c:
        assert _login(c, "dq_staff").status_code == 302
        r = c.get("/data-quality/detail?type=unbalanced", follow_redirects=False)
        assert r.status_code == 403


def test_detail_superadmin_empty_200():
    _insert_user("dq_admin2", "superadmin")
    with _client() as c:
        assert _login(c, "dq_admin2").status_code == 302
        r = c.get("/data-quality/detail?type=unbalanced")
        assert r.status_code == 200


def test_detail_page_clamped_200():
    _insert_user("dq_admin3", "superadmin")
    with _client() as c:
        assert _login(c, "dq_admin3").status_code == 302
        r = c.get("/data-quality/detail?type=orphan&page=99")
        assert r.status_code == 200


def _make_orphan_db(n: int = 150) -> Path:
    p = Path(tempfile.mkdtemp(prefix="dq_")) / "t.db"
    conn = sqlite3.connect(str(p))
    conn.execute("CREATE TABLE JE (UJE TEXT)")
    conn.execute(
        "CREATE TABLE JE_D (UJE TEXT, KD_ACCOUNT TEXT, JUMLAH REAL, "
        "CURRENCY TEXT, EXRATE REAL, DK TEXT, URAIAN TEXT)"
    )
    conn.execute("INSERT INTO JE (UJE) VALUES ('KEEP')")
    conn.executemany(
        "INSERT INTO JE_D (UJE, KD_ACCOUNT, JUMLAH, CURRENCY, EXRATE, DK, URAIAN) "
        "VALUES (?, 'A', 1, 'RP', NULL, 'D', 'x')",
        [(f"ORPH{i}",) for i in range(n)],
    )
    conn.commit()
    conn.close()
    return p


def _make_mixed_orphan_db() -> Path:
    """Orphan JE_D rows in mixed currencies to exercise EXRATE conversion.

    Expected IDR page total: 1000 (RP) + 2*16000 (USD) + 500 (RP.) + 1*0
    (USD with NULL EXRATE -> contributes 0, same COALESCE(EXRATE,0) rule
    as get_account_detail) = 33500. D rows: 1500, K rows: 32000.
    """
    p = Path(tempfile.mkdtemp(prefix="dq_mixed_")) / "m.db"
    conn = sqlite3.connect(str(p))
    conn.execute("CREATE TABLE JE (UJE TEXT)")
    conn.execute(
        "CREATE TABLE JE_D (UJE TEXT, KD_ACCOUNT TEXT, JUMLAH REAL, "
        "CURRENCY TEXT, EXRATE REAL, DK TEXT, URAIAN TEXT)"
    )
    conn.execute("INSERT INTO JE (UJE) VALUES ('KEEP')")
    conn.executemany(
        "INSERT INTO JE_D (UJE, KD_ACCOUNT, JUMLAH, CURRENCY, EXRATE, DK, URAIAN) "
        "VALUES (?, 'A', ?, ?, ?, ?, 'x')",
        [
            ("ORPHA", 1000.0, "RP", None, "D"),
            ("ORPHB", 2.0, "USD", 16000.0, "K"),
            ("ORPHC", 500.0, "RP.", None, "D"),
            ("ORPHD", 1.0, "USD", None, "K"),
        ],
    )
    conn.commit()
    conn.close()
    return p


def _make_unbalanced_db() -> Path:
    """Two unbalanced vouchers: gaps +0.5 and -10.0, page gap sum -9.5."""
    p = Path(tempfile.mkdtemp(prefix="dq_unbal_")) / "u.db"
    conn = sqlite3.connect(str(p))
    conn.execute(
        "CREATE TABLE JE (UJE TEXT, TANGGAL TEXT, REF_NO TEXT, "
        "KETERANGAN TEXT, TOT_DR REAL, TOT_CR REAL)"
    )
    conn.execute("CREATE TABLE JE_D (UJE TEXT)")
    conn.executemany(
        "INSERT INTO JE (UJE, TANGGAL, REF_NO, KETERANGAN, TOT_DR, TOT_CR) "
        "VALUES (?, ?, ?, ?, ?, ?)",
        [
            ("V1", "2026-01-01", "R1", "k", 100.5, 100.0),
            ("V2", "2026-01-02", "R2", "k", 50.0, 60.0),
            ("V3", "2026-01-03", "R3", "k", 10.0, 10.0),  # balanced: excluded
        ],
    )
    conn.commit()
    conn.close()
    return p


def test_detail_orphan_sums_convert_to_idr():
    from app import queries as q

    p = _make_mixed_orphan_db()
    d = q.get_gl_anomaly_detail("orphan", company_filter="T", db_path=str(p))
    assert d["sums"] == {"amount_idr": 33500.0, "dr_idr": 1500.0, "cr_idr": 32000.0}
    # Currency context columns now ride along for per-row display.
    assert d["columns"] == ["UJE", "KD_ACCOUNT", "JUMLAH", "CURRENCY", "EXRATE", "DK", "URAIAN"]


def test_detail_unbalanced_sums_and_gap():
    from app import queries as q

    p = _make_unbalanced_db()
    d = q.get_gl_anomaly_detail("unbalanced", company_filter="T", db_path=str(p))
    assert d["sums"] == {"dr": 150.5, "cr": 160.0, "gap": -9.5}
    assert d["columns"][-1] == "GAP"
    gaps = sorted(round(r["GAP"], 6) for r in d["rows"])
    assert gaps == [-10.0, 0.5]
    # Balanced vouchers never appear.
    assert len(d["rows"]) == 2


def test_detail_pagination_unit():
    from app import queries as q

    p = _make_orphan_db(150)
    d1 = q.get_gl_anomaly_detail("orphan", company_filter="T", per_page=100, db_path=str(p))
    assert (d1["total"], d1["pages"], len(d1["rows"])) == (150, 2, 100)
    d2 = q.get_gl_anomaly_detail("orphan", company_filter="T", page=2, per_page=100, db_path=str(p))
    assert (d2["page"], len(d2["rows"])) == (2, 50)
    d3 = q.get_gl_anomaly_detail("orphan", company_filter="T", page=99, per_page=100, db_path=str(p))
    assert d3["page"] == 2  # clamped


def test_detail_missing_tables_empty():
    from app import queries as q

    p = Path(tempfile.mkdtemp(prefix="dq_empty_")) / "e.db"
    sqlite3.connect(str(p)).close()
    d = q.get_gl_anomaly_detail("unbalanced", company_filter="T", db_path=str(p))
    assert d["total"] == 0 and d["rows"] == []


# ------------------------------------------------- bad_date GLOB regression

def _make_bad_date_db() -> Path:
    """Two genuine bad-date vouchers + three valid ones.

    Pins the get_gl_anomaly_detail bad_date fix: the detail query's GLOB must
    be IDENTICAL to the rollup's. A malformed pattern (4-digit month/day once
    shipped) can never match a valid date, so NOT GLOB selected EVERY JE row
    — 70,555 on real CMT data vs the rollup's 43.
    """
    p = Path(tempfile.mkdtemp(prefix="dq_baddate_")) / "b.db"
    conn = sqlite3.connect(str(p))
    conn.execute(
        "CREATE TABLE JE (UJE TEXT, TANGGAL TEXT, REF_NO TEXT, "
        "KETERANGAN TEXT, TOT_DR REAL, TOT_CR REAL)"
    )
    conn.execute(
        "CREATE TABLE JE_D (UJE TEXT, KD_ACCOUNT TEXT, JUMLAH REAL, "
        "CURRENCY TEXT, EXRATE REAL, DK TEXT, URAIAN TEXT)"
    )
    # One orphan JE_D row: the rollup legitimately reports it alongside
    # bad_date (this test reads only the bad_date rollup row).
    conn.execute(
        "INSERT INTO JE_D (UJE, KD_ACCOUNT, JUMLAH, CURRENCY, EXRATE, DK, URAIAN) "
        "VALUES ('ORPHAN', 'A', 1.0, 'RP', NULL, 'D', 'x')"
    )
    conn.executemany(
        "INSERT INTO JE (UJE, TANGGAL, REF_NO, KETERANGAN, TOT_DR, TOT_CR) "
        "VALUES (?, ?, ?, ?, ?, ?)",
        [
            ("B1", "0007-04-17", "BAD-EARLY", "k", 1.0, 1.0),   # year < 2000
            ("B2", "1999-12-31", "BAD-1999", "k", 1.0, 1.0),    # year < 2000
            ("G1", "2026-01-01", "OK-1", "k", 10.0, 10.0),      # valid
            # Shape-valid but calendar-impossible: NOT flagged (rollup checks
            # shape + year only, not calendar validity) — documented behavior.
            ("G2", "2026-13-45", "SHAPE-OK-IMPOSSIBLE", "k", 5.0, 5.0),
            ("G3", "2026-02-02", "OK-2", "k", 20.0, 20.0),      # valid
        ],
    )
    conn.commit()
    conn.close()
    return p


def test_detail_bad_date_matches_rollup_exactly():
    from app import queries as q

    p = _make_bad_date_db()
    d = q.get_gl_anomaly_detail("bad_date", company_filter="T", db_path=str(p))
    refs = {r["REF_NO"] for r in d["rows"]}

    # Exactly the two bad-date vouchers — never the whole table.
    assert d["total"] == 2
    assert refs == {"BAD-EARLY", "BAD-1999"}

    # Rollup (independent query path) and detail must agree on the same DB.
    import os

    old_data_dir = os.environ.get("DATA_DIR")
    os.environ["DATA_DIR"] = str(p.parent)
    try:
        rollup = q.get_gl_anomalies("B")
    finally:
        if old_data_dir is None:
            os.environ.pop("DATA_DIR", None)
        else:
            os.environ["DATA_DIR"] = old_data_dir
    bd = next(r for r in rollup if r["type"] == "bad_date")
    assert bd["count"] == d["total"], (
        "rollup and detail disagree — the detail GLOB drifted from the rollup's"
    )
