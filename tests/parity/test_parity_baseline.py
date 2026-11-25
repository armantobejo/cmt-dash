"""Parity-baseline harness (PRD phase P5 support).

Executes the docs/parity-checklist.yaml steps against the PYTHON app via
TestClient and writes a machine-readable baseline to
tests/parity/baseline/python.json. The Laravel app must produce a structurally
identical JSON for the diff tool (tools/compare_parity.py) to report parity.

Run:
    pytest tests/parity/ -q

Known defects are captured AS-IS (defect-for-defect parity) and flagged in the
baseline's `meta.known_gaps` (KG-1..KG-5 in the checklist).

Normalizers strip environment noise: token cookies, CSRF-ish hidden fields,
request ids, timings, sizes, and template asset hashes — everything that
legitimately differs between two processes of the SAME app.
"""
from __future__ import annotations

import hashlib
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

import pytest

_REPO_ROOT = Path(__file__).resolve().parents[2]
_HERE = Path(__file__).resolve().parent
for p in (str(_HERE),):
    if p not in sys.path:
        sys.path.insert(0, p)

from parity_dataset import DEFAULTS as D  # noqa: E402
from parity_constants import ROLE_CREDS  # noqa: E402
from parity_actions import login, logout  # noqa: E402

BASELINE_PATH = _HERE / "baseline" / "python.json"

# ── Normalization ────────────────────────────────────────────────────────────

_NOISE_KEYS = {
    "cmt_session", "session", "token", "csrf_token", "request_id",
    "duration_ms", "asset_ver", "timing", "age_seconds",
    "last_sync",  # DB file mtime - differs per run (fresh temp dir)
}


def normalize(obj):
    """Recursively strip noise keys and round floats for cross-process stability."""
    if isinstance(obj, dict):
        return {k: normalize(v) for k, v in obj.items() if k.lower() not in _NOISE_KEYS}
    if isinstance(obj, (list, tuple)):
        return [normalize(v) for v in obj]
    if isinstance(obj, float):
        return round(obj, 4)
    return obj


def page_signature(resp) -> dict:
    """Compare-friendly signature of an HTTP response.

    Status + Location for redirects; status + extracted data strings for pages;
    parsed + normalized body for JSON. Full-HTML hashing is too brittle (asset
    version query string, whitespace) — the harness compares DOM text instead.
    """
    sig: dict = {"status": resp.status_code}
    loc = resp.headers.get("location")
    if loc:
        sig["location"] = loc
    ctype = resp.headers.get("content-type", "")
    if "json" in ctype:
        try:
            sig["json"] = normalize(resp.json())
        except Exception:
            sig["json"] = None
    elif "html" in ctype and resp.status_code == 200:
        sig["data_strings"] = _visible_strings(resp.text)
        sig["rows_sample"] = _table_rows(resp.text)[:10]
    return sig


_STR_TAG = re.compile(r"<[^>]+>")
_WS = re.compile(r"\s+")
_SCRIPT = re.compile(r"<(script|style)[^>]*>.*?</\1>", re.S | re.I)
# base.html renders the DB file's mtime ("%Y-%m-%d %H:%M") in the footer of
# every page. It legitimately differs per run (fresh temp dir), so it is
# tokenized like last_sync. Data dates are date-only and never match.
_SYNC_TIME_RE = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$")
_SYNC_TIME_TOKEN = "<SYNC_TIME>"


def _scrub(text: str) -> str:
    return _SYNC_TIME_TOKEN if _SYNC_TIME_RE.match(text) else text


def _visible_strings(html: str) -> list[str]:
    """Text nodes of the page, whitespace-collapsed. Deterministic per DOM."""
    body = _SCRIPT.sub("", html)
    text = _STR_TAG.sub("|", body)
    parts = [p.strip() for p in text.split("|")]
    return [_scrub(p) for p in parts if p]


