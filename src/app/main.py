from contextlib import asynccontextmanager
from datetime import datetime, timezone
from pathlib import Path
import logging
import os
import secrets
import sys
import threading
import urllib.parse

from fastapi import FastAPI, Form, Query, Request, Depends, Response
from fastapi.exceptions import HTTPException
from fastapi.responses import RedirectResponse, HTMLResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

from .auth import (
    User,
    STORE,
    SESSION_COOKIE,
    get_current_user,
    require_login,
    require_api_login,
    require_role,
    session_cookie_secure,
)
from . import queries as q
from . import db
from .db import discover_databases, set_active_db
from .logging_config import configure as configure_logging
from .middleware import RequestLogMiddleware
from .sync import run_sync

# ── In-memory login rate limiter (stdlib only) ───────────────────────────
# Per-IP sliding window: max LOGIN_RATE_LIMIT attempts per LOGIN_RATE_WINDOW
# seconds. In-memory is fine for single-process uvicorn deploys; behind a
# reverse proxy this should move to a shared store (Redis).
_LOGIN_RATE_LIMIT = 5
_LOGIN_RATE_WINDOW = 60  # seconds
_login_attempts: dict[str, list[float]] = {}
_login_attempts_lock = threading.Lock()


def _check_csrf(request: Request, csrf_token: str) -> None:
    """Reject POST state-changing requests without a valid double-submit CSRF token."""
    cookie_token = request.cookies.get("csrf_token", "")
    if not csrf_token or csrf_token != cookie_token:
        raise HTTPException(status_code=403, detail="CSRF token missing or invalid")


def require_csrf() -> dict:
    """Dependency: generates a per-request CSRF token for POST state-changing forms."""
    return {"csrf_token": secrets.token_urlsafe(32)}


def _client_ip(request: Request) -> str:
    # Use request.client.host only; X-Forwarded-For is untrusted client input.
    # Behind a reverse proxy, configure the proxy to set the real IP in
    # request.client.host (e.g. uvicorn --proxy-headers) and move rate
    # limiting to a shared store (Redis) keyed by the proxy-sourced IP.
    return request.client.host if request.client else "unknown"


def _check_login_rate_limit(request: Request) -> None:
    now = datetime.now(timezone.utc).timestamp()
    ip = _client_ip(request)
    window_start = now - _LOGIN_RATE_WINDOW
    with _login_attempts_lock:
        attempts = [t for t in _login_attempts.get(ip, []) if t > window_start]
        if len(attempts) >= _LOGIN_RATE_LIMIT:
            raise HTTPException(status_code=429, detail="Too many login attempts")
        attempts.append(now)
        _login_attempts[ip] = attempts

DBF_DIR = os.environ.get("DBF_DIR", str(Path(__file__).resolve().parents[2] / "DBF"))
DB_PATH = os.environ.get("DATABASE_URL", str(Path(__file__).resolve().parents[2] / "data" / "cmt.db"))
DATA_DIR = os.environ.get("DATA_DIR", str(Path(DB_PATH).parent))

# How old the DB may be before /api/sync-status flags it stale.
STALE_AFTER_SECONDS = 12 * 60 * 60  # 12 hours

# Rows per page in the dashboard's unpaid-shipments table.
UNPAID_PER_PAGE = 5

# Rows per page on the /shipments listing page.
SHIPMENTS_PER_PAGE = 25

# Rows per page on the /ledger page.
LEDGER_PER_PAGE = 50

# Rows per page on F-28 reference listing pages (build spec: 25 each).
# Config-owned page size — no user-facing page-size selector per spec.
AGENTS_PER_PAGE = 25
SHIPPERS_PER_PAGE = 25
CONSIGNEES_PER_PAGE = 25
ACCOUNTS_PER_PAGE = 25





def _money(value, currency: str = "usd", fmt: str = "full") -> str:
    """Format a number in accounting style for the templates.

    USD uses comma thousands separators ($1,171.50); IDR uses the
    Indonesian dot convention (Rp 2.825.370, whole rupiah). Negative
    amounts are wrapped in parentheses — ("($1,320.00)" / "(Rp 2.825.370)") —
    the accounting convention. None -> "" (the template renders its own dash).

    When *fmt* is ``"abbr"``, abbreviate large numbers:
    USD → K ($1.2K); IDR → M (Rp 1.2M).
    """
    if value is None:
        return ""
    if isinstance(value, str):
        try:
            value = float(value)
        except ValueError:
            return ""
    negative = value < 0
    open_paren, close_paren = ("(", ")") if negative else ("", "")
    abs_val = abs(value)
    if fmt == "abbr":
        if currency == "idr":
            if abs_val >= 1_000_000:
                num = f"{abs_val / 1_000_000:,.1f}".rstrip("0").rstrip(".")
                return f"{open_paren}Rp {num}M{close_paren}"
            if abs_val >= 1_000:
                num = f"{abs_val / 1_000:,.0f}"
                return f"{open_paren}Rp {num}K{close_paren}"
            number = f"{abs_val:,.0f}".replace(",", ".")
            return f"{open_paren}Rp {number}{close_paren}"
        else:  # usd
            if abs_val >= 1_000_000:
                num = f"{abs_val / 1_000_000:,.1f}".rstrip("0").rstrip(".")
                return f"{open_paren}${num}M{close_paren}"
            if abs_val >= 1_000:
                num = f"{abs_val / 1_000:,.1f}".rstrip("0").rstrip(".")
                return f"{open_paren}${num}K{close_paren}"
            return f"{open_paren}${abs_val:,.2f}{close_paren}"
    if currency == "idr":
        number = f"{abs_val:,.0f}".replace(",", ".")
        return f"{open_paren}Rp {number}{close_paren}"
    return f"{open_paren}${abs_val:,.2f}{close_paren}"


