"""Company-filter coverage for the sales queries.

The sales functions filter by company via a `REFSHITMEN IN (subquery)` on
UnifiedShipment joined to CONSIGNEE/SHIPPER. Regression tests for the
`_company_filter` helper: the filter must match case-insensitively on either
name column, and the generated subquery must stay syntactically balanced
(the pre-helper copies were missing their opening paren).
"""
import sqlite3

from app import db
from app import queries as q


def _make_sales_db(tmp_path):
    """Minimal UnifiedShipment/CONSIGNEE/SHIPPER/INVOICE schema + rows."""
    p = tmp_path / "sales.db"
    conn = sqlite3.connect(p)
    conn.executescript(
        """
        CREATE TABLE UnifiedShipment (
            REFSHITMEN TEXT PRIMARY KEY, TYPETRAN TEXT,
            KD_CONSIG TEXT, KD_SHIPPER TEXT, DATE TEXT, SALES TEXT
        );
        CREATE TABLE CONSIGNEE (KD_CONSIG TEXT, NAME TEXT);
        CREATE TABLE SHIPPER (KD_SHIPPER TEXT, NAME TEXT);
        CREATE TABLE INVOICE (
            NOINVOICE TEXT, REFSHITMEN TEXT, EI TEXT, DATEOFISSU TEXT,
            GRAND_USD REAL, GRAND_RP REAL, BYR_USD REAL, BYR_RP REAL
        );
        """
    )
    # R1 = consignee-side Trisco (import), R2 = shipper-side ACME (export).
    conn.execute("INSERT INTO UnifiedShipment VALUES ('R1','I','C1','S9','2026-01-15','SL1')")
    conn.execute("INSERT INTO UnifiedShipment VALUES ('R2','E','C9','S2','2026-02-10','SL2')")
    conn.execute("INSERT INTO CONSIGNEE VALUES ('C1','PT TRISCO INDONESIA')")
    conn.execute("INSERT INTO SHIPPER VALUES ('S2','ACME EXPORT CO')")
    conn.executemany(
        "INSERT INTO INVOICE VALUES (?,?,?,?,?,?,?,?)",
        [
            ("INV1", "R1", "I", "2026-01-20", 100.0, 1_500_000.0, 0, 0),
            ("INV2", "R1", "I", "2026-01-25", 50.0, 750_000.0, 0, 0),
            ("INV3", "R2", "E", "2026-02-11", 80.0, 1_200_000.0, 0, 0),
        ],
    )
    conn.commit()
    conn.close()
    return p


def _with_active_db(p):
    """Decorator-style helper: run the test body against the mini DB."""
    class _Ctx:
        def __enter__(self):
            db.set_active_db(str(p))

        def __exit__(self, *exc):
            db.set_active_db(None)
    return _Ctx()


def test_sales_summary_company_filter(tmp_path):
    """Summary totals scope to the company, matching case-insensitively."""
    with _with_active_db(_make_sales_db(tmp_path)):
        s = q.get_sales_summary(company_filter="trisco")
        assert s["total_invoices"] == 2          # only R1's invoices
        assert s["total_usd"] == 150.0           # 100 + 50
        assert s["import_count"] == 2            # both EI='I'
        assert s["export_count"] == 0

        unfiltered = q.get_sales_summary()
        assert unfiltered["total_invoices"] == 3


def test_sales_monthly_trend_company_filter(tmp_path):
    """Trend subquery scopes months to the company."""
    with _with_active_db(_make_sales_db(tmp_path)):
        rows = q.get_sales_monthly_trend(company_filter="Trisco")
        assert len(rows) == 1
        assert rows[0]["month"] == "2026-01"
        assert rows[0]["invoices"] == 2
        assert rows[0]["usd"] == 150.0
