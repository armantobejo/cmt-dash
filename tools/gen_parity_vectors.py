"""Generate cross-app parity vectors by RUNNING the real Python stack.

Purpose: kill circular testing. Every expected value in
laravel/tests/fixtures/parity_vectors.json is produced by executing the
reference Python stack (app.auth constants, real FastAPI validation, real
Starlette cookie headers, stdlib primitives), then written to disk. The
Laravel suite consumes the fixture verbatim and asserts its own outputs
equal it -- PHP never computes an expected value for itself.

Usage:
    python tools/gen_parity_vectors.py            # writes the fixture
    python tools/gen_parity_vectors.py --check    # exit 1 if fixture stale

Any behavioral change in the Python reference must re-run the generator.
"""
from __future__ import annotations

import hashlib
import json
import re
import secrets
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO_ROOT / "src"))

OUT = REPO_ROOT / "laravel" / "tests" / "fixtures" / "parity_vectors.json"


def fastapi_422_body() -> dict:
    """Real FastAPI validation output for a missing username/password."""
    from fastapi import FastAPI, Form
    from fastapi.testclient import TestClient

    probe = FastAPI()

    @probe.post("/login")
    def _login(username: str = Form(...), password: str = Form(...)):  # noqa: ANN202
        return {"ok": True}

    client = TestClient(probe)
    r = client.post("/login", data={})
    assert r.status_code == 422, r.text
    body = r.json()
    # "url" entries are FastAPI-version noise (docs links) -- drop for stability.
    for item in body.get("detail", []):
        item.pop("url", None)
    return {"status": r.status_code, "body": body}


def cookie_headers() -> list[dict]:
    """Real Starlette Set-Cookie output for the two cookies we must mirror."""
    from starlette.responses import Response

    def header(action: str, **kwargs) -> str:
        resp = Response()
        if action == "set":
            resp.set_cookie(**kwargs)
        else:
            resp.delete_cookie(kwargs["key"])
        h = resp.headers["set-cookie"]
        # delete_cookie embeds expires=<now-1s> -- wall-clock noise that would
        # make the fixture differ every run. Keep the attribute, mask the value;
        # the PHP side matches it against an RFC 7231 HTTP-date regex.
        return re.sub(r"expires=[^;]+", "expires=<EXPIRES>", h)

    return [
        {
            "id": "session_cookie_set",
            "kind": "set",
            "name": "cmt_session",
            "value": "tok",
            "python_header": header(
                "set", key="cmt_session", value="tok",
                httponly=True, samesite="lax", secure=False,
            ),
        },
        {
            "id": "session_cookie_secure",
            "kind": "set",
            "name": "cmt_session",
            "value": "tok",
            "python_header": header(
                "set", key="cmt_session", value="tok",
                httponly=True, samesite="lax", secure=True,
            ),
        },
        {
            "id": "db_cookie_set",
            "kind": "set",
            "name": "cmt_db",
            "value": "CME",
            "python_header": header(
                "set", key="cmt_db", value="CME",
                max_age=31536000, samesite="lax", secure=False,
            ),
        },
        {
            "id": "db_cookie_delete",
            "kind": "delete",
            "name": "cmt_session",
            "value": "",
            "python_header": header("delete", key="cmt_session", path="/"),
        },
    ]


def json_vectors() -> list[dict]:
    """JSON produced exactly as FastAPI's JSONResponse produces it.

    FastAPI: json.dumps(..., separators=(",", ":"), ensure_ascii=False).
    These are the bytes the Python API endpoints emit.
    """
    samples = [
        {"id": "ascii", "obj": {"user": "admin", "role": "superadmin", "active_db": "CMT"}},
        {"id": "unicode", "obj": {"msg": "Pembayaran lunas", "name": "PT. Trisco"}},
        {"id": "slash_amp", "obj": {"url": "http://x/?a=1&b=2", "p": "/x/y"}},
        {"id": "floats_common", "obj": {"a": 0.1, "b": 1.0, "c": 1234.5678, "d": 3.14159265358979},
         "note": "Python emits int-valued floats as 1.0; PHP json_encode prints 1. Spellings differ, decoded value identical"},
        {"id": "floats_edge", "obj": {"n": 1e20},
         "note": "Python 1e+20 vs PHP 1.0e+20 exponent spelling; P5 capture normalizer rounds floats to 4dp"},
        {"id": "null_bool", "obj": {"x": None, "y": True, "z": False}},
        {"id": "nested", "obj": {"rows": [{"a": 1}, {"b": [1, 2, 3]}], "total": 2}},
    ]
    out = []
    for s in samples:
        expected = json.dumps(s["obj"], separators=(",", ":"), ensure_ascii=False)
        out.append({
            "id": s["id"],
            "obj": s["obj"],
            "expected": expected,
            **({"note": s["note"]} if "note" in s else {}),
        })
    return out