def _num_plain(value) -> str:
    """Format a number without a currency symbol, IDR grouping (1.234.567,89).

    Used for table columns whose currency is carried by the row (e.g. JE
    header TOT_DR/TOT_CR in the data-quality detail), so a footer sum can
    share the same unit label without hardcoding one.
    """
    if value is None:
        return ""
    if isinstance(value, str):
        try:
            value = float(value)
        except ValueError:
            return ""
    negative = value < 0
    abs_val = abs(value)
    whole, dot, frac = f"{abs_val:,.2f}".partition(".")
    whole = whole.replace(",", ".")
    frac = frac.rstrip("0").rstrip(".")
    num = f"{whole},{frac}" if frac else whole
    return f"({num})" if negative else num


# ── Startup verification ────────────────────────────────────────────────

def _verify_startup() -> None:
    """Verify required secret environment variables are set."""
    # In parity mode, the test harness manages its own environment.
    if '_PARITY_MODE' in os.environ:
        return
    users_env = os.environ.get('CMT_USERS', '')
    if not users_env:
        msg = ("CRITICAL: CMT_USERS environment variable must be set. "
               "No users will be created. Set it in .env or pass via docker-compose/environment.")
        print(f"ERROR: {msg}", file=sys.stderr)
        raise SystemExit(1)


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Ensure the DB file exists and app schema (users, sessions) is present.
    db_path = Path(DB_PATH)
    if not db_path.exists():
        print(f"WARNING: DB not found at {db_path}, creating empty file.")
        db_path.parent.mkdir(exist_ok=True, parents=True)
        db_path.touch()
    db.init_schema()
    _verify_startup()
    print("Server started with user seeding from CMT_USERS.")
    yield


class SecurityHeadersMiddleware:
    """Add security headers to every HTTP response (CSP, XCTO, XFO, Referrer-Policy)."""

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        async def wrapped_send(message):
            if message["type"] == "http.response.start":
                headers = dict(message.get("headers", []))
                headers[b"x-content-type-options"] = b"nosniff"
                headers[b"x-frame-options"] = b"DENY"
                headers[b"referrer-policy"] = b"same-origin"
                headers[b"content-security-policy"] = (
                    b"default-src 'self' 'unsafe-inline'; "
                    b"script-src 'self' 'unsafe-inline'; "
                    b"style-src 'self' 'unsafe-inline'; "
                    b"img-src 'self' data:; "
                    b"connect-src 'self'"
                )
                message["headers"] = [
                    (k.encode() if isinstance(k, str) else k, v.encode() if isinstance(v, str) else v)
                    for k, v in headers.items()
                ]
            await send(message)

        await self.app(scope, receive, wrapped_send)


app = FastAPI(
    title="CMT Dashboard",
    description="Read-only dashboard over legacy FoxPro cargo/accounting databases.",
    version="1.0.0",
    lifespan=lifespan,
)
app.add_middleware(RequestLogMiddleware)
app.add_middleware(SecurityHeadersMiddleware)
configure_logging()


logger = logging.getLogger("cmt.main")


@app.exception_handler(404)
async def not_found_handler(request: Request, exc: HTTPException):
    lang = lang_from_request(request)
    # Resolve the real session user so logged-in visitors keep their sidebar
    # (and don't get logged-out chrome) on a bad URL.
    user = get_current_user(session=request.cookies.get(SESSION_COOKIE))
    return templates.TemplateResponse(
        request=request,
        name="404.html",
        context={
            "user": user,
            "_t": (lambda k: _t(lang, k)),
            "_lang": lang,
            "sync_time": "",
            "asset_ver": ASSET_VER,
            "available_dbs": [],
            "active_db_name": "",
            "active_page": None,
        },
        status_code=404,
    )

TEMPLATES_DIR = Path(__file__).parent / "templates"
templates = Jinja2Templates(directory=str(TEMPLATES_DIR))
templates.env.filters["money"] = _money
templates.env.filters["num"] = _num_plain

from .template_helpers import badge_class, anomaly_label
templates.env.globals["badge_class"] = badge_class
templates.env.globals["anomaly_label"] = anomaly_label

from .i18n import t as _t, lang_from_request  # noqa: E402


def _login_ctx(lang: str, error: str | None = None) -> dict:
    return {"user": User(), "error": error, "_t": (lambda k: _t(lang, k)), "_lang": lang}

STATIC_DIR = Path(__file__).parent / "static"
if STATIC_DIR.exists():
    app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")

# Cache-buster for the app's own JS/CSS: bump the asset URL whenever the file
# changes so browsers never keep serving a stale cached copy after a deploy.
_ASSET_FILES = (STATIC_DIR / "layout.js", STATIC_DIR / "theme.css")
ASSET_VER = "".join(
    f"-{f.stat().st_mtime_ns & 0xFFFFFF:x}" for f in _ASSET_FILES if f.exists()
)
ASSET_VER = ASSET_VER or "0"


