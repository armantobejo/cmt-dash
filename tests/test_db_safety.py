"""T015: table_count must reject non-whitelisted table names (SQL injection foot-gun)."""
from __future__ import annotations

import pytest

from app import db


def test_table_count_rejects_unknown_table():
    with pytest.raises(ValueError):
        db.table_count("users; DROP TABLE users;--")


def test_table_count_rejects_malicious_single_quote():
    with pytest.raises(ValueError):
        db.table_count("' OR 1=1 --")


def test_table_count_rejects_empty():
    with pytest.raises(ValueError):
        db.table_count("")


def test_table_count_accepts_whitelisted():
    """Real tables used by app queries must be accepted (no exception)."""
    # The table may not exist in the test DB, but the whitelist check happens
    # BEFORE any SQL runs, so we expect ValueError only for unknown names.
    # We assert the call does not raise ValueError (operational error is ok).
    try:
        db.table_count("NA")
    except ValueError as e:
        pytest.fail(f"whitelisted table NA rejected: {e}")
    except Exception:
        pass  # OperationalError (no table) is fine — whitelist check passed


def test_table_exists_checks_schema():
    """_table_exists should query the schema, not a hardcoded list."""
    assert db._table_exists("users") is True
    assert db._table_exists("nonexistent_table_xyz") is False