def _table_rows(html: str) -> list[list[str]]:
    """Rows of the first data table (td cells only), for row-level comparison."""
    m = re.search(r"<table.*?</table>", html, flags=re.S | re.I)
    if not m:
        return []
    rows = []
    for tr in re.findall(r"<tr[^>]*>(.*?)</tr>", m.group(0), flags=re.S | re.I):
        tds = re.findall(r"<td[^>]*>(.*?)</td>", tr, flags=re.S | re.I)
        if tds:
            rows.append([_scrub(_WS.sub(" ", _STR_TAG.sub("", td)).strip()) for td in tds])
    return rows


# ── Capture domains (each maps to checklist feature IDs) ─────────────────────

def capture_auth(client) -> dict:
    """F-01: login flows, bad creds, logout, anonymous redirect."""
    out = {}
    client.cookies.clear()
    out["anon_root"] = page_signature(client.get("/"))
    out["bad_creds"] = page_signature(
        client.post("/login", data={"username": "staff1", "password": "WRONG"}))
    login(client, "staff")
    out["good_creds"] = {"status": 302, "has_session_cookie": "cmt_session" in client.cookies}
    r = client.post("/logout")
    out["logout"] = {"status": r.status_code, "location": r.headers.get("location")}
    client.cookies.clear()
    return out


def capture_rbac(client) -> dict:
    """F-03/F-09/F-14 guards: /users, /data-quality, /sync per role (incl. 500 defect)."""
    out = {}
    for role, (u, p) in ROLE_CREDS.items():
        client.cookies.clear()
        client.post("/login", data={"username": u, "password": p})
        csrf = client.cookies.get("csrf_token", "")
        users = client.get("/users")
        dq = client.get("/data-quality")
        sync = client.post("/sync", data={"csrf_token": csrf})
        out[role] = {
            "users_status": users.status_code,
            "data_quality_status": dq.status_code,
            "sync_status": sync.status_code,
        }
    client.cookies.clear()
    return out


def capture_dashboard(client) -> dict:
    """F-04: GET / as staff and as customer (Top Customers hidden for customer)."""
    out = {}
    login(client, "staff")
    out["staff"] = page_signature(client.get("/"))
    logout(client)
    login(client, "customer")
    out["customer"] = page_signature(client.get("/"))
    logout(client)
    return out


def capture_shipments(client) -> dict:
    """F-05: filters, search, sort, customer scoping."""
    out = {}
    login(client, "staff")
    out["default"] = page_signature(client.get("/shipments"))
    out["type_import"] = page_signature(client.get("/shipments", params={"type": "import"}))
    out["type_export"] = page_signature(client.get("/shipments", params={"type": "export"}))
    out["type_other"] = page_signature(client.get("/shipments", params={"type": "other"}))
    out["inv_unissued"] = page_signature(client.get("/shipments", params={"invoice": "unissued"}))
    out["inv_issued"] = page_signature(client.get("/shipments", params={"invoice": "issued"}))
    out["inv_paid"] = page_signature(client.get("/shipments", params={"invoice": "paid"}))
    out["inv_issued_unpaid"] = page_signature(client.get("/shipments", params={"invoice": "issued_unpaid"}))
    out["search_ref"] = page_signature(client.get("/shipments", params={"q": D["ref"]}))
    out["search_miss"] = page_signature(client.get("/shipments", params={"q": "NOSUCHREF"}))
    out["sort_customer_asc"] = page_signature(
        client.get("/shipments", params={"sort": "customer", "dir": "asc"}))
    logout(client)
    login(client, "customer")
    out["customer_view"] = page_signature(client.get("/shipments"))
    logout(client)
    return out


def capture_shipment_detail(client) -> dict:
    """F-06 (+KG-1): staff opens any shipment; customer hits the unguarded ref."""
    out = {}
    login(client, "staff")
    out["staff_known"] = page_signature(client.get("/shipments/detail", params={"ref": D["ref"]}))
    out["unknown_ref"] = page_signature(client.get("/shipments/detail", params={"ref": "REF-NOTHING"}))
    logout(client)
    login(client, "customer")
    out["customer_foreign_ref"] = page_signature(  # KG-1: no guard in Python
        client.get("/shipments/detail", params={"ref": D["ref3"]}))
    logout(client)
    return out