def _render(request: Request, template: str, user: User, **kwargs):
    lang = lang_from_request(request)
    try:
        sync_time = datetime.fromtimestamp(Path(DB_PATH).stat().st_mtime).strftime("%Y-%m-%d %H:%M")
    except OSError:
        sync_time = "—"
    # Discover available company databases for the selector dropdown.
    available_dbs = discover_databases(DATA_DIR)
    # Determine which DB is currently active.
    from .db import get_active_db
    active_db_path = get_active_db()
    active_db_name = Path(active_db_path).stem if active_db_path else Path(DB_PATH).stem
    csrf = request.cookies.get("csrf_token", "")
    set_csrf_cookie = not csrf
    if set_csrf_cookie:
        csrf = secrets.token_urlsafe(32)
    ctx = {
        "user": user,
        "_t": (lambda k: _t(lang, k)),
        "_lang": lang,
        "sync_time": sync_time,
        "asset_ver": ASSET_VER,
        "available_dbs": available_dbs,
        "active_db_name": active_db_name,
        "active_page": kwargs.pop("active_page", None),
        "csrf_token": csrf,
        **kwargs,
    }
    response = templates.TemplateResponse(
        request=request,
        name=template,
        context=ctx,
    )
    if set_csrf_cookie:
        response.set_cookie(
            "csrf_token", csrf,
            httponly=False, samesite="lax", secure=session_cookie_secure(),
        )
    return response


# ── Auth routes ──────────────────────────────────────────────────────────

@app.get("/login")
def login_get(request: Request):
    lang = lang_from_request(request)
    return templates.TemplateResponse(
        request=request,
        name="login.html",
        context=_login_ctx(lang),
    )


@app.post("/login")
def login_post(
    request: Request,
    response: Response,
    username: str = Form(...),
    password: str = Form(...),
    _: None = Depends(_check_login_rate_limit),
):
    lang = lang_from_request(request)
    token = STORE.authenticate(username, password)
    if not token:
        return templates.TemplateResponse(
            request=request, name="login.html", context=_login_ctx(lang, error=_t(lang, "Username or password incorrect"))
        )
    resp = RedirectResponse(url="/", status_code=302)
    resp.set_cookie(
        SESSION_COOKIE, token,
        httponly=True, samesite="lax", secure=session_cookie_secure(),
    )
    return resp


@app.post("/logout")
def logout(request: Request, user: User = Depends(get_current_user)):
    # Invalidate server-side session, then clear the cookie.
    token = request.cookies.get(SESSION_COOKIE)
    if token:
        STORE.logout(token)
    response = RedirectResponse(url="/login", status_code=302)
    response.delete_cookie(SESSION_COOKIE)
    # CSRF is not enforced on logout for backward compat with test harnesses.
    return response


# ── App routes ───────────────────────────────────────────────────────────

@app.get("/")
def index(
    request: Request,
    page: int = Query(1, ge=1),
    search: str = Query("", alias="q"),
    sort: str = Query("date"),
    sort_dir: str = Query("desc", alias="dir"),
    user: User = Depends(require_login()),
):
    company = user.customer_company or ""
    summary = q.get_summary(company_filter=company)
    unpaid = q.get_unpaid_shipments(
        page=page, per_page=UNPAID_PER_PAGE, search=search, sort=sort, sort_dir=sort_dir,
        company_filter=company,
    )
    top_customers = q.get_top_customers() if user.role != "customer" else None
    return _render(
        request,
        "index.html",
        user=user,
        summary=summary,
        unpaid=unpaid,
        top_customers=top_customers,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        page_nums=list(range(max(1, unpaid["page"] - 2), min(unpaid["pages"], unpaid["page"] + 2) + 1)),
        active_page="home",
    )


@app.get("/shipments")
def shipments(
    request: Request,
    page: int = Query(1, ge=1),
    search: str = Query("", alias="q"),
    sort: str = Query("date"),
    sort_dir: str = Query("desc", alias="dir"),
    type: str = Query("", alias="type"),
    invoice: str = Query("", alias="invoice"),
    user: User = Depends(require_login()),
):
    result = q.get_shipments(
        page=page,
        per_page=SHIPMENTS_PER_PAGE,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        company_filter=user.customer_company,
        type_filter=type,
        invoice_filter=invoice,
    )
    return _render(
        request,
        "shipments.html",
        user=user,
        shipments=result,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        type_filter=type,
        invoice_filter=invoice,
        page_nums=list(range(max(1, result["page"] - 2), min(result["pages"], result["page"] + 2) + 1)),
    )


@app.get("/projects-jobs")
def projects_jobs(
    request: Request,
    page: int = Query(1, ge=1),
    search: str = Query("", alias="q"),
    sort: str = Query("date"),
    sort_dir: str = Query("desc", alias="dir"),
    type: str = Query("", alias="type"),
    user: User = Depends(require_login()),
):
    result = q.get_projects_jobs_summary(
        page=page,
        per_page=SHIPMENTS_PER_PAGE,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        company_filter=user.customer_company,
        type_filter=type,
    )
    return _render(
        request,
        "projects_jobs.html",
        user=user,
        projects=result,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        type_filter=type,
        page_nums=list(range(max(1, result["page"] - 2), min(result["pages"], result["page"] + 2) + 1)),
        active_page="projects_jobs",
    )


INVOICES_PER_PAGE = 25

@app.get("/shipments/detail")
def shipment_detail(
    request: Request,
    ref: str = Query(...),
    user: User = Depends(require_login()),
):
    detail = q.get_shipment_detail(ref)
    if not detail:
        return RedirectResponse(url="/shipments", status_code=303)
    return _render(
        request,
        "shipment_detail.html",
        user=user,
        detail=detail,
        ref=ref,
        active_page="shipments",
    )


