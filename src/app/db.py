import sqlite3
import os
from contextvars import ContextVar
from pathlib import Path

# Default to a local db file, but allow override via environment variable.
DB_PATH = os.environ.get("DATABASE_URL", "CMT.db")

# Per-request override: when a company DB is selected via the UI,
# the middleware sets this so get_db() opens that DB instead.
_active_db: ContextVar[str | None] = ContextVar("active_db", default=None)

def set_active_db(path: str | None) -> None:
    """Set the active database path for the current request (via middleware)."""
    _active_db.set(path)

def get_active_db() -> str:
    """Return the currently active DB path (cookie-selected or default)."""
    return _active_db.get() or DB_PATH

def get_db():
    conn = sqlite3.connect(get_active_db())
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    return conn


def get_auth_db():
    """Always connect to the default DB for auth (users/sessions).

    Users and sessions are centralized in the default database so that
    a single login grants access to all company databases.
    """
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    return conn


def query(sql, params=None):
    conn = get_db()
    try:
        cur = conn.cursor()
        cur.execute(sql, params or [])
        return cur.fetchall()
    finally:
        conn.close()


def query_one(sql, params=None):
    rows = query(sql, params)
    return rows[0] if rows else None


def init_schema() -> None:
    """Create application schema (users, sessions) on the default DB.

    Always targets DB_PATH so auth is centralized, regardless of the
    active company DB set by the middleware.
    """
    conn = get_auth_db()
    try:
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS users (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                username           TEXT    UNIQUE NOT NULL,
                display_name       TEXT,
                role               TEXT    NOT NULL,
                pw_hash            TEXT    NOT NULL,
                salt               TEXT    NOT NULL,
                customer_company   TEXT,
                created_at         TEXT    NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS sessions (
                token       TEXT PRIMARY KEY,
                user_id     INTEGER NOT NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                expires_at  TEXT    NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users (id)
            );
            """
        )
        conn.commit()
    finally:
        conn.close()


def _table_exists(name: str) -> bool:
    """Check if table or view exists in the database schema."""
    r = query_one(
        "SELECT 1 FROM sqlite_master WHERE type IN ('table','view') AND name=?",
        (name,),
    )
    return r is not None

def table_count(table: str) -> int:
    if not table or not _table_exists(table):
        raise ValueError(f"table not found: {table!r}")
    r = query_one(f'SELECT COUNT(*) as c FROM "{table}"')
    return r["c"] if r else 0


def discover_databases(data_dir: str) -> list[dict]:
    """Scan *data_dir* for .db files and return [{name, path, size}].

    *name* is the filename without extension (shown in the UI).
    The default DB (DB_PATH) is flagged with ``is_default=True``.
    """
    p = Path(data_dir)
    if not p.is_dir():
        return []
    default_name = Path(DB_PATH).resolve()
    # DATA.db and INIT.db are shared/internal, not company databases.
    reserved_dbs = {"DATA", "INIT"}
    result = []
    for f in sorted(p.glob("*.db")):
        if f.stem.upper() in reserved_dbs:
            continue
        result.append({
            "name": f.stem,
            "path": str(f),
            "is_default": f.resolve() == default_name,
        })
    return result