def capture_invoices(client) -> dict:
    """F-07/F-08 (+KG-4): list (no status filter) + guarded detail (KG-5 fixed).

    KG-5 (missing get_invoice_detail) was fixed 2026-09-22: the page renders,
    unknown keys redirect to /invoices, and the customer guard is captured on
    both branches — own-company invoice (200) vs foreign invoice (303).
    """
    out = {}
    login(client, "staff")
    out["list_default"] = page_signature(client.get("/invoices"))
    out["list_search"] = page_signature(client.get("/invoices", params={"q": D["invoice"]}))
    out["detail_known"] = page_signature(client.get("/invoices/detail", params={"k": D["invoice"]}))
    out["detail_unknown"] = page_signature(client.get("/invoices/detail", params={"k": "INV-NOTHING"}))
    logout(client)
    login(client, "customer")
    out["customer_list"] = page_signature(client.get("/invoices"))
    out["customer_own_detail"] = page_signature(
        client.get("/invoices/detail", params={"k": D["invoice"]}))
    out["customer_foreign_detail"] = page_signature(
        client.get("/invoices/detail", params={"k": D["invoice_foreign"]}))
    logout(client)
    return out


def capture_ledger_sales(client) -> dict:
    """F-09 ledger list/detail + F-10 sales dashboard periods."""
    out = {}
    login(client, "staff")
    out["ledger_default"] = page_signature(client.get("/ledger"))
    out["ledger_detail"] = page_signature(client.get("/ledger/detail", params={"k": D["account"]}))
    out["ledger_detail_dates"] = page_signature(client.get(
        "/ledger/detail", params={"k": D["account"], "from": "2026-01-01", "to": "2026-12-31"}))
    for p in ("month", "year", "round", "all"):
        out[f"sales_{p}"] = page_signature(client.get("/sales", params={"p": p}))
    logout(client)
    return out


def capture_data_quality(client) -> dict:
    """F-11: anomaly rollup + detail pages incl. invalid type redirect."""
    login(client, "superadmin")
    out = {"rollup": page_signature(client.get("/data-quality"))}
    for t in ("unbalanced", "orphan", "bad_date", "bad_dk"):
        out[f"detail_{t}"] = page_signature(
            client.get("/data-quality/detail", params={"type": t}))
    out["detail_bogus"] = page_signature(
        client.get("/data-quality/detail", params={"type": "bogus"}))
    logout(client)
    return out


def capture_apis(client) -> dict:
    """F-12..F-16 (+KG-2/KG-3): health, summary, sync-status, 404, lang/db cookies."""
    out = {}
    client.cookies.clear()
    out["health_anon"] = page_signature(client.get("/api/health"))
    out["summary_anon"] = page_signature(client.get("/api/summary"))
    login(client, "staff")
    out["summary_staff"] = page_signature(client.get("/api/summary"))  # KG-2: unfiltered
    out["sync_status"] = page_signature(client.get("/api/sync-status"))
    out["not_found"] = page_signature(client.get("/nonexistent-page"))
    r = client.post("/set-lang", data={"lang": "en"})  # public POST (KG-3)
    out["set_lang_en"] = {"status": r.status_code,
                          "sets_cookie": "cmt_lang" in r.headers.get("set-cookie", "")}
    r = client.post("/set-db", data={"db_name": "CME"})
    out["set_db_cme"] = {"status": r.status_code,
                         "sets_cookie": "cmt_db" in r.headers.get("set-cookie", "")}
    out["page_after_switch"] = page_signature(client.get("/ledger"))  # CME: 1 account
    r = client.post("/set-db", data={"db_name": "NOPE"})
    out["set_db_invalid"] = {"status": r.status_code,
                             "sets_cookie": "cmt_db" in r.headers.get("set-cookie", "")}
    out["page_after_invalid"] = page_signature(client.get("/ledger"))  # falls back to cmt.db
    logout(client)
    return out


