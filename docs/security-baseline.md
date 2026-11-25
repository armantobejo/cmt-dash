# Security Baseline — CMT Dashboard

**Status**: HARDENED — findings F1–F8 resolved (T002, T010–T015, T045, YAGNI cut #6).
**Strix pentest**: DEFERRED by owner (2026-08-10): "no need to pen-test now, it's still in early stage".
**Next action**: Re-run pipeline Stage 6 (strix) when the app reaches feature-complete / pre-launch.

---

## 1. Findings (from manual code review, Stage 1–4)

All findings from the original review are resolved. Resolution recorded per finding.

| # | Severity | Finding | Evidence | Fix task | Status |
|---|---|---|---|---|---|
| F1 | High | Hardcoded seed credentials (`superadmin`/`admin`/`manager`, passwords == usernames), static salts, unsalted SHA-256 | `src/app/auth.py` (pre-hardening) | T010, T011 | **RESOLVED** — SQLite `users` table; PBKDF2-HMAC-SHA256 (200k iterations, per-user random salt); seeding only via `CMT_USERS` env, no in-source defaults |
| F2 | High | Session tokens in an in-memory dict; no expiry; no persistence (restart kills sessions) | `src/app/auth.py` (pre-hardening) | T010, T012 | **RESOLVED** — SQLite `sessions` table with `expires_at` (8h TTL); expired tokens rejected + cleaned up; cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` via `CMT_COOKIE_SECURE=1`) |
| F3 | Medium | `cmt_session` cookie lacked `Secure` flag | `src/app/main.py` | T012 | **RESOLVED** — `session_cookie_secure()` sets the flag when `CMT_COOKIE_SECURE=1` (TLS deploys) |
| F4 | Medium | SQL identifier interpolation in `table_count()` | `src/app/db.py` | T015 | **RESOLVED** — `ALLOWED_TABLES` whitelist rejects unknown names; fuzz test in `tests/test_db_safety.py` |
| F5 | Low | `GET /api/summary` + `GET /api/health` public | `src/app/main.py` | T013 | **RESOLVED** — `/api/summary` now gated `require_login()`; `/api/health` intentionally public (liveness probe, no data) |
| F6 | Low | `tools/extract_schema.py` hardcoded `D:/PROJECTS/...` paths + unused `dbf` dep | `tools/extract_schema.py` | T045 | **RESOLVED** — rewritten on `dbfread`; paths env-driven (`DBF_DIR`/`SCHEMA_OUT`); `dbf` + `pyyaml` deps removed |
| F7 | Info | `cookies.txt` placeholder file (libcurl header only) | repo root | YAGNI cut #6 | **RESOLVED** — deleted |
| F8 | Info | `create_unified_view` duplicated in `queries.py` + `sync_db.py` | both files | T002 | **RESOLVED** — single source of truth in `tools/schema.py`; both sides import it |

## 2. Accepted Risks (owner sign-off 2026-08-11)

| Risk | Rationale | Mitigation |
|---|---|---|
| Public `/api/health` | Liveness probe; no data exposure | Keep public, document |
| No TLS in app | Docker runs plain HTTP; prod expected behind reverse proxy | Set `CMT_COOKIE_SECURE=1` behind TLS |
| `CMT_USERS` env seeding | Plaintext passwords in process env | Standard practice; rotate via env re-deploy |
| Read-only surface | No write path by design (per `IDEA.md`); DBF stays source of truth | No edit endpoints exist |

## 3. Dependency Posture

Pinned in `src/requirements.lock` (transitive closure; regenerate with
`python tools/gen_lock.py > src/requirements.lock`).

Top-level pins: fastapi 0.141.1, uvicorn 0.51.0, jinja2 3.1.6, aiofiles 25.1.0,
python-multipart 0.0.32, httpx 0.28.1, dbfread 2.0.7.
Transitive (22 total): starlette 1.3.1, pydantic 2.13.4, anyio 4.14.2, and 13 more (see the lockfile).

`pip-audit -r src/requirements.txt` (2026-08-18): **No known vulnerabilities found**.
Both images install from the lockfile (`src/Dockerfile`, `tools/Dockerfile`) for
reproducible builds.

## 4. Deferred / Open Work

- [ ] T040 / T046: Strix deep pentest — **attempted 2026-11-22 vs `http://127.0.0.1:8002`; blocked: LLM relay exhausted (402 insufficient credit balance).** Manual re-verification confirms vuln-0001 CRITICAL OPEN (`admin:admin` at `POST /login`). Gate remains BLOCKED. See `docs/security/sc-001-laravel-strix-retry-2026-11-22.md`.
- [x] T042: Manual smoke test — **DONE 2026-11-22**: login probe confirms `admin:admin` accepted on Laravel; `/api/summary` absent on Laravel.
- [x] T043: Final security-baseline refresh — this edit.
