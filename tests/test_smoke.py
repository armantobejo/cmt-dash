"""Smoke tests for CMT Dashboard.

Goal: prove the test harness works end-to-end. Subsequent tasks will add real
coverage for auth, RBAC, sync, logging, etc.
"""
from __future__ import annotations


def test_get_login_returns_200(client):
    """GET /login is public and renders the login form."""
    resp = client.get("/login")
    assert resp.status_code == 200, f"login page should be 200, got {resp.status_code}"
    # login.html renders an HTML form posting to /login; check for the form action.
    body = resp.text.lower()
    assert 'action="/login"' in body or 'action=\"/login\"' in body or "<form" in body, (
        "expected login form in /login response"
    )