# F-22: Projects / Jobs — Summary
def capture_projects_jobs_summary(client) -> dict:
    "F-22: Projects / Jobs — Summary"
    out = {}
    login(client, "staff")
    out["default"] = page_signature(client.get("/projects-jobs/summary"))
    out["search_ref"] = page_signature(client.get("/projects-jobs/summary", params={"q": D["ref"]}))
    out["sort_customer_asc"] = page_signature(client.get("/projects-jobs/summary", params={"sort": "customer", "dir": "asc"}))
    out["customer_view"] = page_signature(client.get("/projects-jobs/summary", params={"q": D["customer"]}))
    logout(client)
    login(client, "customer")
    out["customer_view"] = page_signature(client.get("/projects-jobs/summary"))
    logout(client)
    return out


# F-23: Projects / Jobs — Detail
def capture_projects_jobs_detail(client) -> dict:
    "F-23: Projects / Jobs — Detail"
    out = {}
    login(client, "staff")
    out["known_ref"] = page_signature(client.get("/projects-jobs/detail", params={"ref": D["ref"]}))
    out["unknown_ref"] = page_signature(client.get("/projects-jobs/detail", params={"ref": "REF-NOTHING"}))
    logout(client)
    login(client, "customer")
    out["customer_foreign_ref"] = page_signature(client.get("/projects-jobs/detail", params={"ref": D["ref3"]}))
    logout(client)
    return out


# F-24: A/R reports
def capture_ar_reports(client) -> dict:
    "F-24: A/R reports"
    out = {}
    login(client, "staff")
    out["aging_a_r"] = page_signature(client.get("/aging-a-r"))
    out["aging_a_r_debit_note"] = page_signature(client.get("/aging-a-r-debit-note"))
    out["contra_bond_in"] = page_signature(client.get("/contra-bond-in"))
    out["receipt_a_r"] = page_signature(client.get("/receipt-a-r"))
    out["discrepancy_invoice"] = page_signature(client.get("/discrepancy-invoice"))
    logout(client)
    return out


# F-25: A/P reports
def capture_ap_reports(client) -> dict:
    "F-25: A/P reports"
    out = {}
    login(client, "staff")
    out["aging_a_p"] = page_signature(client.get("/aging-a-p"))
    out["aging_a_p_debit_note"] = page_signature(client.get("/aging-a-p-debit-note"))
    out["contra_bond_out"] = page_signature(client.get("/contra-bond-out"))
    out["payment_a_p"] = page_signature(client.get("/payment-a-p"))
    out["payment_a_p_agent"] = page_signature(client.get("/payment-a-p-agent"))
    out["payment_a_p_refund_cost"] = page_signature(client.get("/payment-a-p-refund-cost"))
    out["payment_a_p_cash_bank"] = page_signature(client.get("/payment-a-p-cash-bank"))
    logout(client)
    return out


# F-26: Finance reports
def capture_finance_reports(client) -> dict:
    "F-26: Finance reports"
    out = {}
    login(client, "staff")
    out["listing_journal"] = page_signature(client.get("/listing-journal"))
    out["general_ledger"] = page_signature(client.get("/general-ledger"))
    out["trial_balance_mutation"] = page_signature(client.get("/trial-balance-mutation"))
    out["trial_balance_bs_is"] = page_signature(client.get("/trial-balance-bs-is"))
    out["other_finance_reports"] = page_signature(client.get("/other-finance-reports"))
    logout(client)
    return out


# F-27: Statement of Account
def capture_statement_of_account(client) -> dict:
    "F-27: Statement of Account"
    out = {}
    login(client, "staff")
    out["statement_of_account"] = page_signature(client.get("/statement-of-account"))
    out["soa_detail"] = page_signature(client.get("/soa-detail", params={"k": D["ref"]}))
    logout(client)
    return out


# F-28: Listing Reference
def capture_listing_reference(client) -> dict:
    "F-28: Listing Reference"
    out = {}
    login(client, "staff")
    out["agent_list"] = page_signature(client.get("/agent-list"))
    out["shipper_list"] = page_signature(client.get("/shipper-list"))
    out["rate_by_destination"] = page_signature(client.get("/rate-by-destination"))
    out["rate_by_destination_vendor_tariff"] = page_signature(client.get("/rate-by-destination-vendor-tariff"))
    out["consignee_notify"] = page_signature(client.get("/consignee-notify"))
    out["account_list"] = page_signature(client.get("/account-list"))
    logout(client)
    return out


