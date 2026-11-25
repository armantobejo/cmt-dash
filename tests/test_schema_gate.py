"""Schema gates: phantom-column regressions can never silently return.

Three layers (the INVOICE.CONTAINERN lesson — both apps' invoice detail 500'd
on real production data because tests ran against schemas wider than reality;
docs/decisions/invoice-container-schema-drift.md):

1. The committed gate fixture tests/parity/fixtures/real_schema.json matches
   the actual data/*.db company files (regenerate via tools/dump_real_schema.py).
2. The parity dataset's tables are a subset of the production shape — no
   dataset-only column can make a query pass in tests and 500 in production.
3. EVERY query function executes against a production-shaped DB built from the
   dataset's DDL with production's exact column sets.

Runs in the plain `pytest tests/` suite (no parity-mode env needed): the app
modules read DATABASE_URL, which conftest sets before importing them; each
gate DB is passed explicitly via db.set_active_db().
"""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import pytest

_REPO = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(_REPO / "tools"))  # schema.py (unified SELECT SoT)
sys.path.insert(0, str(_REPO / "tests" / "parity"))  # parity_dataset

from app import db as app_db  # noqa: E402
from app import queries as q  # noqa: E402
from schema import _unified_select_sql  # noqa: E402

FIXTURE = _REPO / "tests" / "parity" / "fixtures" / "real_schema.json"


# ── Layer 1: the gate fixture is true to the real company DBs ────────────────

def test_gate_fixture_matches_real_dbs_when_present():
    """Skipped on checkouts without data/*.db; a hard gate where they exist."""
    if not list((_REPO / "data").glob("*.db")):
        pytest.skip("no data/*.db in this checkout")

    from dump_real_schema import dump

    committed = json.loads(FIXTURE.read_text(encoding="utf-8"))
    assert committed == dump(_REPO / "data"), (
        "tests/parity/fixtures/real_schema.json is stale vs data/*.db — "
        "re-run: python tools/dump_real_schema.py"
    )


def test_gate_fixture_is_committed_and_nonempty():
    tables = json.loads(FIXTURE.read_text(encoding="utf-8"))
    assert len(tables) >= 1
    for company, shape in tables.items():
        assert shape, f"{company}: empty table set"
        # The regression this file exists for, pinned forever:
        assert "CONTAINERN" not in shape.get("INVOICE", {}), (
            "INVOICE.CONTAINERN reappeared in a real company DB — both apps' "
            "invoice-detail queries must be reconciled before this gate can pass"
        )


# ── Layer 2: dataset ⊆ production shape ─────────────────────────────────────

def _dataset_tables():
    from parity_dataset import _base_tables, _seed_gl_only  # noqa: F401

    import parity_dataset as pd

    import sqlite3

    conn = sqlite3.connect(":memory:")
    seen: dict[str, list[str]] = {}
    orig = pd._table

    def spy(c, name, cols, real_cols=()):
        orig(c, name, cols, real_cols)
        seen[name] = list(cols)

    pd._table = spy
    try:
        pd._base_tables(conn)
        pd._seed_gl_only(conn, pd.DEFAULTS)
    finally:
        pd._table = orig
    return seen


def test_dataset_tables_are_subset_of_production_shape():
    shape = json.loads(FIXTURE.read_text(encoding="utf-8"))
    prod = shape.get("CMT") or next(iter(shape.values()))
    for table, cols in _dataset_tables().items():
        extra = [c for c in cols if c not in prod.get(table, {})]
        assert not extra, (
            f"{table}: dataset-only columns {extra} would make queries pass in "
            "tests but fail on production data (phantom-column class of bug)"
        )


# ── Layer 3: every query function runs on a production-shaped DB ────────────

