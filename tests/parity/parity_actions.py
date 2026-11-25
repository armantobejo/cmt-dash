"""Shared actions for the parity harness (imported by the baseline test).

Kept out of conftest.py: pytest injects fixtures from conftest, not plain
functions, and the capture helpers call these mid-function.
"""
from __future__ import annotations

from parity_constants import ROLE_CREDS


def login(client, role: str) -> str:
    """Log in as the given role's seeded user; return the csrf_token cookie."""
    username, password = ROLE_CREDS[role]
    resp = client.post("/login", data={"username": username, "password": password})
    assert resp.status_code == 302, f"login as {role} failed: {resp.status_code}"
    assert "cmt_session" in client.cookies, f"no session cookie after login as {role}"
    return client.cookies.get("csrf_token", "")


def logout(client, csrf_token: str = "") -> None:
    """Clear the session cookie between roles so tests stay isolated."""
    if csrf_token:
        client.post("/logout", data={"csrf_token": csrf_token})
    else:
        client.post("/logout")
    client.cookies.clear()