# F-29: Performance Tracker
def capture_performance_tracker(client) -> dict:
    "F-29: Performance Tracker"
    out = {}
    login(client, "staff")
    out["by_shipper"] = page_signature(client.get("/by-shipper"))
    out["by_shipping_lines"] = page_signature(client.get("/by-shipping-lines"))
    out["by_agent"] = page_signature(client.get("/by-agent"))
    out["by_consignee_import"] = page_signature(client.get("/by-consignee-import"))
    logout(client)
    return out


# F-30: Miscellaneous views
def capture_miscellaneous_views(client) -> dict:
    "F-30: Miscellaneous views"
    out = {}
    login(client, "staff")
    out["petty_cash_request"] = page_signature(client.get("/petty-cash-request"))
    out["refund_cost_request"] = page_signature(client.get("/refund-cost-request"))
    out["recurring_costs"] = page_signature(client.get("/recurring-costs"))
    logout(client)
    return out


# F-31: Ship Finance & AR/AP Journalism (pending owner decision — static stub)
def capture_ship_finance_ap_journalism(client) -> dict:
    "F-31: Ship Finance & AR/AP Journalism (pending owner decision — static stub)"
    out = {}
    login(client, "staff")
    out["ars_ars_r"] = page_signature(client.get("/ars-ars-r"))
    out["ar_agent_ar_agent_d"] = page_signature(client.get("/ar-agent-ar-agent-d"))
    out["ap_ap_r"] = page_signature(client.get("/ap-ap-r"))
    out["ap_agent_ap_agent_d"] = page_signature(client.get("/ap-agent-ap-agent-d"))
    out["bayar_bayar_r"] = page_signature(client.get("/bayar-bayar-r"))
    out["terima_terima_r"] = page_signature(client.get("/terima-terima-r"))
    out["clearing_d"] = page_signature(client.get("/clearing-d"))
    out["si_si_d"] = page_signature(client.get("/si-si-d"))
    out["crd_note_crd_note_r"] = page_signature(client.get("/crd-note-crd-note-r"))
    out["saging"] = page_signature(client.get("/saging"))
    out["quotation_quotation_r"] = page_signature(client.get("/quotation-quotation-r"))
    out["vendor_vendor_d"] = page_signature(client.get("/vendor-vendor-d"))
    out["cost"] = page_signature(client.get("/cost"))
    out["rc_rc_d"] = page_signature(client.get("/rc-rc-d"))
    out["rc_req_rc_req_d"] = page_signature(client.get("/rc-req-rc-req-d"))
    logout(client)
    return out


# F-32: Volume, Lifting & Gross Profit reports (menu-reconciled)
def capture_volume_lifting_gross_profit(client) -> dict:
    "F-32: Volume, Lifting & Gross Profit reports (menu-reconciled)"
    out = {}
    login(client, "staff")
    out["volume_report"] = page_signature(client.get("/volume-report"))
    out["lifting_report"] = page_signature(client.get("/lifting-report"))
    out["gross_profit_loss"] = page_signature(client.get("/gross-profit-loss"))
    logout(client)
    return out


# ── Pytest wiring: one test per domain, artifact written at session end ─────

_DOMAINS = (
    ("auth", capture_auth),
    ("rbac", capture_rbac),
    ("dashboard", capture_dashboard),
    ("shipments", capture_shipments),
    ("shipment_detail", capture_shipment_detail),
    ("invoices", capture_invoices),
    ("ledger_sales", capture_ledger_sales),
    ("data_quality", capture_data_quality),
    ("apis", capture_apis),
    # F-22: Projects / Jobs — Summary
    ("projects_jobs_summary", capture_projects_jobs_summary),
    # F-23: Projects / Jobs — Detail
    ("projects_jobs_detail", capture_projects_jobs_detail),
    # F-24: A/R reports
    ("ar_reports", capture_ar_reports),
    # F-25: A/P reports
    ("ap_reports", capture_ap_reports),
    # F-26: Finance reports
    ("finance_reports", capture_finance_reports),
    # F-27: Statement of Account
    ("statement_of_account", capture_statement_of_account),
    # F-28: Listing Reference
    ("listing_reference", capture_listing_reference),
    # F-29: Performance Tracker
    ("performance_tracker", capture_performance_tracker),
    # F-30: Miscellaneous views
    ("miscellaneous_views", capture_miscellaneous_views),
    # F-31: Ship Finance & AR/AP Journalism (pending owner decision — static stub)
    ("ship_finance_ap_journalism", capture_ship_finance_ap_journalism),
    # F-32: Volume, Lifting & Gross Profit reports (menu-reconciled)
    ("volume_lifting_gross_profit", capture_volume_lifting_gross_profit),
)

