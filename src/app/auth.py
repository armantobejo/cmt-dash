"""
Real auth: cookie-based session, SQLite-backed user store with PBKDF2 hashed passwords.
Roles (descending): superadmin > admin > management > staff > customer.
"""
import hashlib
import logging
import os
import secrets
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from typing import Optional

from fastapi import Cookie, Depends, HTTPException, status

from .db import get_auth_db, init_schema

logger = logging.getLogger("cmt.auth")

SESSION_COOKIE = "cmt_session"
SESSION_TTL = timedelta(hours=8)
PBKDF2_ITERATIONS = 200_000

ROLE_LEVEL = {
    "superadmin": 5,
    "admin": 4,
    "management": 3,
    "staff": 2,
    "customer": 1,
}


@dataclass
class User:
    username: str = ""
    display_name: str = ""
    role: str = ""
    authenticated: bool = False
    customer_company: str = ""  # Partial-match filter for customer role (e.g. "Trisco").


def _hash(pw: str, salt: str) -> str:
    """PBKDF2-HMAC-SHA256 with per-user salt. Salt is hex string; stored alongside hash."""
    return hashlib.pbkdf2_hmac(
        "sha256", pw.encode(), salt.encode(), PBKDF2_ITERATIONS, dklen=32
    ).hex()


def _new_salt() -> str:
    return secrets.token_hex(16)


class UserStore:
    """SQLite-backed user store. Users and sessions persist across restarts."""

    def __init__(self):
        init_schema()
        self._seed_users()

    def _seed_users(self) -> None:
        """Load users from CMT_USERS env var only. No in-source default users.

        Format: user1:pass1:role1[:company1],user2:pass2:role2[:company2]
        Roles validated against ROLE_LEVEL; invalid entries are skipped.
        Idempotent: existing usernames are left untouched.
        """
        env_map = os.environ.get("CMT_USERS", "")
        if not env_map:
            return
        conn = get_auth_db()
        try:
            existing = {
                r["username"]
                for r in conn.execute("SELECT username FROM users").fetchall()
            }
            for entry in env_map.split(","):
                entry = entry.strip()
                if not entry:
                    continue
                parts = entry.split(":")
                if len(parts) not in (3, 4):
                    continue
                u, p, r = parts[:3]
                if r not in ROLE_LEVEL:
                    continue
                if u in existing:
                    continue
                company = parts[3] if len(parts) == 4 else None
                salt = _new_salt()
                conn.execute(
                    "INSERT INTO users (username, display_name, role, pw_hash, salt, customer_company) "
                    "VALUES (?, ?, ?, ?, ?, ?)",
                    (u, u.capitalize(), r, _hash(p, salt), salt, company),
                )
            conn.commit()
        finally:
            conn.close()

    def authenticate(self, username: str, password: str) -> Optional[str]:
        conn = get_auth_db()
        try:
            row = conn.execute(
                "SELECT id, pw_hash, salt FROM users WHERE username = ?", (username,)
            ).fetchone()
            if row is None:
                logger.info(
                    "user_login",
                    extra={"event": "user_login", "username": username, "outcome": "fail"},
                )
                return None
            if _hash(password, row["salt"]) != row["pw_hash"]:
                logger.info(
                    "user_login",
                    extra={"event": "user_login", "username": username, "outcome": "fail"},
                )
                return None
            token = secrets.token_urlsafe(32)
            expires_at = (datetime.now(timezone.utc) + SESSION_TTL).isoformat()
            conn.execute(
                "INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)",
                (token, row["id"], expires_at),
            )
            conn.commit()
            logger.info(
                "user_login",
                extra={"event": "user_login", "username": username, "outcome": "success"},
            )
            return token
        finally:
            conn.close()

    def logout(self, token: str) -> None:
        conn = get_auth_db()
        try:
            # Resolve username before delete for the log event.
            row = conn.execute(
                "SELECT u.username FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=?",
                (token,),
            ).fetchone()
            conn.execute("DELETE FROM sessions WHERE token = ?", (token,))
            conn.commit()
        finally:
            conn.close()
        if row:
            logger.info(
                "user_logout",
                extra={"event": "user_logout", "username": row["username"]},
            )

    def get(self, token: str) -> Optional[User]:
        conn = get_auth_db()
        try:
            row = conn.execute(
                "SELECT u.username, u.display_name, u.role, u.customer_company, s.expires_at "
                "FROM sessions s JOIN users u ON u.id = s.user_id "
                "WHERE s.token = ?",
                (token,),
            ).fetchone()
            if row is None:
                return None
            if row["expires_at"] <= datetime.now(timezone.utc).isoformat():
                # Expired: clean up + log, treat as anonymous.
                conn.execute("DELETE FROM sessions WHERE token = ?", (token,))
                conn.commit()
                logger.info(
                    "session_expired",
                    extra={"event": "session_expired", "username": row["username"]},
                )
                return None
            return User(
                username=row["username"],
                display_name=row["display_name"],
                role=row["role"],
                customer_company=row["customer_company"] or "",
                authenticated=True,
            )
        finally:
            conn.close()


    # ── User management (admin+) ──────────────────────────────────────

    def list_users(self) -> list[dict]:
        """Return all users (excluding password data)."""
        conn = get_auth_db()
        try:
            rows = conn.execute(
                "SELECT id, username, display_name, role, customer_company, created_at "
                "FROM users ORDER BY role, username"
            ).fetchall()
            return [dict(r) for r in rows]
        finally:
            conn.close()

    def get_user(self, user_id: int) -> dict | None:
        """Return a single user by ID (excluding password data)."""
        conn = get_auth_db()
        try:
            row = conn.execute(
                "SELECT id, username, display_name, role, customer_company, created_at "
                "FROM users WHERE id = ?",
                (user_id,),
            ).fetchone()
            return dict(row) if row else None
        finally:
            conn.close()

    def create_user(self, username: str, display_name: str, role: str,
                    password: str, customer_company: str = "") -> str | None:
        """Create a user. Returns error message on failure, None on success."""
        if role not in ROLE_LEVEL:
            return "Invalid role"
        if not username.strip():
            return "Username is required"
        if not password:
            return "Password is required"
        salt = _new_salt()
        conn = get_auth_db()
        try:
            conn.execute(
                "INSERT INTO users (username, display_name, role, pw_hash, salt, customer_company) "
                "VALUES (?, ?, ?, ?, ?, ?)",
                (username.strip(), display_name.strip() or username.strip(),
                 role, _hash(password, salt), salt, customer_company.strip() or None),
            )
            conn.commit()
        except Exception as e:
            if "UNIQUE" in str(e):
                return "Username already exists"
            return str(e)
        finally:
            conn.close()
        return None

    def update_user(self, user_id: int, display_name: str, role: str,
                    customer_company: str = "", password: str = "") -> str | None:
        """Update a user. Returns error message on failure, None on success."""
        if role not in ROLE_LEVEL:
            return "Invalid role"
        conn = get_auth_db()
        try:
            if password:
                salt = _new_salt()
                conn.execute(
                    "UPDATE users SET display_name=?, role=?, customer_company=?, "
                    "pw_hash=?, salt=? WHERE id=?",
                    (display_name.strip(), role,
                     customer_company.strip() or None,
                     _hash(password, salt), salt, user_id),
                )
            else:
                conn.execute(
                    "UPDATE users SET display_name=?, role=?, customer_company=? WHERE id=?",
                    (display_name.strip(), role,
                     customer_company.strip() or None, user_id),
                )
            conn.commit()
        finally:
            conn.close()
        return None

    def delete_user(self, user_id: int) -> str | None:
        """Delete a user. Returns error message on failure, None on success."""
        conn = get_auth_db()
        try:
            conn.execute("DELETE FROM sessions WHERE user_id=?", (user_id,))
            conn.execute("DELETE FROM users WHERE id=?", (user_id,))
            conn.commit()
        finally:
            conn.close()
        return None


