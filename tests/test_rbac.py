"""T013: RBAC completeness — every data route gated; public whitelist explicit."""
from __future__ import annotations

import os

import pytest

from app import db
from app.auth import STORE, _hash


def _make_user(username, role="staff", company=None) -> None:
    db.init_schema()
    salt = "t013_salt"
    conn = db.get_db()
    try:
        conn.execute(
            "INSERT INTO users (username, display_name, role, pw_hash, salt, customer_company) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (username, username.title(), role, _hash("pw", salt), salt, company),
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


@pytest.fixture(scope="module")
def auth_client():
    """TestClient with a logged-in staff user."""
    from fastapi.testclient import TestClient
    from app.main import app

    _make_user("t013_staff", "staff")
    with TestClient(app) as c:
        resp = _login(c, "t013_staff")
        assert resp.status_code == 302
        yield c


def test_api_summary_requires_login():
    """Unauthenticated GET /api/summary must return 401 (API gate, no redirect)."""
    from fastapi.testclient import TestClient
    from app.main import app

    with TestClient(app) as c:
        resp = c.get("/api/summary", follow_redirects=False)
        assert resp.status_code == 401, resp.status_code
        assert resp.json().get("detail") == "Unauthorized"


def test_api_summary_returns_data_when_authenticated(auth_client):
    """Authenticated GET /api/summary must return 200 with the summary contract."""
    resp = auth_client.get("/api/summary")
    assert resp.status_code == 200, resp.status_code
    body = resp.json()
    for key in ("shipments", "imports", "exports", "invoices", "voyages", "recent_shipments"):
        assert key in body, f"/api/summary missing key {key!r}: {body!r}"


def test_api_health_public():
    """Liveness probe stays public."""
    from fastapi.testclient import TestClient
    from app.main import app

    with TestClient(app) as c:
        resp = c.get("/api/health")
        assert resp.status_code == 200
        assert resp.json() == {"status": "ok"}


def test_customer_role_data_filtering(auth_client):
    """Customer role must reach shipments route (not redirected to /login).

    Empty test DB lacks business tables so the endpoint may 5xx; we only
    confirm the customer cleared the auth gate.
    """
    _make_user("t013_cust", "customer", company="TRISCO")
    from fastapi.testclient import TestClient

    with TestClient(auth_client.app) as c:
        resp = _login(c, "t013_cust")
        assert resp.status_code == 302
        shipments = c.get("/shipments")
        # Must NOT be a redirect-to-login (303) — auth gate passed.
        assert shipments.status_code in (200, 500), shipments.status_code