@pytest.fixture(scope="module")
def prod_shape_db(tmp_path_factory):
    """A DB with the dataset's ROWS but production's exact COLUMN sets.

    Built by letting the dataset create its tables, then rebuilding each with
    only the columns the real sync would produce and re-inserting the data.
    Any query referencing a production-absent column fails here.
    """
    import parity_dataset as pd
    import sqlite3

    shape = json.loads(FIXTURE.read_text(encoding="utf-8"))
    prod = shape.get("CMT") or next(iter(shape.values()))

    path = tmp_path_factory.mktemp("prodshape") / "cmt.db"
    conn = sqlite3.connect(str(path))

    # Full dataset DDL + rows first (in-memory col sets are the dataset's).
    pd._base_tables(conn)
    pd._seed_business(conn, pd.DEFAULTS)
    conn.execute("DROP VIEW IF EXISTS UnifiedShipment")
    conn.execute("DROP TABLE IF EXISTS UnifiedShipment")
    conn.execute(f"CREATE VIEW UnifiedShipment AS {_unified_select_sql()}")

    # Rebuild any table whose column set exceeds production; keep only the
    # intersection (production columns), so seeds survive where they can.
    for table, prod_cols in prod.items():
        info = conn.execute(f'PRAGMA table_info("{table}")').fetchall()
        if not info:
            continue  # not created by the dataset (users/sessions/...): skip
        have = {r[1] for r in info}
        extra = have - set(prod_cols)
        if not extra or table == "UnifiedShipment":
            continue
        keep = [c for c in (r[1] for r in info) if c in prod_cols]
        col_list = ", ".join(f'"{c}"' for c in keep)
        conn.execute(f'ALTER TABLE "{table}" RENAME TO "_old_{table}"')
        conn.execute(
            f'CREATE TABLE "{table}" ({col_list})'
        )
        conn.execute(
            f'INSERT INTO "{table}" ({col_list}) SELECT {col_list} FROM "_old_{table}"'
        )
        conn.execute(f'DROP TABLE "_old_{table}"')
    conn.commit()
    conn.close()
    return path


def _exercise_all_queries(path):
    app_db.set_active_db(str(path))
    # get_gl_anomalies scans DATA_DIR (not the active DB connection); keep it
    # pointed at the gate dir so it doesn't aggregate the real data/*.db.
    old_data_dir = os.environ.get("DATA_DIR")
    os.environ["DATA_DIR"] = str(path.parent)
    try:
        q.get_summary()
        q.get_summary(company_filter="TRISCO")
        q.get_recent_shipments()
        q.get_unpaid_shipments()
        q.get_shipments()
        q.get_shipments(search="INV")
        q.get_shipments(type_filter="import")
        q.get_shipments(invoice_filter="paid")
        q.get_top_customers()
        q.get_invoices()
        q.get_invoices(search="INV")
        q.get_shipment_detail("REF-TRISCO-001")
        q.get_invoice_detail("INV-0001")
        q.get_sales_summary()
        q.get_sales_monthly_trend()
        q.get_sales_top_customers()
        q.get_sales_top_salesman()
        q.get_accounts()
        q.get_account_detail("1101")
        q.get_gl_anomalies()
        q.get_gl_anomaly_detail("unbalanced")
    finally:
        app_db.set_active_db(None)
        if old_data_dir is None:
            os.environ.pop("DATA_DIR", None)
        else:
            os.environ["DATA_DIR"] = old_data_dir


def test_every_query_function_runs_on_production_shape(prod_shape_db):
    """The CONTAINERN regression class: any query touching a column the real
    sync never creates raises here (sqlite3.OperationalError) instead of only
    in production."""
    _exercise_all_queries(prod_shape_db)


def test_invoice_detail_pinned_containern_regression(prod_shape_db):
    """Pinned regression for the original incident: invoice detail executes and
    the Kontainer card renders the Jinja fallback, not a 500."""
    app_db.set_active_db(str(prod_shape_db))
    try:
        detail = q.get_invoice_detail("INV-0001")
    finally:
        app_db.set_active_db(None)
    assert detail is not None
    assert "CONTAINERN" not in detail["header"], (
        "invoice detail re-selected the production-absent INVOICE.CONTAINERN "
        "column — the exact regression this gate pins"
    )