STORE = UserStore()


def session_cookie_secure() -> bool:
    """Whether Set-Cookie should include the Secure flag (toggle via CMT_COOKIE_SECURE=1)."""
    return os.environ.get("CMT_COOKIE_SECURE", "") == "1"


def get_current_user(session: Optional[str] = Cookie(default=None, alias=SESSION_COOKIE)) -> User:
    """FastAPI dependency. Returns the real user if cookie valid, else an anonymous user."""
    if not session:
        return User()
    u = STORE.get(session)
    return u or User()


def require_login():
    """Dependency: 303→/login for anonymous users (HTML routes)."""
    def dep(user: User = Depends(get_current_user)) -> User:
        if not user.authenticated:
            raise HTTPException(
                status_code=status.HTTP_303_SEE_OTHER,
                headers={"Location": "/login"},
            )
        return user
    return dep


def require_api_login():
    """Dependency: 401 for anonymous API callers (no login redirect)."""
    def dep(user: User = Depends(get_current_user)) -> User:
        if not user.authenticated:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Unauthorized",
            )
        return user
    return dep


def require_role(min_role: str):
    """Dependency factory. 403 if user below min_role."""
    min_level = ROLE_LEVEL.get(min_role, 0)

    def dep(user: User = Depends(get_current_user)) -> User:
        user_level = ROLE_LEVEL.get(user.role, 0)
        if user_level < min_level:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=f"Requires role >= {min_role}",
            )
        return user

    return dep