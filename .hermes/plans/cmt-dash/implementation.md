# CMT Dashboard Hardening Implementation Plan

> **For Hermes:** This is a planning artifact only. NO code is to be written until the owner explicitly signs off at Stage 8. When approved, dispatch fresh subagents per task via `subagent-driven-development`.

**Goal:** Harden the existing CMT dashboard (FastAPI + SQLite over legacy DBF) into a secure, observable, on-demand-tested application. Three user stories (US1 security, US2 onboarding, US3 observability).

**Architecture:** Keep FastAPI + Jinja2 + CoreUI stack. Replace in-memory auth with SQLite-backed store (`users` + `sessions` tables). Add ASGI middleware for structured JSON logging. Extract `UnifiedShipment` view SQL into one shared module. PBKDF2-HMAC-SHA256 hashing per user. Cookie `Secure` flag opt-in via env.

**Tech Stack:** Python 3.11, FastAPI 0.115+, uvicorn, Jinja2, SQLite (stdlib), pytest + httpx, `hashlib` (PBKDF2), `secrets` (tokens), `logging` (JSON formatter), `pip-audit` for dep scan. No new third-party deps unless explicitly required.

**Reference Artifacts (Source of Truth):**
- `docs/srs.md` — functional & non-functional requirements
- `docs/information_architecture.md` — route inventory + RBAC matrix
- `docs/design_system.md` — UI tokens (CoreUI dark)
- `docs/data_model.md` — SQLite tables (incl. new `users`/`sessions`)
- `docs/user_flows/uc-main.md` — login & RBAC-filtered data flows
- `docs/system_logics/uc-main.md` — auth + sync + logging logic
- `docs/yagni-cuts.md` — YAGNI cut decisions (recorded; not yet applied to code)
- `docs/security-baseline.md` — known findings + deferred Strix re-scan

---

## Phase 0 — Pre-flight

### Task 0.1: Confirm toolchain
- **Files:** none
- **Verify:** `docker --version`, `python --version`, `pip-audit --version || pip install pip-audit`. Stash output for later stages.

### Task 0.2: Establish git baseline
- **Files:** none
- **Action:** Confirm `git init` already exists (`.code-review-graph/` exists). Create branch `feat/system-hardening` from current HEAD. `git status` clean.
- **Verify:** `git branch --show-current` → `feat/system-hardening`.

---

## Phase 1 — Foundation (TDD)

### Task T001 [F]: Test harness + smoke test
- **Files:** Create `tests/conftest.py`, `tests/test_smoke.py`
- **Step 1 — write failing test:** `test_get_login_returns_200` — uses `fastapi.testclient.TestClient` against `app.main:app`. Expects 200 + HTML containing `cmt_session` form action.
- **Step 2 — run:** `cd src && python -m pytest ../tests/test_smoke.py -v` → expect IMPORT ERROR (pytest not installed).
- **Step 3 — install:** create `src/requirements-dev.txt` with `pytest`, `httpx`. Install in venv.
- **Step 4 — re-run:** expect FAIL ("test client not configured").
- **Step 5 — minimal impl:** in `tests/conftest.py`, set env `DATABASE_URL=tests/_tmp.db`, instantiate `TestClient(app)` fixture.
- **Step 6 — re-run:** expect PASS.
- **Commit:** `test: add smoke test harness + TestClient fixture`.

### Task T002 [F]: Extract `UnifiedShipment` view to shared module
- **Files:** Create `tools/schema.py`. Modify `tools/sync_db.py` and `src/app/queries.py` to import from it.
- **Step 1 — write failing test:** `tests/test_schema.py::test_view_sql_is_constant` — calls `schema.create_unified_view(conn)` on in-memory sqlite, verifies view exists and column count matches.
- **Step 2 — run:** expect FAIL (module missing).
- **Step 3 — impl:** in `tools/schema.py`, move the function (verbatim) from `queries.py`; both files call `from schema import create_unified_view`.
- **Step 4 — re-run:** expect PASS.
- **Verify:** `grep -r "CREATE VIEW" src/ tools/` → only `tools/schema.py`.
- **Commit:** `refactor: unify UnifiedShipment view in shared module`.