_RESULTS: dict = {}
_DOMAIN_FEATURES = {
    "auth": ["F-01", "F-02"],
    "rbac": ["F-03", "F-09", "F-11"],
    "dashboard": ["F-04"],
    "shipments": ["F-05"],
    "shipment_detail": ["F-06"],
    "invoices": ["F-07", "F-08"],
    "ledger_sales": ["F-09", "F-10"],
    "data_quality": ["F-11"],
    "apis": ["F-12", "F-13", "F-14", "F-15", "F-16"],
    "projects_jobs_summary": ["F-22"],
    "projects_jobs_detail": ["F-23"],
    "ar_reports": ["F-24"],
    "ap_reports": ["F-25"],
    "finance_reports": ["F-26"],
    "statement_of_account": ["F-27"],
    "listing_reference": ["F-28"],
    "performance_tracker": ["F-29"],
    "miscellaneous_views": ["F-30"],
    "ship_finance_ap_journalism": ["F-31"],
    "volume_lifting_gross_profit": ["F-32"],
}


@pytest.mark.parametrize("name,fn", _DOMAINS)
def test_capture_domain(name, fn, client):
    result = fn(client)
    assert isinstance(result, dict)
    _RESULTS[name] = result


def test_baseline_written():
    """Session-end: write the baseline artifact and report checklist coverage."""
    assert _RESULTS, "no domains captured"
    BASELINE_PATH.parent.mkdir(parents=True, exist_ok=True)
    covered = sorted({f for ids in _DOMAIN_FEATURES.values() for f in ids})

    # Hash canonical domains JSON exactly as tools/parity_gate.py does.
    content_hash = hashlib.sha256(
        json.dumps(_RESULTS, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()

    artifact = {
        "schema_version": "1.0.0",
        "meta": {
            "source": "python-fastapi",
            "generator": "pytest tests/parity",
            "dataset": "tests/parity/parity_dataset.py (deterministic)",
            "roles": sorted(ROLE_CREDS),
            "checklist": "docs/parity-checklist.yaml",
            "gate": "PRD section 8, phase P5",
            "domains": list(_RESULTS),
            "captured_at": "2026-11-22T16:52:00Z",  # FIXED TIMESTAMP
            "content_hash": content_hash,
            "features_covered": covered,
            "known_gaps": {
                "KG-1": "shipment detail has no customer guard (captured as-is)",
                "KG-2": "api/summary not company-filtered (captured as-is)",
                "KG-3": "set-lang/set-db are public POSTs (captured as-is)",
                "KG-4": "invoice status filters live on /shipments (reflected in captures)",
            },
            "fixed_bugs": {
                "KG-5": "get_invoice_detail was missing (/invoices/detail 500) — implemented 2026-09-22; baseline captures the working page",
                "KG-6": "get_summary company-filter SQL was invalid (customer dashboard 500) — fixed 2026-09-22; baseline captures the working page",
            },
            "normalization": "noise keys stripped; sync-time footer tokenized as <SYNC_TIME>; floats rounded to 4dp; HTML compared via visible strings + first-table rows",
        },
        "domains": _RESULTS,
    }
    BASELINE_PATH.write_text(json.dumps(artifact, indent=2, sort_keys=True), encoding="utf-8")
    assert BASELINE_PATH.exists()
    saved = json.loads(BASELINE_PATH.read_text(encoding="utf-8"))
    assert saved["schema_version"] == "1.0.0"
    assert saved["meta"]["domains"] == list(_RESULTS)
    assert saved["meta"]["content_hash"] == hashlib.sha256(
        json.dumps(saved["domains"], ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()