@app.get("/invoices")
def invoices(
    request: Request,
    page: int = Query(1, ge=1),
    search: str = Query("", alias="q"),
    sort: str = Query("date"),
    sort_dir: str = Query("desc", alias="dir"),
    user: User = Depends(require_login()),
):
    result = q.get_invoices(
        page=page,
        per_page=INVOICES_PER_PAGE,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        company_filter=user.customer_company,
    )
    return _render(
        request,
        "invoices.html",
        user=user,
        invoices=result,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        page_nums=list(range(max(1, result["page"] - 2), min(result["pages"], result["page"] + 2) + 1)),
        active_page="invoices",
    )


@app.get("/invoices/detail")
def invoice_detail(
    request: Request,
    k: str = Query(...),
    user: User = Depends(require_login()),
):
    detail = q.get_invoice_detail(k)
    if not detail:
        return RedirectResponse(url="/invoices", status_code=303)
    # Customer-role users may only view invoices linked to their company.
    if user.customer_company:
        customer = (detail["header"]["CUSTOMER"] or "").upper()
        if user.customer_company.upper() not in customer:
            return RedirectResponse(url="/invoices", status_code=303)
    return _render(
        request,
        "invoice_detail.html",
        user=user,
        detail=detail,
        k=k,
        active_page="invoices",
    )


@app.get("/ledger")
def ledger_list(
    request: Request,
    page: int = Query(1, ge=1),
    search: str = Query("", alias="q"),
    sort: str = Query("code"),
    sort_dir: str = Query("asc", alias="dir"),
    type: str = Query(""),
    user: User = Depends(require_login()),
):
    result = q.get_accounts(
        page=page,
        per_page=LEDGER_PER_PAGE,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        type_filter=type,
    )
    return _render(
        request,
        "general_ledger.html",
        user=user,
        accounts=result,
        search=search,
        sort=sort,
        sort_dir=sort_dir,
        type_filter=type,
        page_nums=list(range(max(1, result["page"] - 2), min(result["pages"], result["page"] + 2) + 1)),
        active_page="ledger",
    )


@app.get("/ledger/detail")
def ledger_detail(
    request: Request,
    k: str = Query(...),
    page: int = Query(1, ge=1),
    date_from: str = Query("", alias="from"),
    date_to: str = Query("", alias="to"),
    search: str = Query("", alias="q"),
    sort: str = Query("date"),
    sort_dir: str = Query("desc", alias="dir"),
    user: User = Depends(require_login()),
):
    # Default newest-first: ascending order surfaces the known corrupted
    # (year < 2000) journal dates at the very top of a 36K-row account.
    detail = q.get_account_detail(
        kd_account=k,
        date_from=date_from,
        date_to=date_to,
        page=page,
        per_page=LEDGER_PER_PAGE,
        sort=sort,
        sort_dir=sort_dir,
    )
    if not detail:
        return RedirectResponse(url="/ledger", status_code=303)
    return _render(
        request,
        "ledger_detail.html",
        user=user,
        detail=detail,
        k=k,
        date_from=date_from,
        date_to=date_to,
        search=search,
        active_page="ledger",
        sort=sort,
        sort_dir=sort_dir,
        page_nums=list(range(max(1, detail["page"] - 2), min(detail["pages"], detail["page"] + 2) + 1)),
    )


@app.get("/sales")
def sales(
    request: Request,
    period: str = Query("all", alias="p"),
    user: User = Depends(require_login()),
):
    if period not in ("month", "year", "round", "all"):
        period = "all"
    summary = q.get_sales_summary(company_filter=user.customer_company)
    trend = q.get_sales_monthly_trend(company_filter=user.customer_company)
    top_customers = q.get_sales_top_customers(company_filter=user.customer_company)
    top_salesman = q.get_sales_top_salesman(company_filter=user.customer_company, period=period)
    return _render(
        request,
        "sales.html",
        user=user,
        summary=summary,
        trend=trend,
        top_customers=top_customers,
        top_salesman=top_salesman,
        period=period,
        active_page="sales",
    )


@app.get("/data-quality")
def data_quality(
    request: Request,
    search: str = Query("", alias="q"),
    user: User = Depends(require_role("superadmin")),
):
    # Scope to the company selected in the header DB selector — the page
    # shows only the anomalies of the currently active company DB.
    from .db import get_active_db
    active_company = Path(get_active_db()).stem.upper()
    anomalies = q.get_gl_anomalies(company_filter=active_company)
    # Group by (company, type) for the summary rollup
    companies_with_issues = sorted(
        {a["company"] for a in anomalies},
        key=lambda c: c.upper(),
    )
    return _render(
        request,
        "data_quality.html",
        user=user,
        anomalies=anomalies,
        companies=companies_with_issues,
        active_company=active_company,
        active_page="data_quality",
    )


@app.get("/data-quality/detail")
def data_quality_detail(
    request: Request,
    anomaly_type: str = Query(..., alias="type"),
    page: int = Query(1, ge=1),
    user: User = Depends(require_role("superadmin")),
):
    from .db import get_active_db
    active_db = get_active_db()
    active_company = Path(active_db).stem.upper()
    VALID_TYPES = {"unbalanced", "orphan", "bad_date", "bad_dk"}
    if anomaly_type not in VALID_TYPES:
        return RedirectResponse(url="/data-quality", status_code=303)
    detail = q.get_gl_anomaly_detail(anomaly_type, company_filter=active_company,
                                     page=page, per_page=100, db_path=active_db)
    page_nums = list(range(max(1, detail["page"] - 2),
                           min(detail["pages"], detail["page"] + 2) + 1))
    return _render(
        request,
        "data_quality_detail.html",
        user=user,
        detail=detail,
        page_nums=page_nums,
        active_page="data_quality",
    )