### Task T003 [F]: Structured logging config
- **Files:** Create `src/app/logging_config.py`. Modify `src/app/main.py` to call `dictConfig`.
- **Step 1 — write failing test:** `tests/test_logging.py::test_log_emits_json` — configures logging via `app.logging_config.configure()`, captures `stdout` via caplog, asserts log record JSON-serializable with required fields.
- **Step 2 — run:** expect FAIL.
- **Step 3 — impl:** JSON formatter with fields `ts`, `level`, `logger`, `msg`, `request_id?`, `event?`. Use `json.dumps(extra)` in formatter.
- **Step 4 — re-run:** expect PASS.
- **Commit:** `feat(logging): JSON formatter via logging.dictConfig`.

### Task T004 [F]: `pip-audit` baseline + ignore policy
- **Files:** Modify `src/requirements.txt`.
- **Step 1 — run:** `pip-audit -r src/requirements.txt` — record JSON output to `docs/security-baseline.md` Appendix A.
- **Step 2 — decision:** for each Critical/High finding, either (a) bump pin to fixed version + re-test, or (b) document accepted-risk rationale in `security-baseline.md` § Accepted Risks. Owner approval required.
- **Step 3 — re-run:** must show no Critical/High unaddressed.
- **Commit:** `chore(deps): pip-audit remediation` (or `docs(security): record accepted risks`).

### Task T005 [P] [F]: `.gitignore`
- **Files:** Create `.gitignore`.
- **Step 1 — write:** ignore `__pycache__/`, `*.pyc`, `data/*.db*`, `.env`, `.hermes/`, `cookies.txt`, `.pytest_cache/`, `.crg/`, `*.egg-info/`, `.venv/`, `node_modules/` (if any).
- **Commit:** `chore: add .gitignore`.

---

## Phase 2 — US1 Security

### Task T010 [US1]: SQLite-backed `users` table + `UserStore`
- **Files:** Modify `src/app/db.py` (add table-creation), `src/app/auth.py` (replace `UserStore`).
- **Step 1 — failing test:** `tests/test_auth_persistence.py::test_user_inserted_in_db_then_authenticated` — insert row directly, call `STORE.authenticate(u, p)` → token.
- **Step 2 — impl:** add `CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, display_name TEXT, role TEXT NOT NULL, pw_hash TEXT NOT NULL, salt TEXT NOT NULL, customer_company TEXT)`. `UserStore.__init__` queries `users` table instead of taking `dict`. `_seed_users()` inserts from `CMT_USERS` env (preserves format) — random salt per user.
- **Step 3 — re-run:** expect PASS.
- **Commit:** `feat(auth): persist users in SQLite, replace in-memory store`.

### Task T011 [US1]: PBKDF2 hashing + remove static salts
- **Files:** Modify `src/app/auth.py`.
- **Step 1 — failing test:** `tests/test_auth_hash.py::test_pbkdf2_password_verifies` — insert user with `pbkdf2_hmac("sha256", pw.encode(), salt.encode(), 200_000).hex()`, authenticate, assert success; assert wrong password rejected.
- **Step 2 — impl:** replace `_hash(pw, salt)` body with `hashlib.pbkdf2_hmac("sha256", pw.encode(), salt.encode(), 200_000, dklen=32).hex()`. `secrets.token_hex(16)` per-user salt. Remove `_DEFAULT_USERS` dict (no plaintext seeds).
- **Step 3 — re-run:** expect PASS.
- **Verify:** `grep -n "_hash\|sa_salt\|ad_salt" src/app/auth.py` → 0 matches for old salts.
- **Commit:** `feat(auth): PBKDF2-HMAC-SHA256, drop hardcoded salts`.

