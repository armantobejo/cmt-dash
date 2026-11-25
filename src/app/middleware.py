"""ASGI middleware that emits a structured JSON line per HTTP request.

Logs `event=request` with: method, path, status, duration_ms, request_id,
username (from session if available). Sits as the outermost middleware so the
duration covers all downstream handling.
"""
from __future__ import annotations

import logging
import os
import secrets
import time
from http.cookies import SimpleCookie
from pathlib import Path

from . import db
from .db import DB_PATH, set_active_db

logger = logging.getLogger("cmt.access")

_DATA_DIR = os.environ.get("DATA_DIR", str(Path(DB_PATH).parent))

# Track which DBs have already been initialized (schema + view) so we
# only pay the cost once per DB, not on every request.
_initialized_dbs: set[str] = set()


def _parse_cookies(cookie_header: bytes) -> SimpleCookie:
    """Parse a raw Cookie header into a SimpleCookie object."""
    if not cookie_header:
        return SimpleCookie()
    try:
        return SimpleCookie(cookie_header.decode("latin-1", errors="replace"))
    except Exception:
        return SimpleCookie()


def _user_from_token(token: str):
    """Resolve token to username via the auth STORE; None if invalid/expired."""
    from .auth import STORE
    try:
        return STORE.get(token)
    except Exception:
        return None


class RequestLogMiddleware:
    """Pure-ASGI middleware (no BaseHTTPMiddleware — avoids response buffering)."""

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        request_id = secrets.token_hex(8)
        scope["cmt_request_id"] = request_id
        method = scope.get("method", "")
        path = scope.get("path", "")

        start = time.perf_counter()
        status_holder = {"status": 500}

        async def wrapped_send(message):
            if message["type"] == "http.response.start":
                status_holder["status"] = message.get("status", 500)
                # HTML pages must always revalidate so deploys show immediately
                # (static assets are cache-busted separately via ?v= URLs).
                headers = message.get("headers", [])
                if any(k == b"content-type" and b"text/html" in v for k, v in headers):
                    message["headers"] = headers + [(b"cache-control", b"no-cache")]
            await send(message)

        # Parse cookies once for both DB switching and session logging.
        cookie_header = b""
        for k, v in scope.get("headers", []):
            if k == b"cookie":
                cookie_header = v
                break
        cookies = _parse_cookies(cookie_header)

        # Set the active data DB from cookie before handling the request.
        db_name = cookies["cmt_db"].value if "cmt_db" in cookies else None
        active_db_path = None
        if db_name:
            db_file = Path(_DATA_DIR) / f"{db_name}.db"
            if db_file.is_file():
                active_db_path = str(db_file)
                set_active_db(active_db_path)
            else:
                set_active_db(None)  # invalid selection, fall back to default
        else:
            set_active_db(None)  # no selection, use default

        # Ensure the UnifiedShipment view exists on the active company DB.
        # Auth schema (users/sessions) is centralized in the default DB and
        # initialized once at startup — we only need to create the business
        # view here.
        _current_db = active_db_path or str(DB_PATH)
        if _current_db not in _initialized_dbs:
            try:
                from . import queries as _q
                _conn = _q.db.get_db()
                try:
                    _q.create_unified_view(_conn)
                finally:
                    _conn.close()
                _initialized_dbs.add(_current_db)
            except Exception:
                pass  # non-critical; worst case some views are missing

        try:
            await self.app(scope, receive, wrapped_send)
        finally:
            duration_ms = int((time.perf_counter() - start) * 1000)
            username = ""
            try:
                token = cookies["cmt_session"].value if "cmt_session" in cookies else None
                if token:
                    user = _user_from_token(token)
                    if user is not None:
                        username = user.username
            except Exception:
                # Logging must never raise — swallow lookups.
                username = ""

            logger.info(
                "request",
                extra={
                    "event": "request",
                    "request_id": request_id,
                    "method": method,
                    "path": path,
                    "status": status_holder["status"],
                    "duration_ms": duration_ms,
                    "username": username,
                },
            )
