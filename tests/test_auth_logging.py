"""T031: auth events emit structured JSON logs (login/logout/session_expired)."""
from __future__ import annotations

import io
import json
import logging
from datetime import datetime, timedelta, timezone

from fastapi.testclient import TestClient
from app.logging_config import JsonFormatter

from app import db
from app.auth import _hash, STORE


def _capture():
    buf = io.StringIO()
    handler = logging.StreamHandler(buf)
    handler.setFormatter(JsonFormatter())
    logging.getLogger().addHandler(handler)
    return handler, buf


def _drain(buf):
    out = buf.getvalue()
    buf.seek(0)
    buf.truncate(0)
    return [json.loads(l) for l in out.splitlines() if l.strip()]


def _make_user(username, role="staff"):
    db.init_schema()
    salt = "t031_salt"
    conn = db.get_db()
    try:
        conn.execute(
            "INSERT INTO users (username, display_name, role, pw_hash, salt, customer_company) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (username, username.title(), role, _hash("pw", salt), salt, None),
        )
        conn.commit()
    finally:
        conn.close()


def test_login_success_logs_event():
    from app.main import app  # configure_logging() runs here, may replace handlers

    handler, buf = _capture()
    try:
        _make_user("t031_ok")
        with TestClient(app) as c:
            c.post("/login", data={"username": "t031_ok", "password": "pw"},
                   follow_redirects=False)
    finally:
        logging.getLogger().removeHandler(handler)

    events = [r for r in _drain(buf) if r.get("event") == "user_login"]
    assert events, "expected a user_login event"
    assert events[-1]["outcome"] == "success"
    assert events[-1]["username"] == "t031_ok"


def test_login_failure_logs_event():
    from app.main import app

    handler, buf = _capture()
    try:
        _make_user("t031_bad")
        with TestClient(app) as c:
            c.post("/login", data={"username": "t031_bad", "password": "wrong"},
                   follow_redirects=False)
    finally:
        logging.getLogger().removeHandler(handler)

    events = [r for r in _drain(buf) if r.get("event") == "user_login"]
    assert events, "expected a user_login event"
    assert events[-1]["outcome"] == "fail"
    assert events[-1]["username"] == "t031_bad"


def test_logout_logs_event():
    from app.main import app

    handler, buf = _capture()
    try:
        _make_user("t031_out")
        with TestClient(app) as c:
            c.post("/login", data={"username": "t031_out", "password": "pw"},
                   follow_redirects=False)
            csrf = c.cookies.get("csrf_token", "")
            c.post("/logout", data={"csrf_token": csrf}, follow_redirects=False)
    finally:
        logging.getLogger().removeHandler(handler)

    events = [r for r in _drain(buf) if r.get("event") == "user_logout"]
    assert events, "expected a user_logout event"
    assert events[-1]["username"] == "t031_out"


def test_session_expired_logs_event():
    """get_current_user on an expired token logs session_expired."""
    from app.main import app

    handler, buf = _capture()
    try:
        db.init_schema()
        _make_user("t031_exp")
        conn = db.get_db()
        past = (datetime.now(timezone.utc) - timedelta(hours=2)).isoformat()
        try:
            uid = conn.execute("SELECT id FROM users WHERE username='t031_exp'").fetchone()["id"]
            conn.execute(
                "INSERT INTO sessions (token, user_id, created_at, expires_at) "
                "VALUES ('t031_expired_token', ?, ?, ?)",
                (uid, past, past),
            )
            conn.commit()
        finally:
            conn.close()
        STORE.get("t031_expired_token")
    finally:
        logging.getLogger().removeHandler(handler)

    events = [r for r in _drain(buf) if r.get("event") == "session_expired"]
    assert events, "expected a session_expired event"