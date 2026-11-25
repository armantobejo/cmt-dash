"""T030: ASGI middleware logs every request as JSON."""
from __future__ import annotations

import io
import json
import logging

from fastapi.testclient import TestClient
from app.logging_config import JsonFormatter


def _collect_stdout_json():
    """Attach a JSON formatter handler to root logger; return buffer."""
    buf = io.StringIO()
    handler = logging.StreamHandler(buf)
    handler.setFormatter(JsonFormatter())
    root = logging.getLogger()
    root.addHandler(handler)
    return handler, buf


def _json_lines(buf):
    """Parse captured output into list of JSON records (skips non-JSON)."""
    out = buf.getvalue()
    buf.seek(0)
    buf.truncate(0)
    return [json.loads(l) for l in out.splitlines() if l.strip() and l.strip().startswith("{")]


def test_request_logged_as_json():
    """GET / must produce an access event JSON line with required fields."""
    from app.main import app  # import first to trigger configure_logging()
    handler, buf = _collect_stdout_json()
    try:
        with TestClient(app) as c:
            c.get("/", follow_redirects=False)
    finally:
        logging.getLogger().removeHandler(handler)

    records = _json_lines(buf)
    access = [r for r in records if r.get("event") == "request"]
    assert access, "expected at least one request log line"
    rec = access[-1]
    assert rec["method"] == "GET"
    assert rec["path"] == "/"
    assert rec["status"] == 303  # anonymous -> redirect to /login
    assert "duration_ms" in rec
    assert "request_id" in rec
    assert "username" in rec


def test_login_request_has_username():
    """The request AFTER login (authenticated) must log the username."""
    from app import db
    from app.auth import _hash
    from app.main import app  # import first to trigger configure_logging()
    handler, buf = _collect_stdout_json()
    try:

        db.init_schema()
        salt = "t030_salt"
        conn = db.get_db()
        try:
            conn.execute(
                "INSERT INTO users (username, display_name, role, pw_hash, salt, customer_company) "
                "VALUES (?, ?, ?, ?, ?, ?)",
                ("t030_u", "T030", "staff", _hash("pw", salt), salt, None),
            )
            conn.commit()
        finally:
            conn.close()

        with TestClient(app) as c:
            resp = c.post("/login", data={"username": "t030_u", "password": "pw"},
                          follow_redirects=False)
            assert resp.status_code == 302
            # Follow redirect with the session cookie -> authenticated GET /
            c.get("/", follow_redirects=False)
    finally:
        logging.getLogger().removeHandler(handler)

    records = _json_lines(buf)
    access = [r for r in records if r.get("event") == "request"]
    assert access, "expected request log lines"
    root = [r for r in access if r.get("path") == "/"]
    assert root, "expected a request log for GET /"
    rec = root[-1]
    assert rec["username"] == "t030_u", f"expected t030_u, got {rec.get('username')!r}"