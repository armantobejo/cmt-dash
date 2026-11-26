# Security Baseline — CMT Dashboard

**Status**: HARDENED — findings F1–F8 resolved (T002, T010–T015, T045, YAGNI cut #6).
**Strix pentest**: RUN 2026-11-25 against the Laravel build (commit `64cf455`) — **0 Critical, 0 High**; 3 Medium + KG-1 remediated in `01aa2e8`. See `docs/security/sc-001-run-2026-11-25.md`.
**Next action**: SC-001 severity criterion cleared; re-run Strix before live cutover to confirm remediation.

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

- [x] T040 / T046: Strix deep pentest — superseded by the successful authenticated run below. The earlier 2026-11-22 attempt was blocked (LLM relay 402); its `admin:admin` observation predates the default-credential fix and is no longer valid.
- [x] T042: Manual smoke test — **DONE 2026-11-25**: `admin:admin` and `superadmin:superadmin` now REJECTED; provisioned creds accepted; unauth `/api/summary` returns 401. See `docs/security/sc-001-run-2026-11-25.md`.
- [x] T043: Security-baseline refresh — this edit.
- [x] T046 (retry): Strix authenticated pentest — **DONE 2026-11-25 vs `http://127.0.0.1:8091` (commit `64cf455`)**: LLM relay restored (`ai.pro.my.id` via `:8099` proxy); 951 LLM turns, 55 surfaces. **0 Critical, 0 High.** Three Medium reported and independently reproduced. Default credentials now REJECTED. See `docs/security/sc-001-run-2026-11-25.md`; raw run in `laravel/strix_runs/host-docker-internal-8091_24ec/`.
- [x] SC-001 Medium disposition — **FIXED in commit `01aa2e8`**, verified against the live Laravel build on `http://127.0.0.1:8091`:
  - `vuln-0001` IP-keyed login throttle → cross-account lockout (CWE-307) — per-`(lowercase(username), ip)` bucket + IP-wide 60/min backstop in `AppServiceProvider::boot()`. `throttle_poc.py`: 5 failures against distinct usernames followed by a valid login returns 200, not 429.
  - `vuln-0002` open redirect / Host-header injection on `/set-db`, `/set-lang` (CWE-601) — `SetDbController::switchResponse()` now returns `new RedirectResponse('/', 303)`; `Referer: https://evil.example/phish` and `Host: evil.example` both yield `Location: /`.
  - `vuln-0003` unauthenticated cross-site `cmt_db` tenant switch, no CSRF token or role gate (CWE-639) — both routes removed from `validateCsrfTokens(except:)`; `@csrf` present in both switcher forms; no-token POST returns 419. Values remain whitelisted by `CompanyDbManager::isValidName()`; routes stay public for KG-3.
  - KG-1: `/shipments/detail` cross-tenant read — `DashboardController::shipmentDetail()` now applies the same `customerCompany` guard as `invoiceDetail()`. Customer `Trisco` requesting a foreign shipment ref returns 303; own ref returns 200. Recorded as a **parity decision** in `docs/decisions/p5-parity-diff-contract.md` (KG-1 retired from `known_gaps` → `fixed_bugs`), not a security acceptance.
  - Verification: `python sc001_verify.py http://127.0.0.1:8091 '<pw>'` exits 0; `sc001_remediation_check.py` (workspace `t_0e9c77a7`) all six checks PASS; `CompanyDbSwitcherTest` 12/12. Re-run Strix before live cutover to confirm remediation.

