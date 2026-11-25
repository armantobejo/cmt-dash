#!/usr/bin/env python3
"""Cross-check every query function against all five REAL company DB schemas.

Complements tests/test_schema_gate.py, which executes queries with DEFAULT
parameters on a production-shaped synthetic DB. This audit additionally:

  - uses COPIES of the real data/*.db (all five companies),
  - exercises every BRANCH of every query function (filters, search, sorts,
    sort directions, periods, anomaly types, detail keys drawn from real rows),
  - runs with _safe_query UN-swallowed, so a column error inside a
    "return [] on failure" site surfaces instead of silently rendering empty
    (the failure mode that hid INVOICE.CONTAINERN for months).

Read-only: works on temp copies; never opens data/*.db for writing.

Run:  python tools/audit_production_queries.py
Exit: 0 = every branch OK on every company; 1 = at least one production 500.
"""
from __future__ import annotations

import os
import shutil
import sqlite3
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO / "src"))
sys.path.insert(0, str(REPO / "tools"))

os.environ.setdefault("DATABASE_URL", ":memory:")

from app import db as app_db  # noqa: E402
from app import queries as q  # noqa: E402


def prepare_company_copies(audit_dir: Path) -> list[Path]:
    """Copy each real company DB; add users/sessions + UnifiedShipment."""
    paths = []
    for src in sorted((REPO / "data").glob("*.db")):
        dst = audit_dir / src.name
        shutil.copy2(src, dst)
        conn = sqlite3.connect(str(dst))
        try:
            # App-support tables the sync preserves (idempotent if present).
            app_db.set_active_db(str(dst))
            app_db.init_schema()
            # UnifiedShipment as a real TABLE from the sync's own SQL.
            from schema import create_unified_table

            create_unified_table(conn)
            conn.commit()
        finally:
            app_db.set_active_db(None)
            conn.close()
        paths.append(dst)
    return paths


def branch_calls(queries: "q", company: str) -> list[tuple[str, object]]:
    """(branch-label, zero-arg callable) — every code path of queries.py."""
    real_ref = queries.query_one(
        "SELECT REFSHITMEN AS r FROM UnifiedShipment "
        "WHERE REFSHITMEN IS NOT NULL AND REFSHITMEN != '' LIMIT 1"
    )
    real_inv = queries.query_one(
        "SELECT K_INVOICE AS k FROM INVOICE "
        "WHERE K_INVOICE IS NOT NULL AND K_INVOICE != '' LIMIT 1"
    )
    ref = real_ref["r"] if real_ref else "___no_ref___"
    kinv = real_inv["k"] if real_inv else "___no_inv___"

    calls: list[tuple[str, object]] = [
        ("summary/default", lambda: queries.get_summary()),
        ("summary/company_filter", lambda: queries.get_summary(company)),
        ("recent/default", lambda: queries.get_recent_shipments()),
        ("recent/company_filter", lambda: queries.get_recent_shipments(company_filter=company)),
        ("unpaid/default", lambda: queries.get_unpaid_shipments()),
        ("unpaid/search", lambda: queries.get_unpaid_shipments(search="A")),
        ("unpaid/company_filter", lambda: queries.get_unpaid_shipments(company_filter=company)),
    ]
    for sort in ("date", "reference", "customer"):
        for d in ("asc", "desc"):
            calls.append((f"unpaid/sort={sort}.{d}",
                          lambda s=sort, dd=d: queries.get_unpaid_shipments(sort=s, sort_dir=dd)))
    calls.append(("shipments/default", lambda: queries.get_shipments()))
    calls.append(("shipments/search", lambda: queries.get_shipments(search="A")))
    calls.append(("shipments/company_filter", lambda: queries.get_shipments(company_filter=company)))
    for t in ("import", "export", "other"):
        calls.append((f"shipments/type={t}",
                      lambda tt=t: queries.get_shipments(type_filter=tt)))
    for f in ("unissued", "issued", "issued_unpaid", "paid"):
        calls.append((f"shipments/invoice={f}",
                      lambda ff=f: queries.get_shipments(invoice_filter=ff)))
    for sort in ("date", "reference", "customer"):
        for d in ("asc", "desc"):
            calls.append((f"shipments/sort={sort}.{d}",
                          lambda s=sort, dd=d: queries.get_shipments(sort=s, sort_dir=dd)))
    calls.append(("top_customers", lambda: queries.get_top_customers()))
    calls.append(("invoices/default", lambda: queries.get_invoices()))
    calls.append(("invoices/search", lambda: queries.get_invoices(search="A")))
    calls.append(("invoices/company_filter", lambda: queries.get_invoices(company_filter=company)))
    for sort in ("date", "reference", "customer"):
        for d in ("asc", "desc"):
            calls.append((f"invoices/sort={sort}.{d}",
                          lambda s=sort, dd=d: queries.get_invoices(sort=s, sort_dir=dd)))
    calls.append(("shipment_detail/hit", lambda: queries.get_shipment_detail(ref)))
    calls.append(("shipment_detail/miss", lambda: queries.get_shipment_detail("___nope___")))
    calls.append(("invoice_detail/hit", lambda: queries.get_invoice_detail(kinv)))
    calls.append(("invoice_detail/miss", lambda: queries.get_invoice_detail("___nope___")))
    calls.append(("sales_summary/default", lambda: queries.get_sales_summary()))
    calls.append(("sales_summary/company_filter", lambda: queries.get_sales_summary(company)))
    calls.append(("sales_trend/default", lambda: queries.get_sales_monthly_trend()))
    calls.append(("sales_trend/company_filter", lambda: queries.get_sales_monthly_trend(company)))
    calls.append(("sales_top_customers/default", lambda: queries.get_sales_top_customers()))
    calls.append(("sales_top_customers/company_filter", lambda: queries.get_sales_top_customers(company)))
    for period in ("month", "year", "round", "all"):
        calls.append((f"sales_top_salesman/{period}",
                      lambda p=period: queries.get_sales_top_salesman(period=p)))
    calls.append(("sales_top_salesman/company_filter",
                  lambda: queries.get_sales_top_salesman(company_filter=company)))
    calls.append(("accounts/default", lambda: queries.get_accounts()))
    calls.append(("accounts/search", lambda: queries.get_accounts(search="A")))
    for t in ("detail", "group"):
        calls.append((f"accounts/type={t}",
                      lambda tt=t: queries.get_accounts(type_filter=tt)))
    for sort in ("code", "name", "type", "balance", "active"):
        calls.append((f"accounts/sort={sort}",
                      lambda s=sort: queries.get_accounts(sort=s)))
    calls.append(("account_detail/default", lambda: queries.get_account_detail("___nope___")))
    for sort in ("date", "modul", "ref"):
        calls.append((f"account_detail/sort={sort}",
                      lambda s=sort: queries.get_account_detail("___nope___", sort=s)))
    calls.append(("gl_anomalies/default", lambda: queries.get_gl_anomalies()))
    calls.append(("gl_anomalies/company_filter", lambda: queries.get_gl_anomalies(company)))
    for t in ("unbalanced", "orphan", "bad_date", "bad_dk"):
        calls.append((f"gl_anomaly_detail/{t}",
                      lambda tt=t: queries.get_gl_anomaly_detail(tt, company)))
    return calls