### Task T012 [US1]: Session expiry + secure cookie flag
- **Files:** Modify `src/app/auth.py`, `src/app/main.py`.
- **Step 1 — failing test:** `tests/test_auth_session.py::test_expired_session_rejected` — insert session row with `expires_at` in past, call `get_current_user(token=...)` → `User(authenticated=False)`.
- **Step 2 — impl:** add `sessions(token TEXT PRIMARY KEY, user_id INTEGER, created_at TEXT, expires_at TEXT)`. Session lifetime 8h. `get_current_user` joins users + sessions, checks expiry, deletes expired rows. `set_cookie` adds `secure=True` when `CMT_COOKIE_SECURE=1` env. Same for `cmt_lang`.
- **Step 3 — re-run:** expect PASS.
- **Verify:** curl with `CMT_COOKIE_SECURE=1` → `Set-Cookie: cmt_session=...; Secure; HttpOnly`.
- **Commit:** `feat(auth): persistent sessions with expiry + secure-flag toggle`.

### Task T013 [US1]: RBAC completeness audit
- **Files:** Modify `src/app/main.py`.
- **Step 1 — failing test:** `tests/test_rbac.py::test_api_summary_requires_login` — GET `/api/summary` without cookie → redirect to `/login` (or 401). Current code: returns 200.
- **Step 2 — impl:** gate `/api/summary` with `Depends(require_login())`. `/api/health` stays public (documented). All other routes already gated.
- **Step 3 — re-run:** expect PASS.
- **Verify:** walk `main.py`, every route either has `Depends(require_login)` or a `# public-endpoint:` comment with rationale.
- **Commit:** `feat(rbac): gate /api/summary behind require_login`.

### Task T014 [US1]: Dependency upgrades (was `pip-audit` step)
- *Merged with T004.*

### Task T015 [P] [US1]: SQL identifier whitelist for `table_count`
- **Files:** Modify `src/app/db.py`.
- **Step 1 — failing test:** `tests/test_db_safety.py::test_table_count_rejects_unknown_table` — call with `table="users; DROP TABLE users;--"` → `ValueError`.
- **Step 2 — impl:** `table_count(table)` checks `table in ALLOWED_TABLES` (frozenset of strings from app queries + new auth tables).
- **Step 3 — re-run:** expect PASS.
- **Commit:** `fix(db): whitelist table_count to prevent SQL injection foot-gun`.

---

## Phase 3 — US2 Onboarding

### Task T020 [US2]: `README.md`
- **Files:** Create `README.md`.
- **Content:** Project intro (link `IDEA.md`), quickstart (docker + local), architecture (ASCII diagram of DBF→sync→SQLite→FastAPI→Browser), env vars table (`DATABASE_URL`, `DBF_DIR`, `CMT_USERS`, `CMT_COOKIE_SECURE`, `CMT_LANG`), role matrix (table), testing instructions.
- **Commit:** `docs: add README.md`.

### Task T021 [US2]: Docker-compose polish
- **Files:** Modify `docker-compose.yml`.
- **Add:** `healthcheck` on `dashboard` (curl `/api/health`), env `CMT_COOKIE_SECURE`, named volume `cmt_data`, `restart: unless-stopped` consistency.
- **Commit:** `chore(docker): healthcheck + named volume`.

### Task T022 [US2]: Makefile
- **Files:** Create `Makefile`.
- **Targets:** `up` (docker compose up), `sync` (run tools/sync_db.py), `test` (pytest), `lint` (ruff if present, else skip), `audit` (pip-audit).
- **Commit:** `chore: add Makefile for common dev tasks`.

### Task T023 [P] [US2]: CONTRIBUTING.md
- **Files:** Create `docs/CONTRIBUTING.md`.
- **Content:** how to add a query (extend `queries.py` + `Allowed_TABLES`), how to add a translation (edit `src/app/translations/{lang}.json` for all 3 langs), how to add a route (RBAC decision matrix in `info_architecture.md` first).
- **Commit:** `docs: CONTRIBUTING.md`.

---

## Phase 4 — US3 Observability