# ── Reference listings (F-28) ─────────────────────────────────────────────
@app.get("/agents")
def agents_page(request: Request, page: int = Query(1, ge=1), search: str = Query("", alias="q"),
                sort: str = Query("name"), sort_dir: str = Query("asc", alias="dir"),
                user: User = Depends(require_login())):
    result = q.get_agents(page=page, per_page=AGENTS_PER_PAGE, search=search, sort=sort, sort_dir=sort_dir)
    return _render(request, "agents.html", user=user, agents=result, search=search, sort=sort,
                   sort_dir=sort_dir, page_nums=list(range(max(1, result["page"]-2), min(result["pages"], result["page"]+2)+1)),
                   active_page="agents")

@app.get("/shippers")
def shippers_page(request: Request, page: int = Query(1, ge=1), search: str = Query("", alias="q"),
                 sort: str = Query("name"), sort_dir: str = Query("asc", alias="dir"),
                 user: User = Depends(require_login())):
    result = q.get_shippers(page=page, per_page=SHIPPERS_PER_PAGE, search=search, sort=sort, sort_dir=sort_dir)
    return _render(request, "shippers.html", user=user, shippers=result, search=search, sort=sort,
                   sort_dir=sort_dir, page_nums=list(range(max(1, result["page"]-2), min(result["pages"], result["page"]+2)+1)),
                   active_page="shippers")

@app.get("/consignees")
def consignees_page(request: Request, page: int = Query(1, ge=1), search: str = Query("", alias="q"),
                    sort: str = Query("name"), sort_dir: str = Query("asc", alias="dir"),
                    user: User = Depends(require_login())):
    result = q.get_consignees(page=page, per_page=CONSIGNEES_PER_PAGE, search=search, sort=sort, sort_dir=sort_dir)
    return _render(request, "consignees.html", user=user, consignees=result, search=search, sort=sort,
                   sort_dir=sort_dir, page_nums=list(range(max(1, result["page"]-2), min(result["pages"], result["page"]+2)+1)),
                   active_page="consignees")


@app.get("/accounts")
def accounts_page(request: Request, page: int = Query(1, ge=1), search: str = Query("", alias="q"),
                  sort: str = Query("code"), sort_dir: str = Query("asc", alias="dir"),
                  type_filter: str = Query(""), user: User = Depends(require_login())):
    result = q.get_accounts(page=page, per_page=ACCOUNTS_PER_PAGE, search=search, sort=sort, sort_dir=sort_dir, type_filter=type_filter)
    return _render(request, "accounts.html", user=user, accounts=result, search=search, sort=sort,
                   sort_dir=sort_dir, type_filter=type_filter,
                   page_nums=list(range(max(1, result["page"]-2), min(result["pages"], result["page"]+2)+1)),
                   active_page="accounts")

# ── User management (admin+) ─────────────────────────────────────────────

@app.get("/users")
def users_list(
    request: Request,
    user: User = Depends(require_role("admin")),
):
    companies = q.get_company_names()
    return _render(request, "users.html", user=user,
                   users=STORE.list_users(), editing=None, error=None,
                   companies=companies, active_page="users")


@app.post("/users/new")
def users_create(
    request: Request,
    username: str = Form(...),
    display_name: str = Form(""),
    role: str = Form("staff"),
    password: str = Form(...),
    customer_company: str = Form(""),
    csrf_token: str = Form(""),
    user: User = Depends(require_role("admin")),
):
    _check_csrf(request, csrf_token)
    companies = q.get_company_names()
    err = STORE.create_user(username, display_name, role, password, customer_company)
    if err:
        return _render(request, "users.html", user=user,
                       users=STORE.list_users(), editing=None, error=err,
                       companies=companies, active_page="users")
    return RedirectResponse(url="/users", status_code=303)


@app.get("/users/{user_id}/edit")
def users_edit_form(
    request: Request,
    user_id: int,
    user: User = Depends(require_role("admin")),
):
    companies = q.get_company_names()
    editing = STORE.get_user(user_id)
    if not editing:
        return RedirectResponse(url="/users", status_code=303)
    return _render(request, "users.html", user=user,
                   users=STORE.list_users(), editing=editing, error=None,
                   companies=companies, active_page="users")


@app.post("/users/{user_id}/edit")
def users_edit(
    request: Request,
    user_id: int,
    display_name: str = Form(...),
    role: str = Form("staff"),
    password: str = Form(""),
    customer_company: str = Form(""),
    csrf_token: str = Form(""),
    user: User = Depends(require_role("admin")),
):
    _check_csrf(request, csrf_token)
    companies = q.get_company_names()
    err = STORE.update_user(user_id, display_name, role, customer_company, password)
    if err:
        editing = STORE.get_user(user_id)
        return _render(request, "users.html", user=user,
                       users=STORE.list_users(), editing=editing, error=err,
                       companies=companies, active_page="users")
    return RedirectResponse(url="/users", status_code=303)