def main() -> int:
    audit_dir = Path(tempfile.mkdtemp(prefix="cmt_audit_"))
    copies = prepare_company_copies(audit_dir)
    if not copies:
        print("error: no data/*.db found", file=sys.stderr)
        return 1

    # Strict mode: _safe_query must NOT swallow errors during the audit —
    # a swallowed column error is exactly the CONTAINERN failure mode.
    orig_safe = q._safe_query
    q._safe_query = q.query

    os.environ["DATA_DIR"] = str(audit_dir)  # gl_anomalies scans DATA_DIR

    failures: list[tuple[str, str, str]] = []
    total = 0
    try:
        for db_path in copies:
            company = db_path.stem.upper()
            app_db.set_active_db(str(db_path))
            try:
                for label, fn in branch_calls(q, company):
                    total += 1
                    try:
                        fn()
                    except Exception as exc:  # noqa: BLE001 — the audit reports any failure
                        failures.append((company, label, f"{type(exc).__name__}: {exc}"))

                # Semantic consistency: rollup counts must equal detail totals
                # (the bad_date GLOB defect shipped because count_sql and
                # data_sql drifted — each half "worked" in isolation).
                for atype in ("unbalanced", "orphan", "bad_date", "bad_dk"):
                    rollup = next((r for r in q.get_gl_anomalies(company)
                                   if r["type"] == atype), None)
                    rollup_count = rollup["count"] if rollup else 0
                    detail = q.get_gl_anomaly_detail(atype, company)
                    if rollup_count != detail["total"]:
                        failures.append((
                            company,
                            f"consistency/gl_{atype}",
                            f"rollup count {rollup_count} != detail total {detail['total']} "
                            "— rollup/detail SQL drifted",
                        ))
            finally:
                app_db.set_active_db(None)
    finally:
        q._safe_query = orig_safe
        os.environ.pop("DATA_DIR", None)

    print(f"audited {total} (function x branch) calls across {len(copies)} real company DBs")
    if failures:
        print(f"\nPRODUCTION FAILURES ({len(failures)}):")
        for company, label, err in failures:
            print(f"  [{company}] {label}: {err}")
        return 1
    print("all branches OK on production schemas")
    shutil.rmtree(audit_dir, ignore_errors=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
