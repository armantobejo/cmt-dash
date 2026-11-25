# Tasks: CMT Dashboard Hardening

**Input**: Design documents from `/specs/cmt-dashboard/`
**Prerequisites**: plan.md (required), spec.md (required for user stories)
**Tests**: pytest suite built as part of T001–T003; every US task has a matching test.
**Organization**: Tasks grouped by user story for independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`
- **[P]**: parallel-safe
- **[Story]**: US1 = security, US2 = onboarding, US3 = observability, F = foundation

---

## Phase 1: Foundation (Blocking Prerequisites)

**Purpose**: Test harness + shared modules that every US depends on

- [ ] T001 [F] Add `tests/` dir with pytest + httpx TestClient fixture; smoke test: GET /login returns 200. Ref: `docs/srs.md`
- [ ] T002 [F] Extract `create_unified_view` from `queries.py` + `tools/sync_db.py` into single shared module `tools/schema.py`; both import it; unit test view SQL builds. Ref: `docs/data_model.md`
- [ ] T003 [F] Add `src/app/logging_config.py` (JSON formatter via `logging.config.dictConfig`). Ref: `docs/system_logics/uc-logging.md`
- [ ] T004 [F] Add `pytest` + `httpx` to `src/requirements-dev.txt`; wire optional `pip-audit` step in Makefile or docs. Ref: `docs/srs.md`
- [ ] T005 [P] [F] Add `.gitignore` (data/, *.pyc, __pycache__, .db-sync, .hermes/, .crg/ if desired). Ref: `docs/srs.md`

## Phase 2: US1 Security Hardening

**Purpose**: Zero Critical/High findings; persistent auth; no hardcoded secrets

- [ ] T010 [US1] Replace in-memory `UserStore` with SQLite-backed store: `users` + `sessions` tables created in `db.py` startup; `UserStore.authenticate` reads users table. Ref: `docs/data_model.md`, `docs/srs.md`
- [ ] T011 [US1] Replace static-salt SHA-256 with `hashlib.pbkdf2_hmac('sha256', pw, salt, 200_000)`; store salt per-user; seed users via env `CMT_USERS` only, no hardcoded passwords. Ref: `docs/srs.md`
- [ ] T012 [US1] Session expiry: `expires_at` on sessions; `get_current_user` rejects expired tokens; cookie `secure` flag via `CMT_COOKIE_SECURE` env (default off for local, on in docker-compose behind TLS comment). Ref: `docs/srs.md`
- [ ] T013 [US1] RBAC audit: walk every route in `main.py`; ensure all data routes use `require_login()`; `/sync` remains `require_role("management")`; document public endpoints (`/login`, `/api/health`, `/api/summary`, `/static`) in `docs/security-baseline.md` with risk-acceptance. Ref: `docs/information_architecture.md`
- [ ] T014 [US1] Dependency scan: `pip-audit` on `src/requirements.txt`; upgrade any vulnerable pins (e.g., fastapi, starlette, uvicorn) — bump versions, re-run tests. Ref: `docs/security-baseline.md`
- [ ] T015 [P] [US1] Prevent SQL identifier interpolation: `table_count()` — whitelist or parameterize; add simple fuzz test. Ref: `docs/data_model.md`

## Phase 3: US2 Developer Onboarding

**Purpose**: New dev up in 2 commands; docs for everything

- [ ] T020 [US2] Write `README.md`: architecture diagram (ASCII), setup (docker + local), env vars table, sync service explanation, role matrix. Ref: `docs/information_architecture.md`, `docs/srs.md`
- [ ] T021 [US2] Polish `docker-compose.yml`: add `CMT_COOKIE_SECURE` env passthrough, healthcheck for `dashboard`, named volume `data`. Ref: `docs/srs.md`
- [ ] T022 [US2] Add `Makefile` (or documented commands): `make up`, `make sync`, `make test`, `make lint`, `make audit`. Ref: `docs/srs.md`
- [ ] T023 [P] [US2] Add `docs/CONTRIBUTING.md` (short): how to add a query, add a translation, add a route. Ref: `docs/information_architecture.md`

## Phase 4: US3 Observability

**Purpose**: Structured logs for access, auth, sync

- [ ] T030 [US3] ASGI middleware: log each request (method, path, status, duration_ms, username, request_id); attach request_id to template context. Ref: `docs/system_logics/uc-logging.md`
- [ ] T031 [US3] Auth events: `user_login` success/fail (with username, IP, UA), `user_logout`, `session_expired`. Ref: `docs/srs.md`
- [ ] T032 [US3] Sync logging: `tools/sync_db.py` emits structured events — `sync_start`, `table_synced {table, rows, ms}`, `sync_fail {table, error}`, `sync_complete {total_rows, ms}` — via `SYNC_LOG_JSON=1` env. Ref: `docs/system_logics/uc-sync.md`
- [ ] T033 [US3] Dashboard footer: show last sync time from `cmt.db` mtime (already exists) — keep. Expose `GET /api/sync-status` returning `{ok, last_sync, stale: bool}` gated `require_login()` so future UI can poll. (YAGNI cut #8: no client-side polling until explicitly requested.) Ref: `docs/information_architecture.md`

## Phase 5: Hardening + Verification

**Purpose**: Prove closure; ship quality

- [ ] T040 [All] Run `strix -m deep -t .` on hardened code; triage findings; fix all Critical/High; re-run until clean. Ref: `docs/security-baseline.md`
- [ ] T041 [All] Full pytest pass: `python -m pytest tests/ -q` — 100% green. Ref: all SoT artifacts
- [ ] T042 [All] Manual smoke: docker compose up, login as each role, hit each page, trigger `/sync` as management, verify logs JSON. Ref: `docs/user_flows/uc-*.md`
- [ ] T043 [All] Update `docs/security-baseline.md` with final scan summary + accepted risks. Ref: `specs/cmt-dashboard/spec.md`

---

**Definition of Done**: all T0xx checked; pytest green; Strix 0 Critical/High; README accurate; security-baseline.md current.

---

## Phase 6: Convergence

Appended by `/speckit-converge` on 2026-08-12. These tasks cover the gap between the original task list and the actual codebase state after implementation. The implementation covered most tasks (T001–T033) during Stage 7 work; the following remaining items close the loop.

**Findings Summary**: 7 findings (1 HIGH, 3 MEDIUM, 3 LOW) — 3 missing, 2 partial, 1 unrequested, 1 contradicts-docs.

### CRITICAL (Constitution violations — none)

*(Constitution is an unfilled template; constitution checks skipped.)*

### HIGH

- [x] T044 Run `pip-audit` on `src/requirements.txt` + tool deps; fix any Critical/High findings; upgrade stale pins (fastapi 0.115.6, uvicorn 0.32.1) to latest stable; re-run tests. Ref: FR-008, SC-001, T014 (missing — audit never executed)
- [x] T045 Rewrite `tools/extract_schema.py` to use `dbfread` (already a dependency) instead of the `dbf` library + hand-rolled regex (`parse_field`, `TYPE_MAP`); make paths env-driven (`DBF_DIR`, output path from env); remove `dbf` and `pyyaml` from tool deps. Ref: YAGNI cut #4 (F5 — unrequested `dbf` lib in codebase)

### MEDIUM

- [ ] T046 Install `strix` CLI; run `strix -m deep -t .` on the hardened codebase; record results in `docs/security-baseline.md`. Ref: T040 (missing — scan tool not installed)
- [x] T047 Add `.dockerignore` to `src/Dockerfile` build context; ensure `CMT_COOKIE_SECURE` env passthrough in docker-compose is documented for local-dev vs TLS deploy. Ref: T021 (partial — healthcheck present, no .dockerignore)
- [x] T048 Update `docs/security-baseline.md` findings table (F1–F2) and Accepted Risks to reflect the **current** hardened state: auth is now SQLite+PBKDF2 (not in-memory+SHA256), sessions have expiry, RBAC is complete, `table_count` uses a whitelist. Ref: T043 (partial — security-baseline.md still describes pre-hardened code)

### LOW

- [x] T049 Generate a pinned `requirements.lock` (via `pip freeze` or `pip-tools`) and update `src/Dockerfile` + `tools/Dockerfile` to use it for reproducible image builds. Ref: T004 (partial — no lockfile exists)
- [x] T050 Update `docs/security-baseline.md` Section 3 (Dependency Posture) with actual `pip-audit` results after T044, replacing the "NOT yet run" note. Ref: T043 (contradicts-docs — section says not run, T044 will fix)