"""
Simplified queries for the main dashboard.
Shipments split: NA.DBF = Import, CARGO.DBF = Export & other.
"""
from pathlib import Path
import os
import sys

from .db import query, query_one, table_count, _table_exists
from .tables import MANIFEST_TABLE, MANIFEST_DEPENDENCY

# make tools/ importable (single source of truth for view SQL)
# Container layout is /app/tools; repo layout is <root>/tools.
_TOOLS_CANDIDATES = [
    Path(__file__).resolve().parents[2] / "tools",  # repo: <root>/tools
    Path(__file__).resolve().parents[1] / "tools",  # container: /app/tools
]
_TOOLS = next((p for p in _TOOLS_CANDIDATES if p.is_dir()), _TOOLS_CANDIDATES[0])
if str(_TOOLS) not in sys.path:
    sys.path.insert(0, str(_TOOLS))

from schema import create_unified_view  # noqa: E402,F401  (re-exported for main.py)


def _company_filter(company_filter: str, a: str = "co", b: str = "sh") -> tuple[str, list[str]]:
    """Case-insensitive company match on two joined name columns.

    Returns a parenthesized SQL condition plus its two LIKE params:
    (UPPER(COALESCE(a.NAME,'')) LIKE ? OR UPPER(COALESCE(b.NAME,'')) LIKE ?)
    Callers join it into their WHERE with whatever prefix they need.
    """
    like = f"%{company_filter.upper()}%"
    return (f"(UPPER(COALESCE({a}.NAME,'')) LIKE ?"
            f" OR UPPER(COALESCE({b}.NAME,'')) LIKE ?)", [like, like])


def _has_user_table() -> bool:
    """Check if USER table exists in the current company database."""
    try:
        return query_one(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='USER'"
        ) is not None
    except Exception:
        return False


def get_summary(company_filter: str = ""):
    """Fetches high-level stats for the main dashboard."""
    if company_filter:
        company_sql, company_params = _company_filter(company_filter, "c", "s")
        inv_cond, inv_params = _company_filter(company_filter, "co", "sh")
        shipment_row = query_one(
            "SELECT COUNT(*) AS c FROM UnifiedShipment u "
            "LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG "
            "LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER "
            "WHERE u.DATE IS NOT NULL AND u.DATE != '' AND " + company_sql,
            company_params,
        )
        import_row = query_one(
            "SELECT COUNT(*) AS c FROM UnifiedShipment u "
            "LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG "
            "LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER "
            "WHERE u.TYPETRAN = 'I' AND u.DATE IS NOT NULL AND u.DATE != '' AND " + company_sql,
            company_params,
        )
        export_row = query_one(
            "SELECT COUNT(*) AS c FROM UnifiedShipment u "
            "LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG "
            "LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER "
            "WHERE u.TYPETRAN != 'I' AND u.DATE IS NOT NULL AND u.DATE != '' AND " + company_sql,
            company_params,
        )
        invoice_row = query_one(
            "SELECT COUNT(*) AS c FROM INVOICE i "
            "LEFT JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN "
            "LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
            "LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
            "WHERE " + inv_cond,
            inv_params,
        )
        shipment_count = shipment_row['c'] if shipment_row else 0
        import_count = import_row['c'] if import_row else 0
        export_count = export_row['c'] if export_row else 0
        invoice_count = invoice_row['c'] if invoice_row else 0
    else:
        shipment_count = table_count("UnifiedShipment")
        import_count = query_one("SELECT COUNT(*) as c FROM UnifiedShipment WHERE TYPETRAN = 'I'")['c']
        export_count = query_one("SELECT COUNT(*) as c FROM UnifiedShipment WHERE TYPETRAN != 'I'")['c']
        invoice_count = table_count("INVOICE")

    vessel_count = table_count("VESSEL")
    recent_shipments = get_recent_shipments(company_filter=company_filter)

    return {
        "shipments": shipment_count,
        "imports": import_count,
        "exports": export_count,
        "invoices": invoice_count,
        "voyages": vessel_count,
        "recent_shipments": recent_shipments,
    }


_SHIPMENT_JOINS = """
FROM UnifiedShipment u
LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG
LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER
LEFT JOIN (SELECT REFSHITMEN, SUM(GRAND_USD) AS GRAND_USD, SUM(GRAND_RP) AS GRAND_RP
           FROM OPRCOST GROUP BY REFSHITMEN) oc ON oc.REFSHITMEN = u.REFSHITMEN
LEFT JOIN (SELECT REFSHITMEN, SUM(GRAND_USD) AS GRAND_USD, SUM(GRAND_RP) AS GRAND_RP,
                  MIN(LUNAS) AS LUNAS,
                  GROUP_CONCAT(DISTINCT NOINVOICE) AS NOINVOICE,
                  SUM(CASE WHEN BYR_USD IS NULL AND DIRPKAN IS NULL THEN NULL
                           ELSE COALESCE(BYR_USD, 0) + COALESCE(DIRPKAN, 0) END) AS PAY_USD,
                  SUM(CASE WHEN BYR_RP IS NULL AND DIUSDKAN IS NULL THEN NULL
                           ELSE COALESCE(BYR_RP, 0) + COALESCE(DIUSDKAN, 0) END) AS PAY_IDR
           FROM INVOICE GROUP BY REFSHITMEN) inv ON inv.REFSHITMEN = u.REFSHITMEN
LEFT JOIN (SELECT REFSHITMEN, SUM(TOTAL) AS TOTAL, SUM(TOTAL_IDR) AS TOTAL_IDR,
                  SUM(CASE WHEN BAYAR IS NULL AND DIRPKAN IS NULL THEN NULL
                           ELSE COALESCE(BAYAR, 0) + COALESCE(DIRPKAN, 0) END) AS PAY_USD,
                  SUM(CASE WHEN BAYAR_IDR IS NULL AND DIUSDKAN IS NULL THEN NULL
                           ELSE COALESCE(BAYAR_IDR, 0) + COALESCE(DIUSDKAN, 0) END) AS PAY_IDR
           FROM DRCRFULL GROUP BY REFSHITMEN) dn ON dn.REFSHITMEN = u.REFSHITMEN
"""

_SHIPMENT_SELECT = (
    "SELECT u.UNIK, u.TYPETRAN AS KIND, u.DATE, u.REFSHITMEN, u.BLNO3, "
    "inv.NOINVOICE, "
    "CASE WHEN u.TYPETRAN='I' THEN c.NAME ELSE s.NAME END AS CUSTOMER, "
    "oc.GRAND_USD AS OPR_USD, oc.GRAND_RP AS OPR_RP, "
    "inv.GRAND_USD AS INV_USD, inv.GRAND_RP AS INV_RP, "
    "inv.LUNAS AS PAID, "
    "inv.PAY_USD AS INV_PAY_USD, inv.PAY_IDR AS INV_PAY_IDR, "
    "dn.TOTAL AS DNCN_USD, dn.TOTAL_IDR AS DNCN_IDR, "
    "dn.PAY_USD AS DNCN_PAY_USD, dn.PAY_IDR AS DNCN_PAY_IDR "
    + _SHIPMENT_JOINS
)

# Values are kept raw (no COALESCE-to-0): the template renders a dash only
# when a value is genuinely missing, never for a real 0.
def get_recent_shipments(limit: int = 10, company_filter: str = ""):
    """Recent shipments joined with OprCost (GRAND_USD/GRAND_RP) and Invoice
    (GRAND_USD/GRAND_RP + LUNAS = Paid flag).

    One shipment may have multiple OPRCOST/INVOICE rows; we aggregate with
    SUM so each shipment appears exactly once.
    """
    where = "WHERE u.DATE IS NOT NULL AND u.DATE != ''"
    params: list = []
    if company_filter:
        cond, cparams = _company_filter(company_filter, "c", "s")
        where += " AND " + cond
        params += cparams
    return query(
        _SHIPMENT_SELECT
        + where
        + " ORDER BY u.DATE DESC LIMIT ?",
        params + [limit],
    )


_UNPAID_SORT_COLUMNS = {
    "date": "u.DATE",
    "reference": "u.REFSHITMEN",
    "customer": "CUSTOMER",
}