### Task T030 [US3]: Request logging middleware
- **Files:** Create `src/app/middleware.py`, modify `src/app/main.py`.
- **Step 1 — failing test:** `tests/test_logging_middleware.py::test_request_logged_as_json` — issue GET /, capture stdout, assert one JSON line containing `event=request`, `method=GET`, `path=/`, `status=200`, `duration_ms` int.
- **Step 2 — impl:** ASGI middleware: on receive, generate `request_id` (secrets.token_hex(8)), store in scope. On send, calc duration, log JSON via `logging.getLogger("access")`.
- **Step 3 — re-run:** expect PASS.
- **Commit:** `feat(logging): ASGI middleware logs every request as JSON`.

### Task T031 [US3]: Auth event logging
- **Files:** Modify `src/app/auth.py`, `src/app/main.py`.
- **Step 1 — failing test:** `tests/test_auth_logging.py::test_login_failure_logs_event` — POST /login with bad creds, capture stdout, assert `event=user_login`, `outcome=fail`.
- **Step 2 — impl:** in `STORE.authenticate`, on success/fail log via `logging.getLogger("auth")`. In `logout`, log `user_logout`. In `get_current_user` on expired session, log `session_expired`.
- **Step 3 — re-run:** expect PASS.
- **Commit:** `feat(logging): auth event JSON logs (login/logout/session_expired)`.

### Task T032 [US3]: Sync JSON logs
- **Files:** Modify `tools/sync_db.py`.
- **Step 1 — failing test:** `tests/test_sync_logging.py::test_sync_emits_json_events` — set `SYNC_LOG_JSON=1`, run sync on empty DBF dir, capture stdout, assert `sync_start` and `sync_complete` events.
- **Step 2 — impl:** if env `SYNC_LOG_JSON=1`, replace `print()` with `json.dumps({"event": ..., ...})`. Default keeps human-readable for operators.
- **Step 3 — re-run:** expect PASS.
- **Commit:** `feat(sync): opt-in JSON logging for sync_db`.

### Task T033 [US3]: Sync-status endpoint
- **Files:** Modify `src/app/main.py`.
- **Step 1 — failing test:** `tests/test_sync_status.py::test_sync_status_returns_mtime` — GET /api/sync-status authenticated, returns `{ok: bool, last_sync: iso8601_str, stale: bool}`.
- **Step 2 — impl:** read `cmt.db` mtime, compute stale as > 24h, return JSON.
- **Step 3 — re-run:** expect PASS.
- **Commit:** `feat(api): /api/sync-status endpoint for future polling clients`.

---

## Phase 5 — Hardening + Verification

### Task T040 [All]: Strix re-scan (DEFERRED per owner 2026-08-10)
- **Status:** NO-OP until owner re-enables.
- **Action when re-enabled:** `strix -m deep -t .` → write results into `docs/security-baseline.md` § Deferred Work → close all Critical/High via sub-tasks → re-scan → close.

### Task T041 [All]: Full test pass
- **Action:** `cd src && python -m pytest ../tests -v` → expect all green. CI command.

### Task T042 [All]: Manual smoke
- **Action:** docker compose up; login each role; hit each page; trigger `/sync` as management; verify JSON logs in `docker compose logs dashboard`.

### Task T043 [All]: Finalize security-baseline.md
- **Action:** append final test/scan summary; record accepted risks; close-out checklist.

---

## Pre-Execution Gate (Stage 8 — owner sign-off)

The owner MUST explicitly approve before Task T001 starts. Items to confirm:

1. Spec/plan/tasks artifacts reviewed — no rewrites needed?
2. YAGNI cuts applied to SoT documents look right?
3. Security-baseline deferred work accepted?
4. Default user seeding via `CMT_USERS` env (no in-source defaults) acceptable for greenfield deploy?
5. `/api/summary` gating decision (now requires login — is that a breaking change for current dashboards?)

On approval, dispatch tasks T001–T043 in order via `subagent-driven-development`.

---

## Verification (final)

- [ ] `pytest` 100% green
- [ ] Strix (when re-enabled) 0 Critical/High
- [ ] `README.md` matches reality
- [ ] `security-baseline.md` current
- [ ] docker compose up + manual smoke per T042 passes
- [ ] All tasks T001–T043 (except T040) committed