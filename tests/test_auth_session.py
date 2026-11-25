"""T012: session expiry + Secure cookie flag via CMT_COOKIE_SECURE."""
from __future__ import annotations

import os
from datetime import datetime, timedelta, timezone

import pytest

from app import db
from app.auth import STORE, _hash, SESSION_COOKIE


@pytest.fixture(autouse=True)
def _fresh_store():
    """Give each test a clean store by re-initializing schema and clearing sessions."""
    db.init_schema()
    conn = db.get_db()
    try:
        conn.execute("DELETE FROM sessions")
        conn.commit()
    finally:
        conn.close()


def _insert_user(username, password) -> int:
    db.init_schema()
    salt = "test_salt_t012"
    pw_hash = _hash(password, salt)
    conn = db.get_db()
    try:
        cur = conn.execute(
            "INSERT INTO users (username, display_name, role, pw_hash, salt, customer_company) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (username, username.title(), "staff", pw_hash, salt, None),
        )
        conn.commit()
        return cur.lastrowid
    finally:
        conn.close()


def test_expired_session_rejected():
    """A session row with expires_at in the past must not authenticate."""
    db.init_schema()
    user_id = _insert_user("t012_expired", "pw")
    conn = db.get_db()
    try:
        past = (datetime.now(timezone.utc) - timedelta(hours=1)).isoformat()
        conn.execute(
            "INSERT INTO sessions (token, user_id, created_at, expires_at) "
            "VALUES ('expired_token_1', ?, ?, ?)",
            (user_id, past, past),
        )
        conn.commit()
    finally:
        conn.close()

    assert STORE.get("expired_token_1") is None


def test_active_session_accepted():
    db.init_schema()
    user_id = _insert_user("t012_active", "pw")
    conn = db.get_db()
    try:
        future = (datetime.now(timezone.utc) + timedelta(hours=1)).isoformat()
        conn.execute(
            "INSERT INTO sessions (token, user_id, created_at, expires_at) "
            "VALUES ('active_token_1', ?, ?, ?)",
            (user_id, future, future),
        )
        conn.commit()
    finally:
        conn.close()

    user = STORE.get("active_token_1")
    assert user is not None and user.authenticated


def test_cookie_secure_flag_via_env():
    """With CMT_COOKIE_SECURE=1, login response must set a Secure cookie."""
    import app.main as main_mod
    from fastapi.testclient import TestClient

    os.environ["CMT_COOKIE_SECURE"] = "1"
    _insert_user("t012_cookie", "pw")
    try:
        with TestClient(main_mod.app) as c:
            resp = c.post(
                "/login",
                data={"username": "t012_cookie", "password": "pw"},
                follow_redirects=False,
            )
            set_cookie = resp.headers.get("set-cookie", "")
            assert SESSION_COOKIE in set_cookie
            assert "Secure" in set_cookie, f"cookie should have Secure flag: {set_cookie}"
            assert "HttpOnly" in set_cookie
    finally:
        os.environ.pop("CMT_COOKIE_SECURE", None)


def test_cookie_not_secure_by_default():
    """Without CMT_COOKIE_SECURE, cookie must NOT have Secure flag."""
    import app.main as main_mod
    from fastapi.testclient import TestClient

    os.environ.pop("CMT_COOKIE_SECURE", None)
    _insert_user("t012_nosecure", "pw")
    with TestClient(main_mod.app) as c:
        resp = c.post(
            "/login",
            data={"username": "t012_nosecure", "password": "pw"},
            follow_redirects=False,
        )
        set_cookie = resp.headers.get("set-cookie", "")
        assert "Secure" not in set_cookie, f"cookie should not be Secure: {set_cookie}"