@app.post("/users/{user_id}/delete")
def users_delete(
    request: Request,
    user_id: int,
    csrf_token: str = Form(""),
    user: User = Depends(require_role("superadmin")),
):
    _check_csrf(request, csrf_token)
    # Prevent self-deletion
    target = STORE.get_user(user_id)
    if target and target["username"] == user.username:
        return RedirectResponse(url="/users", status_code=303)
    STORE.delete_user(user_id)
    return RedirectResponse(url="/users", status_code=303)


@app.post("/sync")
def trigger_sync(
    request: Request,
    user: User = Depends(require_role("management")),
    csrf_token: str = Form(""),
):
    _check_csrf(request, csrf_token)
    """
    Pull latest DBF → SQLite. Gated to management and above.
    Runs the sync script as a subprocess and returns the result.
    """
    python_exe = getattr(sys, '_base_executable', sys.executable)
    result = run_sync(DBF_DIR, DB_PATH, python_exe)

    if result["ok"]:
        # Re-create unified view after schema refresh, and ensure app-internal
        # tables (users/sessions) exist in the freshly replaced DB.
        conn = db.get_db()
        try:
            q.create_unified_view(conn)
            db.init_schema()
            print("Unified view re-created successfully after sync.")
        except Exception as e:
            print(f"Error re-creating view after sync: {e}")
            # The sync itself was ok, but the view failed. Return a mixed success.
            return {
                "ok": False,
                "triggered_by": user.username,
                "detail": "Sync OK, but view creation failed.",
                "stderr": str(e),
            }
        finally:
            conn.close()

    # Return a summary of the sync result
    detail_msg = "Sync OK."
    if not result["ok"]:
        detail_msg = result.get("stderr") or "Sync script failed with no output."

    return {
        "ok": result["ok"],
        "triggered_by": user.username,
        "detail": detail_msg,
        "stdout_tail": result["stdout"].splitlines()[-10:],
        "stderr_tail": result["stderr"].splitlines()[-10:],
    }


# public-endpoint: liveness probe
@app.get("/api/health")
def api_health():
    return {"status": "ok"}


@app.get("/api/summary")
def api_summary(user: User = Depends(require_api_login())):
    return q.get_summary()

@app.get("/aging/ar")
def aging_ar_page(request: Request, company: str = Query(""), as_of: str = Query(""), min_amount: str = Query(""), counterparty: str = Query(""), user: User = Depends(require_login())):
    return _render(request, "aging_ar.html", user=user, company=company or (user.customer_company or ""), as_of=as_of, min_amount=min_amount, counterparty=counterparty, active_page="aging_ar")

@app.get("/aging/ap")
def aging_ap_page(request: Request, company: str = Query(""), as_of: str = Query(""), min_amount: str = Query(""), counterparty: str = Query(""), user: User = Depends(require_login())):
    return _render(request, "aging_ap.html", user=user, company=company or (user.customer_company or ""), as_of=as_of, min_amount=min_amount, counterparty=counterparty, active_page="aging_ap")

@app.get("/ap-payment")
def ap_payment_page(request: Request, company: str = Query(""), date_from: str = Query(""), date_to: str = Query(""),
                    vendor: str = Query(""), agent: str = Query(""), invoice_no: str = Query(""),
                    payment_method: str = Query(""), status: str = Query("all"),
                    sort: str = Query("date"), sort_dir: str = Query("desc"),
                    user: User = Depends(require_role("staff"))):
    from datetime import date
    if not date_from or not date_to:
        today = date.today()
        date_from = date_from or today.replace(day=1).isoformat()
        date_to = date_to or today.isoformat()
    return _render(request, "ap_payment.html", user=user, company=company or "CMT",
                   date_from=date_from, date_to=date_to, vendor=vendor, agent=agent, invoice_no=invoice_no,
                   payment_method=payment_method, status=status, sort=sort, sort_dir=sort_dir,
                   active_page="ap_payment")

@app.get("/ar-receipt")
def ar_receipt_page(request: Request, company: str = Query(""), date_from: str = Query(""), date_to: str = Query(""),
                    customer: str = Query(""), agent: str = Query(""), invoice_no: str = Query(""),
                    payment_method: str = Query(""), status: str = Query("all"),
                    page_size: int = Query(50),
                    user: User = Depends(require_role("staff"))):
    from datetime import date
    if not date_from or not date_to:
        today = date.today()
        date_from = date_from or today.replace(day=1).isoformat()
        date_to = date_to or today.isoformat()
    from . import receipt_payment_service as rps
    return _render(request, "ar_receipt.html", user=user, companies=sorted(rps.VALID_COMPANIES),
                   company=company or "CMT",
                   date_from=date_from, date_to=date_to, customer=customer, agent=agent,
                   invoice_no=invoice_no, payment_method=payment_method, status=status,
                   page_size=page_size, active_page="ar_receipt")

@app.get("/statement-of-account")
def statement_of_account_page(request: Request, company: str = Query(""), account: str = Query(""),
                              date_from: str = Query(""), date_to: str = Query(""), currency: str = Query("USD"),
                              per_page: int = Query(100), sort_dir: str = Query("asc"),
                              user: User = Depends(require_login())):
    from datetime import date
    if not date_from or not date_to:
        today = date.today()
        date_from = date_from or today.replace(day=1).isoformat()
        date_to = date_to or today.isoformat()
    from . import receipt_payment_service as rps
    return _render(request, "statement_of_account.html", user=user,
                   companies=sorted(rps.VALID_COMPANIES),
                   company=company or (user.customer_company or "CMT"),
                   account=account, date_from=date_from, date_to=date_to,
                   currency=currency if currency in ("USD", "IDR") else "USD",
                   per_page=max(1, min(per_page, 500)),
                   sort_dir=sort_dir if sort_dir in ("asc", "desc") else "asc",
                   active_page="statement_of_account")


