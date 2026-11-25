from . import queries as q
from .queries import _company_filter, _safe_query, query
from .aging_buckets import BUCKETS, bucket_for


def _aging_rows_ar(as_of: str, company_filter: str = "", min_amount=None, counterparty=None):
    # INVOICE outstanding
    rows = []
    sql = """
      SELECT i.NOINVOICE AS doc_no, i.DATEOFISSU AS doc_date, i.SHIPP_PIUT AS shipper,
             COALESCE(i.GRAND_USD,0) - COALESCE(i.BYR_USD,0) AS amt,
             i.DATEOFISSU AS due_date
      FROM INVOICE i
      WHERE (COALESCE(i.GRAND_USD,0) - COALESCE(i.BYR_USD,0)) > 0
    """
    params = []
    if company_filter:
        cond, cparams = _company_filter(company_filter)
        sql += " AND " + cond
        params += cparams
    for r in query(sql, params):
        rows.append({"key": r["doc_no"], "counterparty": r["shipper"], "document_no": r["doc_no"],
                     "document_date": r["doc_date"], "due_date": r["due_date"], "amount": r["amt"]})
    # INVOICE_R (debit note) - outstanding; document date from parent INVOICE
    sql2 = """
      SELECT ir.NOINVOICE AS doc_no, i.DATEOFISSU AS doc_date, '' AS shipper,
             COALESCE(ir.JUMLAH,0) - COALESCE(ir.BAYAR,0) AS amt
      FROM INVOICE_R ir
      LEFT JOIN INVOICE i ON i.K_INVOICE = ir.K_INVOICE
      WHERE (COALESCE(ir.JUMLAH,0) - COALESCE(ir.BAYAR,0)) > 0
    """
    params2 = []
    for r in _safe_query(sql2, params2):
        rows.append({"key": r["doc_no"], "counterparty": r["shipper"], "document_no": r["doc_no"],
                     "document_date": r["doc_date"], "due_date": r["doc_date"], "amount": r["amt"]})
    if min_amount is not None:
        rows = [r for r in rows if (r["amount"] or 0) >= min_amount]
    if counterparty:
        rows = [r for r in rows if counterparty.lower() in (r["counterparty"] or "").lower()]
    # days overdue + bucket
    from datetime import date
    asof = date.fromisoformat(as_of)
    out = []
    for r in rows:
        dd = r["due_date"]
        try:
            d = date.fromisoformat(dd[:10]) if dd else asof
        except Exception:
            d = asof
        days = (asof - d).days
        if days < 0:
            days = 0
        r["days_overdue"] = days
        r["bucket"] = bucket_for(days)
        out.append(r)
    out.sort(key=lambda x: ((x["counterparty"] or ""), (x["due_date"] or "")))
    return out


def _aging_rows_ap(as_of: str, company_filter: str = "", min_amount=None, counterparty=None):
    rows = []
    # OPRCOST_R only (payable lines); parent OPRCOST has no payment column.
    # orphan exclusion: OPRCOST_R rows whose UOPRCOST exists in OPRCOST only
    sql2 = """
      SELECT r.INVOICE_NO AS doc_no, r.INV_TGL AS doc_date, r.KD_SHIPLIN AS cp,
             COALESCE(r.JUMLAH,0) - COALESCE(r.BAYAR,0) AS amt, r.INV_TGL AS due_date, r.UOPRCOST AS k
      FROM OPRCOST_R r
      WHERE (COALESCE(r.JUMLAH,0) - COALESCE(r.BAYAR,0)) > 0
        AND EXISTS (SELECT 1 FROM OPRCOST o WHERE o.UOPRCOST = r.UOPRCOST)
    """
    params2 = []
    if company_filter:
        sql2 += " AND UPPER(COALESCE(r.KD_SHIPLIN,'')) LIKE ?"
        params2.append(f"%{company_filter.upper()}%")
    for r in _safe_query(sql2, params2):
        rows.append({"key": r["doc_no"], "counterparty": r["cp"], "document_no": r["doc_no"],
                     "document_date": r["doc_date"], "due_date": r["due_date"], "amount": r["amt"]})
    # DRCRFULL / DRCRFULL_R
    sql3 = """
      SELECT d.NO_REF AS doc_no, d.TANGGAL AS doc_date, d.TONAME AS cp,
             COALESCE(d.TOTAL,0) - COALESCE(d.BAYAR,0) AS amt, d.TANGGAL AS due_date
      FROM DRCRFULL d
      WHERE (COALESCE(d.TOTAL,0) - COALESCE(d.BAYAR,0)) > 0
    """
    for r in _safe_query(sql3):
        rows.append({"key": r["doc_no"], "counterparty": r["cp"], "document_no": r["doc_no"],
                     "document_date": r["doc_date"], "due_date": r["due_date"], "amount": r["amt"]})
    # DRCRFULL_R are detail lines of a DRCRFULL document; parent row above already
    # carries the net (TOTAL - BAYAR), so detail rows are not aged separately.
    if min_amount is not None:
        rows = [r for r in rows if (r["amount"] or 0) >= min_amount]
    if counterparty:
        rows = [r for r in rows if counterparty.lower() in (r["counterparty"] or "").lower()]
    from datetime import date
    asof = date.fromisoformat(as_of)
    out = []
    for r in rows:
        dd = r["due_date"]
        try:
            d = date.fromisoformat(str(dd)[:10]) if dd else asof
        except Exception:
            d = asof
        days = (asof - d).days
        if days < 0:
            days = 0
        r["days_overdue"] = days
        r["bucket"] = bucket_for(days)
        out.append(r)
    out.sort(key=lambda x: ((x["counterparty"] or ""), (x["due_date"] or "")))
    return out


def get_aging_report(kind: str, as_of: str, company_filter: str = "", min_amount=None,
                     counterparty=None, page: int = 1, per_page: int = 50):
    rows = _aging_rows_ar(as_of, company_filter, min_amount, counterparty) if kind == "ar" else _aging_rows_ap(as_of, company_filter, min_amount, counterparty)
    totals = {b: 0.0 for b in BUCKETS}
    totals["grand"] = 0.0
    for r in rows:
        totals[r["bucket"]] += r["amount"] or 0
        totals["grand"] += r["amount"] or 0
    # pagination
    page = max(int(page), 1)
    per_page = max(int(per_page), 1)
    total = len(rows)
    pages = max(1, (total + per_page - 1) // per_page)
    page = min(page, pages)
    paged = rows[(page-1)*per_page: page*per_page]
    return {
        "report": kind,
        "as_of": as_of,
        "buckets": BUCKETS,
        "rows": paged,
        "totals": totals,
        "empty": len(rows) == 0,
        "page": page,
        "per_page": per_page,
        "pages": pages,
        "total": total,
    }