def get_unpaid_shipments(page: int = 1, per_page: int = 10, search: str = "",
                         sort: str = "date", sort_dir: str = "desc",
                         company_filter: str = ""):
    """Paginated list of unpaid shipments (no payments recorded on invoice or DN/CN).

    Returns {"rows", "total", "page", "per_page", "pages"}. Optional search
    matches reference, BL no and customer name (case-insensitive substring).
    """
    page = max(int(page), 1)
    per_page = max(int(per_page), 1)
    where = ("WHERE u.DATE IS NOT NULL AND u.DATE != ''"
             " AND COALESCE(inv.PAY_USD, 0) = 0 AND COALESCE(inv.PAY_IDR, 0) = 0"
             " AND COALESCE(dn.PAY_USD, 0) = 0 AND COALESCE(dn.PAY_IDR, 0) = 0")
    params: list = []
    if company_filter:
        cond, cparams = _company_filter(company_filter, "c", "s")
        where += " AND " + cond
        params += cparams
    if search and search.strip():
        like = f"%{search.strip().upper()}%"
        where += (
            " AND (UPPER(u.REFSHITMEN) LIKE ? OR UPPER(u.BLNO3) LIKE ?"
            " OR UPPER(COALESCE(c.NAME,'')) LIKE ? OR UPPER(COALESCE(s.NAME,'')) LIKE ?)"
        )
        params += [like] * 4

    count_row = query_one(
        "SELECT COUNT(*) AS c " + _SHIPMENT_JOINS + where,
        params,
    )
    total = count_row["c"] if count_row else 0
    pages = max(1, (total + per_page - 1) // per_page)
    page = min(page, pages)

    order_col = _UNPAID_SORT_COLUMNS.get(sort, "u.DATE")
    direction = "ASC" if sort_dir == "asc" else "DESC"
    order = f"ORDER BY {order_col} {direction}, u.DATE DESC"

    rows = query(
        _SHIPMENT_SELECT
        + where
        + " " + order
        + " LIMIT ? OFFSET ?",
        params + [per_page, (page - 1) * per_page],
    )
    # Aggregate totals for unpaid shipments
    totals_row = query_one(
        "SELECT COUNT(*) AS c, "
        "SUM(CASE WHEN inv.GRAND_USD IS NOT NULL THEN inv.GRAND_USD ELSE 0 END) AS total_usd, "
        "SUM(CASE WHEN inv.GRAND_RP IS NOT NULL THEN inv.GRAND_RP ELSE 0 END) AS total_idr "
        + _SHIPMENT_JOINS + where,
        params,
    )
    return {
        "rows": rows,
        "total": total,
        "total_usd": totals_row["total_usd"] if totals_row else 0,
        "total_idr": totals_row["total_idr"] if totals_row else 0,
        "page": page,
        "per_page": per_page,
        "pages": pages,
    }

_SHIPMENTS_SORT_COLUMNS = {
    "date": "u.DATE",
    "reference": "u.REFSHITMEN",
    "customer": "CUSTOMER",
}

def get_shipments(page: int = 1, per_page: int = 25, search: str = "",
                  sort: str = "date", sort_dir: str = "desc",
                  company_filter: str = "", type_filter: str = "",
                  invoice_filter: str = ""):
    """Paginated shipments list — all shipments (paid + unpaid).

    Uses the same rich query as the unpaid card (Opr Cost, Invoice,
    Inv Payment, DNCN, DNCR Payment). For customer role, filter to their
    company (case-insensitive partial match on shipper/consignee).
    type_filter: 'import' → I, 'export' → E, 'other' → D/T/B/etc.
    invoice_filter: 'unissued' → no invoices, 'issued' → has invoices, 'paid' → payments >= invoice total, 'unpaid' → has invoices but payments < total.
    """
    page = max(int(page), 1)
    per_page = max(int(per_page), 1)
    where = "WHERE u.DATE IS NOT NULL AND u.DATE != ''"
    if type_filter == 'import':
        where += " AND u.TYPETRAN = 'I'"
    elif type_filter == 'export':
        where += " AND u.TYPETRAN = 'E'"
    elif type_filter == 'other':
        where += " AND u.TYPETRAN NOT IN ('I', 'E')"
    if invoice_filter == 'unissued':
        where += " AND (inv.NOINVOICE IS NULL OR inv.NOINVOICE = '')"
    elif invoice_filter == 'issued':
        where += " AND inv.NOINVOICE IS NOT NULL AND inv.NOINVOICE != ''"
    elif invoice_filter == 'issued_unpaid':
        where += " AND inv.NOINVOICE IS NOT NULL AND inv.NOINVOICE != '' "
        where += " AND (COALESCE(inv.PAY_USD, 0) + COALESCE(inv.PAY_IDR, 0)) "
        where += " < (COALESCE(inv.GRAND_USD, 0) + COALESCE(inv.GRAND_RP, 0))"
    elif invoice_filter == 'paid':
        where += " AND inv.NOINVOICE IS NOT NULL AND inv.NOINVOICE != '' "
        where += " AND (COALESCE(inv.PAY_USD, 0) + COALESCE(inv.PAY_IDR, 0)) "
        where += " >= (COALESCE(inv.GRAND_USD, 0) + COALESCE(inv.GRAND_RP, 0))"
    params: list = []
    if company_filter:
        cond, cparams = _company_filter(company_filter, "c", "s")
        where += " AND " + cond
        params += cparams
    if search and search.strip():
        like = f"%{search.strip().upper()}%"
        where += (
            " AND (UPPER(u.REFSHITMEN) LIKE ? OR UPPER(u.BLNO3) LIKE ?"
            " OR UPPER(COALESCE(c.NAME,'')) LIKE ? OR UPPER(COALESCE(s.NAME,'')) LIKE ?)"
        )
        params += [like] * 4

    count_row = query_one(
        "SELECT COUNT(*) AS c " + _SHIPMENT_JOINS + where,
        params,
    )
    total = count_row["c"] if count_row else 0
    pages = max(1, (total + per_page - 1) // per_page)
    page = min(page, pages)

    order_col = _SHIPMENTS_SORT_COLUMNS.get(sort, "u.DATE")
    direction = "ASC" if sort_dir == "asc" else "DESC"
    order = f"ORDER BY {order_col} {direction}, u.DATE DESC"

    rows = query(
        _SHIPMENT_SELECT + where + " " + order + " LIMIT ? OFFSET ?",
        params + [per_page, (page - 1) * per_page],
    )
    return {
            "rows": rows,
            "total": total,
            "page": page,
            "per_page": per_page,
            "pages": pages,
        }


# ===================================================================
# Projects/Jobs Summary (F-22) — merged CARGO+NA via UnifiedShipment,
# with FCL/LCL split for DR/CN (Debit/Credit Notes).
# Field → table.column mapping per PRD §3.2.1 (verified against
# tests/parity/fixtures/real_schema.json for all 5 companies CME/CMT/CSM/MAL/SBY):
#
# _R CONVENTION (confirmed in aging_service.py:24-28,62-67,78-88; queries.py:560-626):
#   - Base tables (INVOICE, OPRCOST, DRCRFULL) are parent/header documents.
#   - _R tables (INVOICE_R, OPRCOST_R, DRCRFULL_R) are line-item/detail children
#     linked via parent key (INVOICE_R.K_INVOICE→INVOICE.K_INVOICE,
#     OPRCOST_R.UOPRCOST→OPRCOST.UOPRCOST, DRCRFULL_R.UNIK→DRCRFULL.UNIK).
#   - §3.2.1 Summary uses PARENT tables only (aggregated totals), not _R lines.
#
# 1. Status of B/L
#    Base tables: CARGO (export) + NA (import), merged as UnifiedShipment view.
#    UnifiedShipment columns (real_schema.json: CME.UnifiedShipment.* etc.):
#      - BLNO3          — B/L number (CARGO.BLNO3 / NA.BLNO3)
#      - MBL_NO         — Master B/L number (NA.MBL_NO)
#      - BL_NO          — House B/L number (NA.BL_NO)
#      - PRINT (BL_PRINTED)  — B/L print flag (CARGO.PRINT / NA.PRINT)
#      - READO (BL_READ)     — B/L read/release flag (NA.READO; CARGO has no READO)
#      - PRINTDATE (BL_PRINT_DATE) — B/L print date (CARGO.PRINTDATE / NA.PRINTDATE)
#    Meaning: composite B/L status — numbers identify the document; PRINT/READO/PRINTDATE
#      track issuance/release state. No single "status" column exists; derived from flags.
#    Schema key paths: CME.UnifiedShipment.BLNO3, CME.UnifiedShipment.MBL_NO, ...
#
# 2. Invoice data
#    Table: INVOICE (parent; real_schema.json: CME.INVOICE.* etc.)
#    Columns used (aggregated per REFSHITMEN):
#      - GRAND_USD     — invoice total USD
#      - GRAND_RP      — invoice total IDR
#      - LUNAS         — paid flag (0/1); MIN(LUNAS) over group = any unpaid
#      - NOINVOICE     — invoice number(s); GROUP_CONCAT for display
#    Meaning: financial summary of invoices linked to the shipment.
#    Schema key paths: CME.INVOICE.GRAND_USD, CME.INVOICE.GRAND_RP, CME.INVOICE.LUNAS, CME.INVOICE.NOINVOICE
#
# 3. Operational Cost data
#    Table: OPRCOST (parent; real_schema.json: CME.OPRCOST.* etc.)
#    Columns used (aggregated per REFSHITMEN):
#      - GRAND_USD     — operational cost total USD
#      - GRAND_RP      — operational cost total IDR
#    Meaning: cost summary for the shipment (vendor payables, port charges, etc.).
#    Note: OPRCOST_R (detail lines) NOT used here — summary uses parent totals only.
#    Schema key paths: CME.OPRCOST.GRAND_USD, CME.OPRCOST.GRAND_RP
#
# 4. DR/CN FCL (Full Container Load)
#    Table: DRCRFULL (parent; real_schema.json: CME.DRCRFULL.* etc.)
#    Columns used:
#      - TOTAL       — debit/credit note total USD
#      - TOTAL_IDR   — debit/credit note total IDR
#    Filter: joined to UnifiedShipment on REFSHITMEN, where shipment.DIMENSI IN
#      ('20\'','40\'','40\' HQ','45\'') — container sizes indicating FCL.
#    Meaning: sum of DR/CN amounts for FCL shipments.
#    Schema key paths: CME.DRCRFULL.TOTAL, CME.DRCRFULL.TOTAL_IDR
#
# 5. DR/CN LCL (Less than Container Load)
#    Table: DRCRFULL (parent; real_schema.json: CME.DRCRFULL.* etc.)
#    Columns used: same as FCL (TOTAL, TOTAL_IDR)
#    Filter: where shipment.DIMENSI IN ('CBM','KGS','M3','CTNS','DOC') —
#      weight/volume units indicating LCL.
#    Meaning: sum of DR/CN amounts for LCL shipments.
#    Schema key paths: CME.DRCRFULL.TOTAL, CME.DRCRFULL.TOTAL_IDR
#
# ALL FIVE ELEMENTS RESOLVED — no flags.
# Decision: NEW PAGE (/projects-jobs) — separate from shipments because legacy menu row 3
#   "Status B/L & Invoice" is a distinct report with FCL/LCL split not present on shipments.
# ===================================================================

# ===================================================================
# FCL / LCL DIMENSI split — matches docs/data-provenance.md and summary query
# FCL: container sizes 20', 40', 40' HQ, 45' (all start with 20/40/45)
# LCL: CBM, KGS, M3, CTNS, DOC
# Classifier is prefix-based to cover real-data variants (20', 40' HQ, etc).
_FCL_PREFIXES = ("20", "40", "45")
_LCL_DIMENSI = {"CBM", "KGS", "M3", "CTNS", "DOC"}

def _dim_group(dimensi: str | None) -> str:
    """Return 'FCL' or 'LCL' for a shipment's DIMENSI.
    Matches summary-query split logic (docs/data-provenance.md)."""
    d = (dimensi or "").strip().upper()
    if d.startswith(_FCL_PREFIXES):
        return "FCL"
    if d in _LCL_DIMENSI:
        return "LCL"
    # Fallback: unknown/empty -> LCL (majority of unclassified in real data)
    return "LCL"

# ===================================================================
# Projects/Jobs Summary (F-22): merged CARGO + NA, FCL/LCL split
# ===================================================================

_FCL_DIMENSI = ("'20'''", "'40'''", "'40'' HQ'", "'45'''")
_LCL_DIMENSI = ("'CBM'", "'KGS'", "'M3'", "'CTNS'", "'DOC'")

_PROJECTS_JOBS_JOINS = """
FROM UnifiedShipment u
LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG
LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER
LEFT JOIN (
    SELECT REFSHITMEN, SUM(GRAND_USD) AS GRAND_USD, SUM(GRAND_RP) AS GRAND_RP
    FROM OPRCOST GROUP BY REFSHITMEN
) oc ON oc.REFSHITMEN = u.REFSHITMEN
LEFT JOIN (
    SELECT REFSHITMEN, SUM(GRAND_USD) AS GRAND_USD, SUM(GRAND_RP) AS GRAND_RP,
           MIN(LUNAS) AS LUNAS,
           GROUP_CONCAT(DISTINCT NOINVOICE) AS NOINVOICE
    FROM INVOICE GROUP BY REFSHITMEN
) inv ON inv.REFSHITMEN = u.REFSHITMEN
LEFT JOIN (
    SELECT d.REFSHITMEN,
           SUM(CASE WHEN u.DIMENSI IN ({fcl}) THEN d.TOTAL ELSE 0 END) AS DNCN_FCL_USD,
           SUM(CASE WHEN u.DIMENSI IN ({fcl}) THEN d.TOTAL_IDR ELSE 0 END) AS DNCN_FCL_IDR,
           SUM(CASE WHEN u.DIMENSI IN ({lcl}) THEN d.TOTAL ELSE 0 END) AS DNCN_LCL_USD,
           SUM(CASE WHEN u.DIMENSI IN ({lcl}) THEN d.TOTAL_IDR ELSE 0 END) AS DNCN_LCL_IDR
    FROM DRCRFULL d
    JOIN UnifiedShipment u ON d.REFSHITMEN = u.REFSHITMEN
    GROUP BY d.REFSHITMEN
) dn ON dn.REFSHITMEN = u.REFSHITMEN
""".format(fcl=",".join(_FCL_DIMENSI), lcl=",".join(_LCL_DIMENSI))

_PROJECTS_JOBS_SELECT = (
    "SELECT u.UNIK, u.TYPETRAN AS KIND, u.DATE, u.REFSHITMEN, "
    "u.BLNO3, u.BL_NO, u.MBL_NO, "
    "u.PRINT AS BL_PRINTED, u.READO AS BL_READ, u.PRINTDATE AS BL_PRINT_DATE, "
    "u.CONTAINERN, u.DIMENSI, u.PORTOFLOAD, u.CITY_DISCH, u.PORTOFDISC, "
    "u.SHIPPER, u.NOTIFYPART, u.SHIPPINGMA, "
    "u.GW, u.NW, u.TOT_EUR, u.TOT_USD, u.TOT_IDR, "
    "CASE WHEN u.TYPETRAN='I' THEN c.NAME ELSE s.NAME END AS CUSTOMER, "
    "oc.GRAND_USD AS OPR_USD, oc.GRAND_RP AS OPR_RP, "
    "inv.GRAND_USD AS INV_USD, inv.GRAND_RP AS INV_RP, inv.LUNAS AS INV_PAID, inv.NOINVOICE, "
    "COALESCE(dn.DNCN_FCL_USD, dn.DNCN_FCL_IDR) AS DR_CN_FCL, "
    "COALESCE(dn.DNCN_LCL_USD, dn.DNCN_LCL_IDR) AS DR_CN_LCL, "
    "dn.DNCN_FCL_USD, dn.DNCN_FCL_IDR, dn.DNCN_LCL_USD, dn.DNCN_LCL_IDR "
    + _PROJECTS_JOBS_JOINS
)


def get_projects_jobs_summary(
    page: int = 1,
    per_page: int = 25,
    search: str = "",
    sort: str = "date",
    sort_dir: str = "desc",
    company_filter: str = "",
    type_filter: str = "",
) -> dict:
    """Paginated Projects/Jobs Summary (F-22).
    Merged CARGO+NA view with FCL/LCL split for DR/CN.
    Returns dict with rows, total, page, per_page, pages.
    """
    page = max(int(page), 1)
    per_page = max(int(per_page), 1)
    where = "WHERE u.DATE IS NOT NULL AND u.DATE != ''"
    params: list = []
    if type_filter == "import":
        where += " AND u.TYPETRAN = 'I'"
    elif type_filter == "export":
        where += " AND u.TYPETRAN = 'E'"
    elif type_filter == "other":
        where += " AND u.TYPETRAN NOT IN ('I', 'E')"
    if company_filter:
        cond, cparams = _company_filter(company_filter, "c", "s")
        where += " AND " + cond
        params += cparams
    if search and search.strip():
        like = f"%{search.strip().upper()}%"
        where += (
            " AND (UPPER(u.REFSHITMEN) LIKE ? OR UPPER(u.BLNO3) LIKE ?"
            " OR UPPER(u.BL_NO) LIKE ? OR UPPER(u.MBL_NO) LIKE ?"
            " OR UPPER(COALESCE(c.NAME,'')) LIKE ? OR UPPER(COALESCE(s.NAME,'')) LIKE ?)"
        )
        params += [like] * 6

    _sort_cols = {
        "date": "u.DATE",
        "reference": "u.REFSHITMEN",
        "customer": "CUSTOMER",
        "bl_status": "u.PRINT",
    }
    order_col = _sort_cols.get(sort, "u.DATE")
    direction = "ASC" if sort_dir == "asc" else "DESC"
    order = f"ORDER BY {order_col} {direction}, u.DATE DESC"

    count_row = query_one(
        "SELECT COUNT(*) AS c " + _PROJECTS_JOBS_JOINS + where,
        params,
    )
    total = count_row["c"] if count_row else 0
    pages = max(1, (total + per_page - 1) // per_page)
    page = min(page, pages)

    rows = query(
        _PROJECTS_JOBS_SELECT + where + " " + order + " LIMIT ? OFFSET ?",
        params + [per_page, (page - 1) * per_page],
    )
    return {
        "rows": rows,
        "total": total,
        "page": page,
        "per_page": per_page,
        "pages": pages,
    }


def get_company_names() -> list[str]:
    """Unique company names from SHIPPER + CONSIGNEE, sorted alphabetically."""
    rows = query(
        "SELECT DISTINCT NAME FROM SHIPPER WHERE NAME IS NOT NULL AND NAME != '' "
        "UNION "
        "SELECT DISTINCT NAME FROM CONSIGNEE WHERE NAME IS NOT NULL AND NAME != '' "
        "ORDER BY NAME"
    )
    return [r["NAME"] for r in rows]


def get_top_customers(limit: int = 5):
    """Top customers by shipment count, split by import vs export."""
    import_rows = query(
        "SELECT COALESCE(c.NAME, u.KD_CONSIG, '-') AS name, COUNT(*) AS cnt "
        "FROM UnifiedShipment u "
        "LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG "
        "WHERE u.TYPETRAN = 'I' AND u.DATE IS NOT NULL AND u.DATE != '' "
        "GROUP BY name ORDER BY cnt DESC LIMIT ?",
        (limit,),
    )
    export_rows = query(
        "SELECT COALESCE(s.NAME, u.KD_SHIPPER, '-') AS name, COUNT(*) AS cnt "
        "FROM UnifiedShipment u "
        "LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER "
        "WHERE u.TYPETRAN != 'I' AND u.DATE IS NOT NULL AND u.DATE != '' "
        "GROUP BY name ORDER BY cnt DESC LIMIT ?",
        (limit,),
    )
    return {"import": import_rows, "export": export_rows}


_INVOICE_JOINS = """
FROM INVOICE i
LEFT JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN
LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG
LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER
"""

_INVOICE_SELECT = (
    "SELECT i.rowid_ AS rowid_, i.NOINVOICE, i.REFSHITMEN, i.EI, i.DATEOFISSU, "
    "i.SHIPPER AS SHIPPER_CODE, "
    "CASE WHEN i.EI = 'I' THEN co.NAME ELSE sh.NAME END AS CUSTOMER, "
    "i.GRAND_USD, i.GRAND_RP, i.BYR_USD, i.BYR_RP, "
    "i.SELISIH_US, i.SELISIH_ID, i.LUNAS, i.REMARK "
    + _INVOICE_JOINS
)

_INVOICE_SORT_COLUMNS = {
    "date": "i.DATEOFISSU",
    "reference": "i.REFSHITMEN",
    "customer": "CUSTOMER",
}


def get_invoices(page: int = 1, per_page: int = 25, search: str = "",
                 sort: str = "date", sort_dir: str = "desc",
                 company_filter: str = ""):
    """Paginated invoice list. Customers only see invoices linked to their company."""
    page = max(int(page), 1)
    per_page = max(int(per_page), 1)
    where = "WHERE i.NOINVOICE IS NOT NULL AND i.NOINVOICE != ''"
    params: list = []
    if company_filter:
        cond, cparams = _company_filter(company_filter)
        where += " AND " + cond
        params += cparams
    if search and search.strip():
        like = f"%{search.strip().upper()}%"
        where += (
            " AND (UPPER(i.NOINVOICE) LIKE ? OR UPPER(i.REFSHITMEN) LIKE ?"
            " OR UPPER(COALESCE(co.NAME,'')) LIKE ? OR UPPER(COALESCE(sh.NAME,'')) LIKE ?)"
        )
        params += [like] * 4

    count_row = query_one(
        "SELECT COUNT(*) AS c " + _INVOICE_JOINS + where,
        params,
    )
    total = count_row["c"] if count_row else 0
    pages = max(1, (total + per_page - 1) // per_page)
    page = min(page, pages)

    order_col = _INVOICE_SORT_COLUMNS.get(sort, "i.DATEOFISSU")
    direction = "ASC" if sort_dir == "asc" else "DESC"
    order = f"ORDER BY {order_col} {direction}, i.DATEOFISSU DESC"

    rows = query(
        _INVOICE_SELECT + where + " " + order + " LIMIT ? OFFSET ?",
        params + [per_page, (page - 1) * per_page],
    )
    return {
        "rows": rows,
        "total": total,
        "page": page,
        "per_page": per_page,
        "pages": pages,
    }


def _safe_query(sql: str, params: list | None = None) -> list:
    """Run a query, returning [] if the table doesn't exist."""
    try:
        return query(sql, params)
    except Exception:
        return []


def get_shipment_detail(refshifmen: str) -> dict | None:
    """Full detail for a single shipment: header, opr costs, invoices, DR/CN notes."""
    # Shipment header with agent name and vessel name
    header = query_one(
            "SELECT u.UNIK, u.TYPETRAN AS KIND, u.DATE, u.REFSHITMEN, u.BLNO3, "
            "u.DIMENSI, "
            "CASE WHEN u.TYPETRAN='I' THEN co.NAME ELSE sh.NAME END AS CUSTOMER, "
            "u.KD_CONSIG, u.KD_SHIPPER, u.CONTAINERN, u.PORTOFLOAD, u.PORTOFDISC, "
            "u.SHIPPINGMA, u.DESCRIPTIO AS DESCRIPTION, "
            "u.CONSIGNEE AS CONSIGNEE_NAME, u.SHIPPER AS SHIPPER_NAME, "
            "u.SAIDTOCONT, u.BL_NO, u.MBL_NO, u.ETD, u.ETA, u.ATA, "
            "ag.NAME AS AGENT_NAME, v.NM_VESSEL AS VESSEL_NAME, u.EXVOYAGE "
            "FROM UnifiedShipment u "
            "LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
            "LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
            "LEFT JOIN AGENT ag ON u.AGENT = ag.AGENT AND u.CODE = ag.CODE "
            "LEFT JOIN VESSEL v ON u.KD_EXVESSE = v.KD_VESSEL "
            "WHERE u.REFSHITMEN = ?",
            (refshifmen,),
        )
    if not header:
        return None

    # Operational cost headers
    # Source table: OPRCOST (parent) + OPRCOST_R (line). NA_D-vs-OPRCOST_R
    # resolution: NA_D is NA's cost child (NA_D.UNIK -> NA.UNIK); OPRCOST_R is
    # OPRCOST's child. Their overlap is 0, so this Detail section reads the
    # operational-cost lines from OPRCOST_R (not NA_D). Choice stated here.
    opr_headers = _safe_query(
        "SELECT UOPRCOST, NOMOR, TANGGAL, EXRATE, GRAND_USD, GRAND_RP "
        "FROM OPRCOST WHERE REFSHITMEN = ? ORDER BY TANGGAL",
        [refshifmen],
    )
    # Operational cost line items (grouped by parent)
    opr_items = _safe_query(
        "SELECT r.UOPRCOST, r.JENISCOST, r.DESCRIPTIO, r.CURRENCY, "
        "r.QTY, r.TSRATE, r.JUMLAH, r.INVOICE_NO, r.INV_TGL, r.KETERANGAN "
        "FROM OPRCOST_R r JOIN OPRCOST o ON r.UOPRCOST = o.UOPRCOST "
        "WHERE o.REFSHITMEN = ? ORDER BY r.JENISCOST",
        [refshifmen],
    )
    # Group items by parent UOPRCOST
    opr_items_by_parent: dict[str, list] = {}
    for item in opr_items:
        opr_items_by_parent.setdefault(item["UOPRCOST"], []).append(item)

    # Invoices
    invoices = _safe_query(
        "SELECT NOINVOICE, DATEOFISSU, SHIPPER AS SHIPPER_CODE, "
        "EXRATE, GRAND_USD, GRAND_RP, BYR_USD, BYR_RP, "
        "SELISIH_US, SELISIH_ID, LUNAS, REMARK, EI "
        "FROM INVOICE WHERE REFSHITMEN = ? ORDER BY DATEOFISSU",
        [refshifmen],
    )
    # Invoice line items (grouped by parent NOINVOICE) — join on K_INVOICE, not NOINVOICE (child NOINVOICE differs from parent)
    inv_items_raw = _safe_query(
        "SELECT i.NOINVOICE AS PARENT_NO, r.JENISCOST, r.DESCRIPTIO, r.CURRENCY, "
        "r.QTY, r.SELLING, r.JUMLAH, r.NOTE "
        "FROM INVOICE_R r "
        "JOIN INVOICE i ON r.K_INVOICE = i.K_INVOICE "
        "WHERE i.REFSHITMEN = ? ORDER BY i.NOINVOICE, r.JENISCOST",
        [refshifmen],
    )
    inv_items_by_parent: dict[str, list] = {}
    for item in inv_items_raw:
        inv_items_by_parent.setdefault(item["PARENT_NO"], []).append(item)

    # Debit / Credit notes — classify by amount sign, not SV field.
    # Positive (or zero) total -> DN (Debit Note); Negative total -> CN.
    _all_notes = _safe_query(
        "SELECT UNIK, NO_REF, PLUS, TANGGAL, TONAME, MODE, BLNO, BLNO3, "
        "TOTAL, TOTAL_IDR, EXRATE, BAYAR, DIRPKAN, BAYAR_IDR, DIUSDKAN, LUNAS, ACCT_DRCR "
        "FROM DRCRFULL WHERE REFSHITMEN = ? "
        "ORDER BY TANGGAL",
        [refshifmen],
    )
    debit_notes: list[dict] = []
    credit_notes: list[dict] = []
    for note in _all_notes:
        amount = note["TOTAL"] or 0
        amount_idr = note["TOTAL_IDR"] or 0
        if amount < 0 or amount_idr < 0:
            credit_notes.append(note)
        else:
            debit_notes.append(note)

    # DR/CN notes split by shipment DIMENSI (FCL vs LCL)
    # Per docs/data-provenance.md, DR/CN totals on summary page are split
    # by the shipment's DIMENSI, not by note-level data.
    # This detail page mirrors that grouping.
    header = dict(header)
    dim = header.get("DIMENSI")
    dim_grp = _dim_group(dim)
    debit_notes_fcl = [n for n in debit_notes if dim_grp == "FCL"]
    debit_notes_lcl = [n for n in debit_notes if dim_grp == "LCL"]
    credit_notes_fcl = [n for n in credit_notes if dim_grp == "FCL"]
    credit_notes_lcl = [n for n in credit_notes if dim_grp == "LCL"]

    # DR/CN line items grouped by parent UNIK
    dr_items = _safe_query(
        "SELECT r.UNIK, r.JENISCOST, r.DESCRIPTIO, r.CURRENCY, "
        "r.QTY, r.UNITPRICE, r.JUMLAH "
        "FROM DRCRFULL_R r JOIN DRCRFULL d ON r.UNIK = d.UNIK "
        "WHERE d.REFSHITMEN = ? ORDER BY d.NO_REF, r.JENISCOST",
        [refshifmen],
    )
    dr_items_by_parent: dict[str, list] = {}
    for item in dr_items:
        dr_items_by_parent.setdefault(item["UNIK"], []).append(item)

    # Gross Profit = total invoice - total operational cost
    gp_row = query_one(
        "SELECT COALESCE(SUM(i.GRAND_USD), 0) - COALESCE(( "
        "SELECT SUM(o.GRAND_USD) FROM OPRCOST o WHERE o.REFSHITMEN = ?), 0) AS gp_usd, "
        "COALESCE(SUM(i.GRAND_RP), 0) - COALESCE(( "
        "SELECT SUM(o.GRAND_RP) FROM OPRCOST o WHERE o.REFSHITMEN = ?), 0) AS gp_rp "
        "FROM INVOICE i WHERE i.REFSHITMEN = ?",
        (refshifmen, refshifmen, refshifmen),
    )
    header["GP_USD"] = gp_row["gp_usd"] if gp_row else 0
    header["GP_RP"] = gp_row["gp_rp"] if gp_row else 0

    # Manifest (second-layer): wire to MANIFEST when the ingest pipeline has
    # materialized the table; otherwise render a pending-ingest state naming
    # the blocking dependency (Q-11 whitelist promotion). Table name + dep live
    # in tables.py so no raw identifier is hardcoded here or in the template.
    manifest = None
    manifest_status = "pending ingest"
    manifest_dependency = MANIFEST_DEPENDENCY
    if _table_exists(MANIFEST_TABLE):
        manifest = _safe_query(
            "SELECT NOMOR, DATE, VESSEL, CONTAINERN, SHIPPINGLI, AGENT, "
            "ETD_1, ETA_1, TERMS, PREPAREDBY, NOTE "
            "FROM " + MANIFEST_TABLE + " WHERE REFSHITMEN = ? ORDER BY DATE",
            [refshifmen],
        )
        manifest_status = "available" if manifest else "no rows"

    return {
        "header": header,
        "opr_headers": opr_headers,
        "opr_items": opr_items_by_parent,
        "invoices": invoices,
        "inv_items": inv_items_by_parent,
        "debit_notes": debit_notes,
        "credit_notes": credit_notes,
        "debit_notes_fcl": debit_notes_fcl,
        "debit_notes_lcl": debit_notes_lcl,
        "credit_notes_fcl": credit_notes_fcl,
        "credit_notes_lcl": credit_notes_lcl,
        "dr_items": dr_items_by_parent,
        "manifest": manifest,
        "manifest_status": manifest_status,
        "manifest_dependency": manifest_dependency,
        "manifest_source_table": MANIFEST_TABLE,
    }


def get_invoice_detail(k_invoice: str) -> dict | None:
    """Full detail for a single invoice (keyed by K_INVOICE): header + line items.

    Header joins the shipment for voyage context (BL/vessel/ports); the customer
    name follows the shipment-detail convention: consignee for imports (EI='I'),
    shipper otherwise. Line items resolve SATUAN names for display.
    """
    header = query_one(
        "SELECT i.K_INVOICE, i.NOINVOICE, i.EI, i.REFSHITMEN, i.DATEOFISSU, "
        "i.SHIPPER AS SHIPPER_CODE, sh.NAME AS SHIPPER_NAME, "
        "i.EXRATE, i.GRAND_USD, i.GRAND_RP, i.BYR_USD, i.BYR_RP, "
        "i.DIRPKAN, i.DIUSDKAN, i.SELISIH_US, i.SELISIH_ID, "
        "i.LUNAS, i.REMARK, i.NOTEINV, "
        # CONTAINERN is deliberately NOT selected: the DBF-driven sync owns the
        # INVOICE schema (tools/sync_db.py recreates tables from FoxPro field
        # metadata) and real company DBs have no such column. Selecting it made
        # this page 500 on production data while shipment detail (u.CONTAINERN,
        # from UnifiedShipment) kept working. The template renders the card as
        # '-' via Jinja's undefined-or-dash fallback.
        "i.BLNO, i.BLNO3, i.PORTOFLOAD, i.PORTOFDISC, "
        "i.VESSELNAME, i.ETDDATE, i.ETADATE, "
        "CASE WHEN i.EI = 'I' THEN co.NAME ELSE s.NAME END AS CUSTOMER "
        "FROM INVOICE i "
        "LEFT JOIN SHIPPER sh ON i.SHIPPER = sh.KD_SHIPPER "
        "LEFT JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN "
        "LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
        "LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER "
        "WHERE i.K_INVOICE = ?",
        (k_invoice,),
    )
    if not header:
        return None

    line_items = _safe_query(
        "SELECT r.JENISCOST, r.DESCRIPTIO, r.CURRENCY, r.QTY, "
        "r.SELLING, r.JUMLAH, r.NOTE, "
        "st.SATUAN AS SATUAN_NAME, r.KD_SATUAN "
        "FROM INVOICE_R r "
        "LEFT JOIN SATUAN st ON r.KD_SATUAN = st.KD_SATUAN "
        "WHERE r.K_INVOICE = ? ORDER BY r.NO, r.JENISCOST",
        [k_invoice],
    )

    return {"header": header, "line_items": line_items}


def get_sales_summary(company_filter: str = ""):
    """Sales dashboard summary: invoice totals, outstanding, import/export split."""
    where_inv = "WHERE NOINVOICE IS NOT NULL AND NOINVOICE != ''"
    params: list = []
    if company_filter:
        cond, cparams = _company_filter(company_filter)
        where_inv += (
            " AND REFSHITMEN IN ("
            "SELECT REFSHITMEN FROM UnifiedShipment u "
            "LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
            "LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
            "WHERE " + cond + ")"
        )
        params += cparams

    # Totals
    totals = query_one(
        "SELECT COUNT(*) AS total_invoices, "
        "SUM(GRAND_USD) AS total_usd, SUM(GRAND_RP) AS total_idr, "
        "SUM(COALESCE(BYR_USD, 0)) AS paid_usd, SUM(COALESCE(BYR_RP, 0)) AS paid_idr, "
        "SUM(GRAND_USD - COALESCE(BYR_USD, 0)) AS outstanding_usd, "
        "SUM(GRAND_RP - COALESCE(BYR_RP, 0)) AS outstanding_idr, "
        "COUNT(CASE WHEN COALESCE(BYR_USD, 0) = 0 AND COALESCE(BYR_RP, 0) = 0 THEN 1 END) AS unpaid_count "
        "FROM INVOICE " + where_inv,
        params,
    )

    # Import vs Export split
    split_where = where_inv
    import_row = query_one(
        "SELECT COUNT(*) AS cnt, SUM(GRAND_USD) AS usd, SUM(GRAND_RP) AS idr "
        "FROM INVOICE " + split_where + " AND EI = 'I'",
        params,
    )
    export_row = query_one(
        "SELECT COUNT(*) AS cnt, SUM(GRAND_USD) AS usd, SUM(GRAND_RP) AS idr "
        "FROM INVOICE " + split_where + " AND EI = 'E'",
        params,
    )

    return {
        "total_invoices": totals["total_invoices"] if totals else 0,
        "total_usd": totals["total_usd"] if totals else 0,
        "total_idr": totals["total_idr"] if totals else 0,
        "paid_usd": totals["paid_usd"] if totals else 0,
        "paid_idr": totals["paid_idr"] if totals else 0,
        "outstanding_usd": totals["outstanding_usd"] if totals else 0,
        "outstanding_idr": totals["outstanding_idr"] if totals else 0,
        "unpaid_count": totals["unpaid_count"] if totals else 0,
        "import_count": import_row["cnt"] if import_row else 0,
        "import_usd": import_row["usd"] if import_row else 0,
        "import_idr": import_row["idr"] if import_row else 0,
        "export_count": export_row["cnt"] if export_row else 0,
        "export_usd": export_row["usd"] if export_row else 0,
        "export_idr": export_row["idr"] if export_row else 0,
    }


def get_sales_monthly_trend(company_filter: str = "", months: int = 12):
    """Monthly invoice revenue for the last N months."""
    where_inv = "WHERE DATEOFISSU IS NOT NULL AND DATEOFISSU != '' AND NOINVOICE IS NOT NULL AND NOINVOICE != ''"
    params: list = []
    if company_filter:
        cond, cparams = _company_filter(company_filter)
        where_inv += (
            " AND REFSHITMEN IN ("
            "SELECT REFSHITMEN FROM UnifiedShipment u "
            "LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
            "LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
            "WHERE " + cond + ")"
        )
        params += cparams
    return query(
        "SELECT SUBSTR(DATEOFISSU, 1, 7) AS month, "
        "COUNT(*) AS invoices, "
        "SUM(GRAND_USD) AS usd, SUM(GRAND_RP) AS idr, "
        "SUM(COALESCE(BYR_USD, 0)) AS paid_usd, "
        "SUM(COALESCE(BYR_RP, 0)) AS paid_idr, "
        "SUM(GRAND_USD - COALESCE(BYR_USD, 0)) AS unpaid_usd, "
        "SUM(GRAND_RP - COALESCE(BYR_RP, 0)) AS unpaid_idr "
        "FROM INVOICE " + where_inv +
        " GROUP BY month ORDER BY month DESC LIMIT ?",
        params + [months],
    )


def get_sales_top_customers(company_filter: str = "", limit: int = 10):
    """Top customers by invoice total."""
    where = "WHERE i.NOINVOICE IS NOT NULL AND i.NOINVOICE != ''"
    params: list = []
    if company_filter:
        cond, cparams = _company_filter(company_filter)
        where += " AND " + cond
        params += cparams
    has_user = _has_user_table()
    user_join = "LEFT JOIN USER us ON u.SALES = us._id" if has_user else ""
    name_col = "us.NAME AS salesman_name, " if has_user else "NULL AS salesman_name, "
    group_name = "us.NAME" if has_user else "1"
    return query(
        "SELECT CASE WHEN i.EI = 'I' THEN co.NAME ELSE sh.NAME END AS customer, "
        "i.EI AS type, u.SALES AS salesman, " + name_col +
        "COUNT(*) AS invoices, "
        "SUM(i.GRAND_USD) AS usd, SUM(i.GRAND_RP) AS idr, "
        "SUM(COALESCE(i.BYR_USD, 0)) AS paid_usd, "
        "SUM(COALESCE(i.BYR_RP, 0)) AS paid_idr "
        "FROM INVOICE i "
        "LEFT JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN "
        + (user_join + " " if user_join else "") +
        "LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
        "LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
        + where +
        " GROUP BY customer, type, salesman, " + group_name +
        " ORDER BY usd DESC NULLS LAST LIMIT ?",
        params + [limit],
    )


def get_sales_top_salesman(company_filter: str = "", period: str = "all", limit: int = 10):
    """Top salesmen by shipment count and invoice revenue.

    period: 'month' (current month), 'year' (current year),
            'round' (last 12 months), 'all' (all time).
    """
    from datetime import date
    today = date.today()
    where_inv = (
        "WHERE i.NOINVOICE IS NOT NULL AND i.NOINVOICE != '' "
        "AND u.SALES IS NOT NULL AND u.SALES != ''"
    )
    params: list = []
    if period == "month":
        where_inv += f" AND i.DATEOFISSU LIKE '{today.strftime('%Y-%m')}%'"
    elif period == "year":
        where_inv += f" AND i.DATEOFISSU LIKE '{today.strftime('%Y')}%'"
    elif period == "round":
        # Last 12 months
        year = today.year
        month = today.month
        start_month = month - 11 if month > 11 else 1
        start_year = year - 1 if month < 12 else year
        start = f"{start_year:04d}-{start_month:02d}"
        where_inv += f" AND i.DATEOFISSU >= '{start}'"
    if company_filter:
        cond, cparams = _company_filter(company_filter)
        where_inv += " AND " + cond
        params += cparams
    has_user = _has_user_table()
    user_join = "LEFT JOIN USER us ON u.SALES = us._id" if has_user else ""
    name_col = "us.NAME AS salesman_name, " if has_user else "NULL AS salesman_name, "
    group_col = "u.SALES, us.NAME" if has_user else "u.SALES"
    return query(
        "SELECT u.SALES AS salesman, " + name_col +
        "COUNT(DISTINCT u.REFSHITMEN) AS shipments, "
        "COUNT(*) AS invoices, "
        "SUM(i.GRAND_USD) AS usd, SUM(i.GRAND_RP) AS idr "
        "FROM INVOICE i "
        "JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN "
        + (user_join + " " if user_join else "") +
        "LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
        "LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
        + where_inv +
        " GROUP BY " + group_col +
        " ORDER BY usd DESC NULLS LAST LIMIT ?",
        params + [limit],
    )


# ---------------------------------------------------------------------------
# General Ledger queries
# ---------------------------------------------------------------------------

_ACCOUNTS_SORT_COLUMNS = {
    "code": "a.KD_ACCOUNT",
    "name": "a.NM_ACCOUNT",
    "type": "a.TYPE",
    "group": 'a."GROUP"',
}


def get_accounts(page: int = 1, per_page: int = 50, search: str = "",
                 sort: str = "code", sort_dir: str = "asc",
                 type_filter: str = ""):
    """Paginated chart of accounts.  The ``type_filter`` is ``detail`` or
    ``group`` (maps to ACCOUNT.TYPE 'D'/'G')."""
    page = max(int(page), 1)
    per_page = max(int(per_page), 1)
    where = "WHERE 1=1"
    params: list = []
    if search and search.strip():
        like = f"%{search.strip().upper()}%"
        where += " AND (UPPER(a.KD_ACCOUNT) LIKE ? OR UPPER(a.NM_ACCOUNT) LIKE ?)"
        params += [like, like]
    if type_filter == "detail":
        where += " AND a.TYPE = 'D'"
    elif type_filter == "group":
        where += " AND a.TYPE = 'G'"

    count_row = query_one(
        f"SELECT COUNT(*) AS c FROM ACCOUNT a {where}",
        params,
    )
    total = count_row["c"] if count_row else 0
    pages = max(1, (total + per_page - 1) // per_page)
    page = min(page, pages)

    order_col = _ACCOUNTS_SORT_COLUMNS.get(sort, "a.KD_ACCOUNT")
    direction = "ASC" if sort_dir == "asc" else "DESC"
    order = f"ORDER BY {order_col} {direction}"

    rows = query(
        f"SELECT a.KD_ACCOUNT, a.NM_ACCOUNT, a.SLD_NORMAL, a.TYPE, "
        f"a.\"GROUP\", a.ACTIVE "
        f"FROM ACCOUNT a {where} {order} LIMIT ? OFFSET ?",
        params + [per_page, (page - 1) * per_page],
    )
    return {
        "rows": rows,
        "total": total,
        "page": page,
        "per_page": per_page,
        "pages": pages,
    }


def get_account_detail(kd_account: str, date_from: str = "",
                       date_to: str = "", page: int = 1,
                       per_page: int = 50, sort: str = "date",
                       sort_dir: str = "desc"):
    """Paginated journal entries (JE_D) for a single account, with header
    info from JE.  Returns account metadata plus a paginated result set."""
    account = query_one(
        "SELECT KD_ACCOUNT, NM_ACCOUNT, SLD_NORMAL, TYPE, \"GROUP\", ACTIVE "
        "FROM ACCOUNT WHERE KD_ACCOUNT = ?",
        [kd_account],
    )
    if not account:
        return None

    where = "WHERE d.KD_ACCOUNT = ?"
    params: list = [kd_account]
    if date_from:
        where += " AND h.TANGGAL >= ?"
        params.append(date_from)
    if date_to:
        where += " AND h.TANGGAL <= ?"
        params.append(date_to + " 23:59:59")

    count_row = query_one(
        f"SELECT COUNT(*) AS c FROM JE_D d "
        f"JOIN JE h ON d.UJE = h.UJE {where}",
        params,
    )
    total = count_row["c"] if count_row else 0
    pages = max(1, (total + per_page - 1) // per_page)
    page = min(page, pages)

    # Sort key whitelist — no interpolation of raw caller values.
    _sort_cols = {"date": "h.TANGGAL", "modul": "d.MODUL", "ref": "h.REF_NO"}
    sort_col = _sort_cols.get(sort, "h.TANGGAL")
    direction = "DESC" if sort_dir == "desc" else "ASC"
    rows = query(
        f"SELECT d.MODUL, h.REF_NO, h.TANGGAL, d.URAIAN, d.JUMLAH, "
        f"d.DK, d.CURRENCY, d.EXRATE "
        f"FROM JE_D d "
        f"JOIN JE h ON d.UJE = h.UJE {where} "
        f"ORDER BY {sort_col} {direction}, h.REF_NO ASC "
        f"LIMIT ? OFFSET ?",
        params + [per_page, (page - 1) * per_page],
    )

    # Compute page-level debit/credit totals, converted to IDR (JUMLAH is
    # stored in the row's own currency; EXRATE is IDR per foreign unit).
    _idr = ("CASE WHEN UPPER(d.CURRENCY) IN ('RP','IDR','RP.') "
            "THEN d.JUMLAH ELSE d.JUMLAH * COALESCE(d.EXRATE, 0) END")
    totals = query_one(
        f"SELECT "
        f"SUM(CASE WHEN d.DK = 'D' THEN {_idr} ELSE 0 END) AS debit, "
        f"SUM(CASE WHEN d.DK = 'K' THEN {_idr} ELSE 0 END) AS credit "
        f"FROM JE_D d "
        f"JOIN JE h ON d.UJE = h.UJE {where}",
        params,
    )

    return {
        "account": account,
        "rows": rows,
        "total": total,
        "page": page,
        "per_page": per_page,
        "pages": pages,
        "debit": totals["debit"] if totals else 0,
        "credit": totals["credit"] if totals else 0,
    }


# Single source of truth for every GL anomaly's SQL condition. BOTH the
# rollup (get_gl_anomalies) and the detail page (get_gl_anomaly_detail)
# compose their count/data queries from these fragments, so the two can
# never drift apart the way the bad_date GLOB once did (rollup said 43,
# detail listed every row — count-SQL and data-SQL were separate string
# literals that each "worked"). One edit point per anomaly condition.
_GL_ANOMALY_SPECS: dict = {
    "unbalanced": {
        "from": "JE",
        "cond": "ABS(TOT_DR - TOT_CR) > 0.01",
        "cols": ["UJE", "TANGGAL", "REF_NO", "KETERANGAN", "TOT_DR", "TOT_CR", "GAP"],
        "select": ("UJE, TANGGAL, REF_NO, KETERANGAN, TOT_DR, TOT_CR, "
                   "TOT_DR - TOT_CR AS GAP"),
        "order": "TANGGAL DESC",
    },
    "orphan": {
        "from": ("JE_D d LEFT JOIN (SELECT DISTINCT UJE FROM JE) j "
                 "ON d.UJE = j.UJE"),
        "cond": "j.UJE IS NULL",
        "cols": ["UJE", "KD_ACCOUNT", "JUMLAH", "CURRENCY", "EXRATE", "DK", "URAIAN"],
        "select": "d.UJE, d.KD_ACCOUNT, d.JUMLAH, d.CURRENCY, d.EXRATE, d.DK, d.URAIAN",
        "order": "d.UJE",
    },
    "bad_date": {
        "from": "JE",
        "cond": ("TANGGAL IS NOT NULL "
                 "AND (CAST(strftime('%Y', TANGGAL) AS INTEGER) < 2000 "
                 "OR TANGGAL NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]')"),
        "cols": ["UJE", "TANGGAL", "REF_NO", "KETERANGAN", "TOT_DR", "TOT_CR", "GAP"],
        "select": ("UJE, TANGGAL, REF_NO, KETERANGAN, TOT_DR, TOT_CR, "
                   "TOT_DR - TOT_CR AS GAP"),
        "order": "TANGGAL",
    },
    "bad_dk": {
        "from": "JE_D",
        "cond": "DK NOT IN ('D', 'K') AND DK IS NOT NULL",
        "cols": ["UJE", "KD_ACCOUNT", "JUMLAH", "CURRENCY", "EXRATE", "DK", "URAIAN"],
        "select": "UJE, KD_ACCOUNT, JUMLAH, CURRENCY, EXRATE, DK, URAIAN",
        "order": "UJE",
    },
}

_GL_ANOMALY_CACHE: dict = {}


def get_gl_anomalies(company_filter: str = "") -> list[dict]:
    """Return GL data-quality anomalies for the requested company, with counts.

    Rows: company, anomaly_type, count, balance_dr, balance_cr, sample_ref, sample_date.
    company_filter is an exact company (DB stem) name — the data-quality page
    scopes to the company selected in the header DB selector. Empty = all.
    """
    # Derive company label from DB file name (same as sidebar selector).
    import sqlite3
    from pathlib import Path
    db_dir = Path(os.environ.get("DATA_DIR", "data"))
    db_names = {P.stem.upper(): P.stem for P in db_dir.glob("*.db")}

    rows: list[dict] = []
    for db_path in sorted(db_dir.glob("*.db")):
        company = db_path.stem
        if company_filter and company.upper() != company_filter.upper():
            continue

        # Anomaly scans are full-table; cache per (file, mtime) so repeat
        # views between syncs are instant (data only changes on re-sync).
        cache_key = (str(db_path), db_path.stat().st_mtime)
        cached = _GL_ANOMALY_CACHE.get(cache_key)
        if cached is not None:
            rows.extend(cached)
            continue
        company_rows: list[dict] = []
        conn = sqlite3.connect(str(db_path))
        try:
            # Unbalanced vouchers
            u = conn.execute(
                "SELECT COUNT(*) AS c, "
                "COALESCE(SUM(TOT_DR), 0) AS dr, "
                "COALESCE(SUM(TOT_CR), 0) AS cr, "
                "COALESCE(MAX(REF_NO), '') AS ref, "
                "COALESCE(MAX(TANGGAL), '') AS dt "
                + "FROM " + _GL_ANOMALY_SPECS["unbalanced"]["from"]
                + " WHERE " + _GL_ANOMALY_SPECS["unbalanced"]["cond"]
            ).fetchone()
            if u and u[0]:
                company_rows.append({
                    "company": company,
                    "type": "unbalanced",
                    "count": u[0],
                    "dr": u[1],
                    "cr": u[2],
                    "sample_ref": u[3],
                    "sample_date": u[4],
                })

            # Orphan JE_D rows
            o = conn.execute(
                "SELECT COUNT(*) AS c, "
                "COALESCE(SUM(JUMLAH), 0) AS amt, "
                "COALESCE(GROUP_CONCAT(DISTINCT KD_ACCOUNT), '') AS accts, "
                "COALESCE(MAX(URAIAN), '') AS sample_desc "
                + "FROM " + _GL_ANOMALY_SPECS["orphan"]["from"]
                + " WHERE " + _GL_ANOMALY_SPECS["orphan"]["cond"]
            ).fetchone()
            if o and o[0]:
                company_rows.append({
                    "company": company,
                    "type": "orphan",
                    "count": o[0],
                    "dr": 0,
                    "cr": 0,
                    "sample_ref": "",
                    "sample_date": "",
                    "sample_desc": o[3],
                    "accounts": o[2],
                })

            # Corrupted journal dates (year < 2000 or unparseable)
            bad = conn.execute(
                "SELECT COUNT(*) AS c, "
                "COALESCE(MAX(REF_NO), '') AS ref, "
                "COALESCE(MAX(TANGGAL), '') AS dt, "
                "COALESCE(MAX(KETERANGAN), '') AS sample_desc, "
                "COALESCE(SUM(TOT_DR), 0) AS dr, "
                "COALESCE(SUM(TOT_CR), 0) AS cr "
                + "FROM " + _GL_ANOMALY_SPECS["bad_date"]["from"]
                + " WHERE " + _GL_ANOMALY_SPECS["bad_date"]["cond"]
            ).fetchone()
            if bad and bad[0]:
                company_rows.append({
                    "company": company,
                    "type": "bad_date",
                    "count": bad[0],
                    "dr": bad[4],
                    "cr": bad[5],
                    "sample_ref": bad[1],
                    "sample_date": bad[2],
                    "sample_desc": bad[3],
                })

            # Stray DK values (not D, K, or NULL)
            dk = conn.execute(
                "SELECT COUNT(*) AS c, "
                "COALESCE(MAX(URAIAN), '') AS sample_desc, "
                "COALESCE(MAX(KD_ACCOUNT), '') AS acct, "
                "COALESCE(MAX(JUMLAH), 0) AS amt "
                + "FROM " + _GL_ANOMALY_SPECS["bad_dk"]["from"]
                + " WHERE " + _GL_ANOMALY_SPECS["bad_dk"]["cond"]
            ).fetchone()
            if dk and dk[0]:
                company_rows.append({
                    "company": company,
                    "type": "bad_dk",
                    "count": dk[0],
                    "dr": 0,
                    "cr": 0,
                    "sample_ref": "",
                    "sample_date": "",
                    "sample_desc": dk[1],
                    "account": dk[2],
                    "amount": dk[3],
                })
        finally:
            conn.close()
        _GL_ANOMALY_CACHE[cache_key] = company_rows
        rows.extend(company_rows)

    return rows


def get_gl_anomaly_detail(anomaly_type: str, company_filter: str = "",
                         page: int = 1, per_page: int = 100,
                         db_path=None) -> dict:
    """Return the individual rows that caused a specific GL anomaly type.

    Returns { type, company, columns, rows, total, page, per_page, pages, sums }.
    *sums* carries page-level footer totals for the detail table (see below).
    *db_path* is the resolved active company DB file (passed in by the
    caller so this function never re-implements DB discovery). When omitted,
    falls back to the DATA_DIR glob for backward compatibility.
    Results are paginated — full-table fetchall() on JE_D would render
    huge HTML on large companies.
    """
    import sqlite3 as _sql
    from pathlib import Path as _P

    if db_path is None:
        db_dir = _P(os.environ.get("DATA_DIR", "data"))
        for p in db_dir.glob("*.db"):
            if company_filter and p.stem.upper() != company_filter.upper():
                continue
            db_path = p
            break
    else:
        db_path = _P(str(db_path))
    empty = {"type": anomaly_type, "company": company_filter, "columns": [],
             "rows": [], "total": 0, "page": 1, "per_page": per_page,
             "pages": 1, "sums": {}}
    if db_path is None:
        return empty

    page = max(int(page), 1)
    per_page = max(int(per_page), 1)
    spec = _GL_ANOMALY_SPECS.get(anomaly_type)
    if spec is None:
        return empty
    cols = spec["cols"]
    # Count and data compose the SAME condition fragments — one edit point,
    # so the rollup and detail totals can never disagree again.
    count_sql = "SELECT COUNT(*) FROM " + spec["from"] + " WHERE " + spec["cond"]
    data_sql = ("SELECT " + spec["select"] + " FROM " + spec["from"]
                + " WHERE " + spec["cond"]
                + " ORDER BY " + spec["order"] + " LIMIT ? OFFSET ?")

    conn = _sql.connect(str(db_path))
    try:
        try:
            total = conn.execute(count_sql).fetchone()[0]
        except Exception:
            return empty  # JE/JE_D missing in this DB
        pages = max(1, (total + per_page - 1) // per_page)
        page = min(page, pages)
        cur = conn.execute(data_sql, (per_page, (page - 1) * per_page))
        rows = [dict(zip(cols, r)) for r in cur.fetchall()]
    finally:
        conn.close()

    company = db_path.stem.upper() if company_filter == "" else company_filter

    # Footer auto-sums for the detail table. JE header totals (TOT_DR/TOT_CR)
    # are summed as stored. JE_D.JUMLAH is stored in each row's own currency
    # (EXRATE = IDR per foreign unit), so the JUMLAH page total must convert
    # foreign rows via EXRATE — same rule as get_account_detail's totals.
    sums: dict = {}
    if anomaly_type in ("unbalanced", "bad_date"):
        _dr = sum(r["TOT_DR"] or 0 for r in rows)
        _cr = sum(r["TOT_CR"] or 0 for r in rows)
        sums = {"dr": _dr, "cr": _cr, "gap": _dr - _cr}
    elif anomaly_type in ("orphan", "bad_dk"):
        def _is_rp(cu) -> bool:
            return str(cu or "").strip().upper() in ("RP", "IDR", "RP.")

        def _idr(r) -> float:
            amt = r["JUMLAH"] or 0
            return amt if _is_rp(r["CURRENCY"]) else amt * (r["EXRATE"] or 0)

        _total_idr = sum(_idr(r) for r in rows)
        _dr_idr = sum(_idr(r) for r in rows if r["DK"] == "D")
        sums = {"amount_idr": _total_idr, "dr_idr": _dr_idr,
                "cr_idr": _total_idr - _dr_idr}
    return {"type": anomaly_type, "company": company, "columns": cols,
            "rows": rows, "total": total, "page": page,
            "per_page": per_page, "pages": pages, "sums": sums}


# ---------------------------------------------------------------------------
# Trial Balance / Balance Sheet / Income Statement (F-26) — single derivation
# ---------------------------------------------------------------------------
def _idr_expr(col_jumlah="d.JUMLAH", col_currency="d.CURRENCY", col_exrate="d.EXRATE"):
    return ("CASE WHEN UPPER(COALESCE(" + col_currency + ",'')) IN ('RP','IDR','RP.') "
            "THEN " + col_jumlah + " ELSE " + col_jumlah + " * COALESCE(" + col_exrate + ", 0) END")


def _account_mutations(as_of_date: str = "", date_from: str = "", date_to: str = ""):
    """Single source: per-account debit/credit (IDR) from JE_D joined JE."""
    where = "WHERE 1=1"
    params = []
    if as_of_date:
        where += " AND h.TANGGAL <= ?"
        params.append(as_of_date + " 23:59:59")
    if date_from:
        where += " AND h.TANGGAL >= ?"
        params.append(date_from)
    if date_to:
        where += " AND h.TANGGAL <= ?"
        params.append(date_to + " 23:59:59")
    idr = _idr_expr()
    rows = query(
        "SELECT d.KD_ACCOUNT AS code, "
        "SUM(CASE WHEN d.DK='D' THEN " + idr + " ELSE 0 END) AS debit, "
        "SUM(CASE WHEN d.DK='K' THEN " + idr + " ELSE 0 END) AS credit "
        "FROM JE_D d JOIN JE h ON d.UJE = h.UJE " + where +
        " GROUP BY d.KD_ACCOUNT",
        params,
    )
    return {r["code"]: {"debit": r["debit"] or 0, "credit": r["credit"] or 0} for r in rows}


def _opening_balances(as_of_date: str = ""):
    """Per-account opening balance from SALDO_ACCT up to as_of_date (IDR via SALDO)."""
    where = "WHERE 1=1"
    params = []
    if as_of_date:
        where += " AND TANGGAL <= ?"
        params.append(as_of_date)
    rows = query(
        "SELECT KD_ACCOUNT AS code, SUM(CASE WHEN DK='D' THEN SALDO ELSE -SALDO END) AS bal "
        "FROM SALDO_ACCT " + where + " GROUP BY KD_ACCOUNT",
        params,
    )
    return {r["code"]: (r["bal"] or 0) for r in rows}


def get_trial_balance(as_of_date: str = "", company_filter: str = ""):
    muts = _account_mutations(as_of_date=as_of_date)
    opens = _opening_balances(as_of_date=as_of_date)
    accounts = query("SELECT KD_ACCOUNT, NM_ACCOUNT, SLD_NORMAL, TYPE, \"GROUP\" FROM ACCOUNT")
    out = []
    for a in accounts:
        code = a["KD_ACCOUNT"]
        m = muts.get(code, {"debit": 0, "credit": 0})
        opening = opens.get(code, 0)
        closing = opening + m["debit"] - m["credit"]
        out.append({
            "code": code, "name": a["NM_ACCOUNT"], "type": a["TYPE"],
            "group": a["GROUP"], "normal": a["SLD_NORMAL"],
            "opening": opening, "debit": m["debit"], "credit": m["credit"],
            "closing": closing,
        })
    return out


def get_balance_sheet(as_of_date: str = "", company_filter: str = ""):
    tb = get_trial_balance(as_of_date=as_of_date, company_filter=company_filter)
    # Assets: type D/G with normal D; Liabilities/Equity: normal K. Group by type.
    assets = [r for r in tb if r["normal"] == "D"]
    liab = [r for r in tb if r["normal"] == "K"]
    return {"assets": assets, "liabilities_equity": liab,
            "total_assets": sum(r["closing"] for r in assets),
            "total_liab_eq": sum(r["closing"] for r in liab)}


def get_income_statement(date_from: str = "", date_to: str = "", company_filter: str = ""):
    muts = _account_mutations(date_from=date_from, date_to=date_to)
    accounts = query("SELECT KD_ACCOUNT, NM_ACCOUNT, SLD_NORMAL, TYPE, \"GROUP\" FROM ACCOUNT")
    rows = []
    for a in accounts:
        code = a["KD_ACCOUNT"]
        m = muts.get(code, {"debit": 0, "credit": 0})
        # Income = credit-normal, Expense = debit-normal; include any with activity
        if m["debit"] == 0 and m["credit"] == 0:
            continue
        rows.append({
            "code": code, "name": a["NM_ACCOUNT"], "type": a["TYPE"],
            "group": a["GROUP"], "normal": a["SLD_NORMAL"],
            "debit": m["debit"], "credit": m["credit"],
            "amount": (m["credit"] - m["debit"]) if a["SLD_NORMAL"] == "K" else (m["debit"] - m["credit"]),
        })
    revenue = [r for r in rows if r["normal"] == "K"]
    expense = [r for r in rows if r["normal"] == "D"]
    return {"revenue": revenue, "expense": expense,
            "total_revenue": sum(r["amount"] for r in revenue),
            "total_expense": sum(r["amount"] for r in expense),
            "net_income": sum(r["amount"] for r in revenue) - sum(r["amount"] for r in expense)}


# ---------------------------------------------------------------------------
# Reference listings (F-28): Agent, Shipper, Consignee/Notify

_AGENTS_SORT_COLUMNS = {"code": "a.CODE", "name": "a.NAME", "agent": "a.AGENT", "rate": "a.RATE"}


def get_agents(page=1, per_page=50, search="", sort="name", sort_dir="asc"):
    page=max(int(page),1); per_page=max(int(per_page),1)
    where="WHERE 1=1"; params=[]
    if search and search.strip():
        like=f"%{search.strip().upper()}%"
        where+=" AND (UPPER(a.CODE) LIKE ? OR UPPER(a.NAME) LIKE ? OR UPPER(a.AGENT) LIKE ?)"
        params += [like,like,like]
    count_row=query_one(f"SELECT COUNT(*) AS c FROM AGENT a {where}", params)
    total=count_row["c"] if count_row else 0
    pages=max(1,(total+per_page-1)//per_page)
    page=min(page,pages)
    order=f"ORDER BY {_AGENTS_SORT_COLUMNS.get(sort,'a.CODE')} {'ASC' if sort_dir=='asc' else 'DESC'}"
    rows=query(f"SELECT a.CODE, a.NAME, a.AGENT, a.AGENTINV, a.RATE, a.KD_ACCOUNT, a.ACCT_CR, a.TELP, a.FAX, a.PIC FROM AGENT a {where} {order} LIMIT ? OFFSET ?", params+[per_page,(page-1)*per_page])
    return {"rows":rows,"total":total,"page":page,"per_page":per_page,"pages":pages}

_SHIPPERS_SORT_COLUMNS = {"code": "s.KD_SHIPPER", "name": "s.NAME", "bu": "s.KD_BU", "type": "s.KD_JNSCUST"}


def get_shippers(page=1, per_page=50, search="", sort="name", sort_dir="asc"):
    page=max(int(page),1); per_page=max(int(per_page),1)
    where="WHERE 1=1"; params=[]
    if search and search.strip():
        like=f"%{search.strip().upper()}%"
        where+=" AND (UPPER(s.KD_SHIPPER) LIKE ? OR UPPER(s.NAME) LIKE ?)"
        params += [like,like]
    count_row=query_one(f"SELECT COUNT(*) AS c FROM SHIPPER s {where}", params)
    total=count_row["c"] if count_row else 0
    pages=max(1,(total+per_page-1)//per_page)
    page=min(page,pages)
    order=f"ORDER BY {_SHIPPERS_SORT_COLUMNS.get(sort,'s.KD_SHIPPER')} {'ASC' if sort_dir=='asc' else 'DESC'}"
    rows=query(f"SELECT s.KD_SHIPPER, s.NAME, s.KD_BU, s.TELP, s.FAX, s.KD_JNSCUST, s.TERM, s.KD_ACCOUNT, s.NPWP FROM SHIPPER s {where} {order} LIMIT ? OFFSET ?", params+[per_page,(page-1)*per_page])
    return {"rows":rows,"total":total,"page":page,"per_page":per_page,"pages":pages}

RATE_BY_DESTINATION_SORT_FIELDS = {"code", "city", "quota", "date"}


def get_rate_by_destination(page=1, per_page=50, search="", sort="code", sort_dir="asc"):
    """Rate by Destination (PRD F-28 row 5): one row per agent x destination.

    Source: QUOTATION_R (per-container rates) joined to QUOTATION on UNIK for
    the quotation header (agent code, date, quota). The latest quotation per
    agent x destination wins (ROW_NUMBER over TANGGAL desc); the full history
    is in QUOTATION_R but the page shows the current rate per destination.

    Agent labels resolve through AGENT (AGENT = CODE_AGENT, CODE = KD_CITY);
    destination labels through QUOTATION_R.DESC_CITY, falling back to KD_CITY.
    """
    page = max(int(page), 1); per_page = max(int(per_page), 1)
    base = (
        "SELECT r.UNIK, r.CODE_AGENT, r.KD_CITY, r.DESC_CITY, r.LCL, r.MIN,"
        " r.D20, r.D40, r.D40HQ, r.D45, r.PRINT,"
        " q.KD_QUOTA, q.TANGGAL, q.EXPIRED, q.KD_SHIPPER,"
        " a.NAME AS agent_name,"
        " CASE WHEN a.NAME IS NOT NULL THEN 0 ELSE 1 END AS unresolved_agent,"
        " CASE WHEN r.DESC_CITY IS NOT NULL AND TRIM(r.DESC_CITY) != '' THEN r.DESC_CITY"
        " ELSE r.KD_CITY END AS city_label"
        " FROM (SELECT r0.*, ROW_NUMBER() OVER (PARTITION BY r0.CODE_AGENT, r0.KD_CITY"
        " ORDER BY COALESCE(q0.TANGGAL, '') DESC, r0.UNIK DESC) AS rn"
        " FROM QUOTATION_R r0 JOIN QUOTATION q0 ON q0.UNIK = r0.UNIK) r"
        " JOIN QUOTATION q ON q.UNIK = r.UNIK"
        " LEFT JOIN AGENT a ON a.AGENT = r.CODE_AGENT AND a.CODE = r.KD_CITY"
        " WHERE r.rn = 1"
    )
    where = ""; params = []
    if search and search.strip():
        like = f"%{search.strip().upper()}%"
        where += (" AND (UPPER(COALESCE(r.CODE_AGENT,'')) LIKE ?"
                  " OR UPPER(COALESCE(r.DESC_CITY,'')) LIKE ?"
                  " OR UPPER(COALESCE(r.KD_CITY,'')) LIKE ?"
                  " OR UPPER(COALESCE(q.KD_QUOTA,'')) LIKE ?"
                  " OR UPPER(COALESCE(a.NAME,'')) LIKE ?)")
        params += [like] * 5

    order = {
        "code": "COALESCE(agent_name, CODE_AGENT)",
        "city": "city_label",
        "quota": "COALESCE(KD_QUOTA, '')",
        "date": "COALESCE(TANGGAL, '')",
    }.get(sort, "COALESCE(agent_name, CODE_AGENT)")
    order = f"ORDER BY {order} {'ASC' if sort_dir == 'asc' else 'DESC'}"

    count_row = query_one(f"SELECT COUNT(*) AS c FROM ({base}{where})", params)
    total = count_row["c"] if count_row else 0
    pages = max(1, (total + per_page - 1) // per_page)
    page = min(page, pages)

    rows = query(
        f"{base}{where} {order} LIMIT ? OFFSET ?",
        params + [per_page, (page - 1) * per_page],
    )
    return {"rows": rows, "total": total, "page": page, "per_page": per_page, "pages": pages}


_CONSIGNEES_SORT_COLUMNS = {"code": "c.KD_CONSIG", "name": "c.NAME"}


def get_consignees(page=1, per_page=50, search="", sort="name", sort_dir="asc"):
    page=max(int(page),1); per_page=max(int(per_page),1)
    where="WHERE 1=1"; params=[]
    if search and search.strip():
        like=f"%{search.strip().upper()}%"
        where+=" AND (UPPER(c.KD_CONSIG) LIKE ? OR UPPER(c.NAME) LIKE ?)"
        params += [like,like]
    count_row=query_one(f"SELECT COUNT(*) AS c FROM CONSIGNEE c {where}", params)
    total=count_row["c"] if count_row else 0
    pages=max(1,(total+per_page-1)//per_page)
    page=min(page,pages)
    order=f"ORDER BY {_CONSIGNEES_SORT_COLUMNS.get(sort,'c.KD_CONSIG')} {'ASC' if sort_dir=='asc' else 'DESC'}"
    rows=query(f"SELECT c.KD_CONSIG, c.NAME, c.ADDRESS1, c.ADDRESS2, c.ADDRESS3, c.TELP, c.FAX FROM CONSIGNEE c {where} {order} LIMIT ? OFFSET ?", params+[per_page,(page-1)*per_page])
    return {"rows":rows,"total":total,"page":page,"per_page":per_page,"pages":pages}