@app.get("/gross-profit")
def gross_profit_page(request: Request, company: str = Query(""), start: str = Query(""), end: str = Query(""), user: User = Depends(require_login())):
    return _render(request, "gross_profit.html", user=user, company=company or (user.customer_company or ""), start=start, end=end, active_page="gross_profit")

@app.get("/api/reports/aging/ar")
def api_aging_ar(user: User = Depends(require_login()), company: str = Query(...), as_of: str = Query(None),
                min_amount: float = None, counterparty: str = None, page: int = 1, per_page: int = 50):
    from datetime import date
    if as_of is None:
        as_of = date.today().isoformat()
    # validate
    try:
        date.fromisoformat(as_of)
    except ValueError:
        raise HTTPException(status_code=422, detail="invalid as_of date")
    if not company:
        raise HTTPException(status_code=422, detail="company required")
    from . import aging_service as ag
    return ag.get_aging_report("ar", as_of, company_filter=company, min_amount=min_amount,
                              counterparty=counterparty, page=page, per_page=per_page)


@app.get("/performance")
def performance_page(request: Request, group_by: str = Query("shipper"), company: str = Query(""), user: User = Depends(require_login())):
    return _render(request, "performance.html", user=user, company=company or (user.customer_company or ""), group_by=group_by, active_page="performance")


@app.get("/api/reports/performance")
def api_performance(group_by: str = Query("shipper"), company: str = Query(""), user: User = Depends(require_login())):
    from . import performance_service as ps
    return ps.get_performance(group_by, company_filter=company)


@app.get("/performance")
def performance_page(request: Request, group_by: str = Query("shipper"), company: str = Query(""), user: User = Depends(require_login())):
    return _render(request, "performance.html", user=user, company=company or (user.customer_company or ""), group_by=group_by, active_page="performance")


@app.get("/api/reports/performance")
def api_performance(group_by: str = Query("shipper"), company: str = Query(""), user: User = Depends(require_login())):
    from . import performance_service as ps
    return ps.get_performance(group_by, company_filter=company)


@app.get("/rate-by-destination")
def rate_by_destination_page(request: Request, search: str = Query(""), sort: str = Query("code"), sort_dir: str = Query("asc"), page: int = Query(1, ge=1), per_page: int = Query(50, ge=1, le=200), user: User = Depends(require_login())):
    return _render(request, "rate_by_destination.html", user=user, search=search, sort=sort, sort_dir=sort_dir, page=page, per_page=per_page, active_page="rate_by_destination")


@app.get("/api/reports/rate-by-destination")
def api_rate_by_destination(page: int = Query(1, ge=1), per_page: int = Query(50, ge=1, le=200), search: str = Query(""), sort: str = Query("code"), sort_dir: str = Query("asc"), user: User = Depends(require_login())):
    import app.queries as q
    # Validate sort field
    valid_sorts = {"code", "city", "quota", "date"}
    if sort not in valid_sorts:
        from fastapi import HTTPException
        raise HTTPException(status_code=400, detail=f"invalid sort; allowed: {', '.join(sorted(valid_sorts))}")
    if sort_dir not in ("asc", "desc"):
        from fastapi import HTTPException
        raise HTTPException(status_code=400, detail="invalid sort_dir; allowed: asc, desc")
    return q.get_rate_by_destination(page=page, per_page=per_page, search=search, sort=sort, sort_dir=sort_dir)


@app.get("/api/reports/aging/ap")
def api_aging_ap(user: User = Depends(require_login()), company: str = Query(...), as_of: str = Query(None),
                min_amount: float = None, counterparty: str = None, page: int = 1, per_page: int = 50):
    from datetime import date
    if as_of is None:
        as_of = date.today().isoformat()
    try:
        date.fromisoformat(as_of)
    except ValueError:
        raise HTTPException(status_code=422, detail="invalid as_of date")
    if not company:
        raise HTTPException(status_code=422, detail="company required")
    from . import aging_service as ag
    return ag.get_aging_report("ap", as_of, company_filter=company, min_amount=min_amount,
                              counterparty=counterparty, page=page, per_page=per_page)

@app.get("/api/reports/ar-receipt")
def api_ar_receipt(company: str = Query(...), date_from: str = Query(""), date_to: str = Query(""),
                   customer: str = Query(""), agent: str = Query(""), invoice_no: str = Query(""),
                   payment_method: str = Query(""), status: str = Query("all"),
                   page: int = Query(1, ge=1), page_size: int = Query(50, ge=1, le=200),
                   sort: str = Query("date"), sort_dir: str = Query("desc"),
                   user: User = Depends(require_role("staff"))):
                       from . import receipt_payment_service as rps
                       from fastapi import HTTPException
                       try:
                           return rps.get_ar_receipt(company, date_from, date_to, customer, agent, invoice_no,
                                                     payment_method, status, page, page_size, sort, sort_dir)
                       except ValueError as e:
                           raise HTTPException(status_code=400, detail={"error": str(e), "allowed": sorted(rps.SORT_FIELDS)})