def money_vectors() -> list[dict]:
    """Render money via the app's REAL filter (import from main.py).

    The template filter is imported as a function, so the generator exercises
    the exact code path the pages render.
    """
    from app.main import _money

    cases = [
        ("usd_basic", 1171.5, "usd", "full"),
        ("usd_round", 1234.0, "usd", "full"),
        ("usd_negative", -1320.0, "usd", "full"),
        ("usd_zero", 0.0, "usd", "full"),
        ("usd_small", 0.05, "usd", "full"),
        ("usd_thousands", 1234567.89, "usd", "full"),
        ("idr_basic", 2825370.4, "idr", "full"),
        ("idr_negative", -2825370.4, "idr", "full"),
        ("idr_zero", 0.0, "idr", "full"),
        ("idr_fraction_dropped", 1.5, "idr", "full"),
        ("usd_abbr_k", 1200.0, "usd", "abbr"),
        ("usd_abbr_m", 1234567.0, "usd", "abbr"),
        ("usd_abbr_thousand_edge", 999.99, "usd", "abbr"),
        ("idr_abbr_m", 1250000.0, "idr", "abbr"),
        ("idr_abbr_m_strip", 1200000.0, "idr", "abbr"),
        ("idr_abbr_k", 1200.0, "idr", "abbr"),
        ("idr_abbr_small", 999.0, "idr", "abbr"),
        ("usd_abbr_negative", -1500.0, "usd", "abbr"),
        ("none_value", None, "usd", "full"),
    ]
    return [
        {"id": cid, "value": v, "currency": c, "fmt": f, "expected": _money(v, c, f)}
        for cid, v, c, f in cases
    ]


def i18n_vectors() -> list[dict]:
    """Translations via the app's REAL lookup (i18n.t), incl. identity fallback."""
    from app.i18n import t as real_t

    keys = [
        "Dashboard", "Ringkasan", "Overview of system data", "Total Shipments",
        "Total Unpaid", "Unpaid Shipments", "No unpaid shipments found.",
        "Shipments", "All shipment data (Import & Export)", "All Types",
        "Invoice Un-issued", "Invoice Issued & Unpaid", "No shipments found.",
        "Invoices", "results", "Ref.", "No. Invoice", "Amount (USD)",
        "Paid (IDR)", "Status", "Unpaid", "No invoices.",
        "Data synced from FoxPro system", "Previous", "Next", "Search",
        "Contact admin if you don't have an account.",
    ]
    return [
        {
            "key": k,
            "id": real_t("id", k),
            "en": real_t("en", k),
            "zh": real_t("zh", k),
        }
        for k in keys
    ]


def capitalize_vectors() -> list[dict]:
    for s in ["boss", "staff1", "ADMIN", "über", "žluťoučký", "x y", ""]:
        yield {"input": s, "expected": s.capitalize()}


