"""T033: /api/sync-status reports last sync time + freshness."""
from __future__ import annotations

import os
from datetime import datetime, timezone

from fastapi.testclient import TestClient
from app.main import app


def _login(client):
    from app import db
    from app.auth import _hash

    db.init_schema()
    salt = "t033_salt"
    conn = db.get_db()
    try:
        conn.execute(
            "INSERT INTO users (username, display_name, role, pw_hash, salt, customer_company) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            ("t033_u", "T033", "staff", _hash("pw", salt), salt, None),
        )
        conn.commit()
    finally:
        conn.close()
    return client.post(
        "/login", data={"username": "t033_u", "password": "pw"}, follow_redirects=False
    )


def test_sync_status_returns_last_sync_and_freshness():
    with TestClient(app) as c:
        _login(c)
        resp = c.get("/api/sync-status")
        assert resp.status_code == 200
        body = resp.json()
        assert "last_sync" in body
        assert isinstance(body["last_sync"], str)  # ISO timestamp
        assert "stale" in body
        assert isinstance(body["stale"], bool)


def test_sync_status_requires_login():
    with TestClient(app) as c:
        resp = c.get("/api/sync-status", follow_redirects=False)
        assert resp.status_code in (303, 307)
        assert "/login" in resp.headers.get("location", "")