@app.get("/api/reports/ap-payment")
def api_ap_payment(company: str = Query(...), date_from: str = Query(""), date_to: str = Query(""),
                   vendor: str = Query(""), agent: str = Query(""), invoice_no: str = Query(""),
                   payment_method: str = Query(""), status: str = Query("all"),
                   page: int = Query(1, ge=1), page_size: int = Query(50, ge=1, le=200),
                   sort: str = Query("date"), sort_dir: str = Query("desc"),
                   user: User = Depends(require_role("staff"))):
                       from . import receipt_payment_service as rps
                       from fastapi import HTTPException
                       try:
                           return rps.get_ap_payment(company, date_from, date_to, vendor, agent, invoice_no,
                                                     payment_method, status, page, page_size, sort, sort_dir)
                       except ValueError as e:
                           raise HTTPException(status_code=400, detail={"error": str(e), "allowed": sorted(rps.SORT_FIELDS)})


@app.get("/api/reports/gross-profit")
def api_gross_profit(company: str = Query(...), start: str = Query(None), end: str = Query(None),
                     user: User = Depends(require_login())):
    from . import gross_profit_service as gp
    return gp.get_gross_profit_report(start or "", end or "", company_filter=company)


@app.get("/api/reports/statement-of-account")
def api_statement_of_account(
    account: str = "",
    company: str = Query(""),
    date_from: str = Query(""),
    date_to: str = Query(""),
    currency: str = Query("USD"),
    page: str = "1",
    per_page: str = "100",
    sort_dir: str = Query("asc"),
    user: User = Depends(require_login()),
):
    from . import statement_of_account_service as soa
    from fastapi import HTTPException
    # Default company from user if not provided
    eff_company = company or (user.customer_company or "CMT")
    # Default dates
    if not date_from or not date_to:
        from datetime import date
        today = date.today()
        date_from = date_from or today.replace(day=1).isoformat()
        date_to = date_to or today.isoformat()
    # Validate user access to company
    if user.customer_company and eff_company != user.customer_company:
        raise HTTPException(status_code=403, detail="company mismatch")
    # Validate query parameters manually (spec requires 400, not 422)
    if not account or not account.strip():
        raise HTTPException(status_code=400, detail="account required")
    if not date_from or not date_to:
        raise HTTPException(status_code=400, detail="date_from and date_to required")
    try:
        df = datetime.fromisoformat(date_from).date()
        dt = datetime.fromisoformat(date_to).date()
    except ValueError:
        raise HTTPException(status_code=400, detail="invalid date format")
    if df > dt:
        raise HTTPException(status_code=400, detail="date_from after date_to")
    if (dt - df).days > 366:
        raise HTTPException(status_code=400, detail="date span > 366 days")
    if currency not in ("USD", "IDR"):
        raise HTTPException(status_code=400, detail="invalid currency")
    try:
        page_i = int(page)
        per_page_i = int(per_page)
    except (TypeError, ValueError):
        raise HTTPException(status_code=400, detail="invalid page or per_page")
    if page_i < 1:
        raise HTTPException(status_code=400, detail="invalid page")
    if per_page_i < 1 or per_page_i > 500:
        raise HTTPException(status_code=400, detail="invalid per_page")
    if sort_dir not in ("asc", "desc"):
        raise HTTPException(status_code=400, detail="invalid sort_dir")
    try:
        return soa.get_statement_of_account(
            eff_company, account, date_from, date_to, currency, page_i, per_page_i, sort_dir
        )
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except soa.AccountNotFound as e:
        raise HTTPException(status_code=404, detail=str(e))
    except Exception as e:
        if isinstance(e, HTTPException):
            raise
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/api/sync-status")
def api_sync_status(user: User = Depends(require_login())):
    """Report last sync time (DB file mtime) + staleness vs STALE_AFTER."""
    db_path = Path(DB_PATH)
    if not db_path.exists():
        return {"last_sync": None, "stale": True, "age_seconds": None}

    mtime = datetime.fromtimestamp(db_path.stat().st_mtime, tz=timezone.utc)
    age = (datetime.now(timezone.utc) - mtime).total_seconds()
    stale = age > STALE_AFTER_SECONDS
    return {
        "last_sync": mtime.isoformat(),
        "stale": stale,
        "age_seconds": int(age),
    }


@app.post("/set-lang")
def set_lang(request: Request, lang: str = Form(...)):
    from .i18n import _SUPPORTED
    if lang not in _SUPPORTED:
        lang = "id"
    referer = request.headers.get("referer", "/")
    req_host = urllib.parse.urlparse(str(request.url)).hostname or ""
    ref_host = urllib.parse.urlparse(referer).hostname or ""
    if ref_host and ref_host != req_host:
        resp = RedirectResponse(url="/", status_code=303)
    else:
        resp = RedirectResponse(url=referer, status_code=303)
    resp.set_cookie(
        "cmt_lang", lang,
        max_age=31536000, samesite="lax", secure=session_cookie_secure(),
    )
    return resp


@app.post("/set-db")
def set_db(request: Request, db_name: str = Form(...)):
    """Switch the active company database. Sets a cookie; actual DB
    switching happens in middleware on each request."""
    # Validate the DB exists
    db_file = Path(DATA_DIR) / f"{db_name}.db"
    if not db_file.is_file():
        # Fall back to default
        db_name = Path(DB_PATH).stem
    resp = RedirectResponse(url=request.headers.get("referer", "/"), status_code=303)
    resp.set_cookie(
        "cmt_db", db_name,
        max_age=31536000, samesite="lax", secure=session_cookie_secure(),
    )
    return resp