def main() -> int:
    from app.auth import PBKDF2_ITERATIONS, ROLE_LEVEL, SESSION_TTL

    iters = int(PBKDF2_ITERATIONS)

    def py_hash(pw: str, salt: str) -> str:
        # auth.py::_hash: pw.encode() (utf-8), salt.encode(), dklen=32 bytes.
        return hashlib.pbkdf2_hmac("sha256", pw.encode(), salt.encode(), iters, dklen=32).hex()

    pbkdf2_cases = [
        {"id": "ascii_basic", "pw": "pw", "salt": "4f0a"},
        {"id": "unicode_pw", "pw": "pässwörd", "salt": "0a0b0c0d"},
        {"id": "long_pw", "pw": "x" * 200, "salt": "00ff"},
        {"id": "empty_pw", "pw": "", "salt": "deadbeef"},
        {"id": "salt_with_space", "pw": "S3cret!", "salt": "12 34 56 78"},
    ]
    pbkdf2_vec = [
        {
            "id": c["id"], "pw": c["pw"], "salt": c["salt"],
            "pw_bytes_hex": c["pw"].encode().hex(),
            "expected_hash": py_hash(c["pw"], c["salt"]),
        }
        for c in pbkdf2_cases
    ]

    token = secrets.token_urlsafe(32)
    now_iso = datetime.now(timezone.utc).isoformat()
    assert re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?\+00:00", now_iso)

    iso_cases = [
        {"id": "micros", "dt": datetime(2026, 9, 22, 13, 45, 12, 123456, tzinfo=timezone.utc)},
        {"id": "zero_micros", "dt": datetime(2026, 1, 1, 0, 0, 0, 0, tzinfo=timezone.utc),
         "note": "Python omits '.000000' when microsecond==0; PHP .u always prints it. Only reachable in ~1e-6 of writes; string ordering comparisons are unaffected"},
        {"id": "end_of_year", "dt": datetime(1999, 12, 31, 23, 59, 59, 999999, tzinfo=timezone.utc)},
        {"id": "far_future", "dt": datetime(2100, 2, 28, 12, 0, 0, 1, tzinfo=timezone.utc)},
    ]
    iso_vec = [
        {
            "id": c["id"], "utc_iso": c["dt"].isoformat(), "expected": c["dt"].isoformat(),
            **({"note": c["note"]} if "note" in c else {}),
        }
        for c in iso_cases
    ]

    sqlite_now = sqlite3.connect(":memory:").execute("select datetime('now')").fetchone()[0]
    assert re.fullmatch(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}", sqlite_now)

    fixture = {
        "_meta": {
            "generated_by": "tools/gen_parity_vectors.py (runs the real Python stack)",
            "python": sys.version.split()[0],
            "regenerate_with": ["python", "tools/gen_parity_vectors.py"],
            "note": (
                "Expected values are produced by executing the Python reference "
                "(app.auth, FastAPI, Starlette). The Laravel suite asserts equality "
                "with these values; PHP never derives an expected value from itself."
            ),
        },
        "constants": {
            "pbkdf2_iterations": iters,
            "pbkdf2_dklen_bytes": 32,
            "session_ttl_hours": SESSION_TTL.total_seconds() / 3600,
            "role_levels": dict(ROLE_LEVEL),
        },
        "pbkdf2": pbkdf2_vec,
        "tokens": {
            "urlsafe32": {
                "length": len(token),
                "php_regex": "^[A-Za-z0-9_\\-]{43}$",
                "note": "secrets.token_urlsafe(32) -> 43 url-safe base64 chars, no padding",
            },
        },
        "timestamps": {
            "iso_vectors": iso_vec,
            "isoformat_shape_regex": "^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(\\.\\d+)?\\+00:00$",
            "sqlite_datetime_now_regex": "^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$",
        },
        "capitalize": list(capitalize_vectors()),
        "money": money_vectors(),
        "i18n": i18n_vectors(),
        "json_dumps": json_vectors(),
        "cookies": cookie_headers(),
        "validation_422": fastapi_422_body(),
    }

    OUT.parent.mkdir(parents=True, exist_ok=True)
    text = json.dumps(fixture, indent=2, ensure_ascii=False, sort_keys=False)
    if "--check" in sys.argv:
        current = OUT.read_text(encoding="utf-8") if OUT.exists() else ""
        if current != text + "\n":
            print(f"STALE: {OUT} does not match regenerated output; re-run the generator.")
            return 1
        print(f"OK: {OUT} is up to date.")
        return 0
    OUT.write_text(text + "\n", encoding="utf-8")
    print(f"Wrote {OUT} ({len(text)} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
