"""Regression: sync must preserve app-internal tables (users, sessions).

The sync builds a fresh SQLite DB from DBF files and os.replace()s it over
the live one. Without preservation, every sync wiped the user accounts and
sessions, breaking login until a container restart. _copy_app_tables() in
tools/sync_db.py carries those tables into the new DB before the swap.
"""
from __future__ import annotations

import sqlite3
from pathlib import Path

import pytest

_TOOLS = Path(__file__).resolve().parent.parent / "tools"


@pytest.fixture()
def sync_db_module(monkeypatch):
    monkeypatch.syspath_prepend(str(_TOOLS))
    import sync_db as s

    return s


def _make_app_db(path):
    """Old DB with app tables: users (has id) and sessions (keys on token)."""
    conn = sqlite3.connect(str(path))
    conn.executescript(
        """
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            role TEXT NOT NULL
        );
        INSERT INTO users (username, role) VALUES ('admin', 'superadmin');
        CREATE TABLE sessions (token TEXT PRIMARY KEY, user_id INTEGER);
        INSERT INTO sessions VALUES ('tok-1', 1);
        """
    )
    conn.commit()
    conn.close()


def test_copy_app_tables_preserves_users_and_sessions(sync_db_module, tmp_path):
    old = tmp_path / "old.db"
    new = tmp_path / "new.db"
    _make_app_db(old)

    conn = sqlite3.connect(str(new))  # stand-in for the freshly built temp DB
    sync_db_module._copy_app_tables(old, conn)
    conn.commit()

    assert conn.execute("SELECT username, role FROM users").fetchall() == [
        ("admin", "superadmin")
    ]
    assert conn.execute("SELECT token FROM sessions").fetchall() == [("tok-1",)]
    assert conn.execute("SELECT username FROM users WHERE id=1").fetchall() == [
        ("admin",)
    ]
    conn.close()


def test_copy_app_tables_is_noop_when_old_db_missing(sync_db_module, tmp_path):
    """No old DB (first ever sync): must not raise and must not create tables."""
    conn = sqlite3.connect(str(tmp_path / "new.db"))
    sync_db_module._copy_app_tables(tmp_path / "does-not-exist.db", conn)
    tables = [
        r[0]
        for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")
    ]
    assert "users" not in tables
    conn.close()


def test_copy_app_tables_handles_table_without_id_column(sync_db_module, tmp_path):
    """sessions keys on token (no `id`) — the sqlite_sequence fix must skip it."""
    old = tmp_path / "old.db"
    new = tmp_path / "new.db"
    _make_app_db(old)

    conn = sqlite3.connect(str(new))
    sync_db_module._copy_app_tables(old, conn)  # must not raise
    conn.commit()
    assert conn.execute("SELECT COUNT(*) FROM sessions").fetchone()[0] == 1
    conn.close()
