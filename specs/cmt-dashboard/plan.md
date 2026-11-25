# Implementation Plan: CMT Dashboard Hardening

**Branch**: `feat/system-hardening` | **Date**: 2026-08-10 | **Spec**: `specs/cmt-dashboard/spec.md`

**Input**: Feature specification from `/specs/cmt-dashboard/spec.md`

## Summary

Harden the existing FastAPI + SQLite dashboard for the CMT (cargo management & transport) business. The app is pre-built: 5-tier RBAC (superadmin > admin > management > staff > customer), DBF→SQLite sync via `tools/sync_db.py`, Jinja2 templates + CoreUI, i18n (id/en/zh). Goal: zero Critical/High findings from Strix, persistent auth store, no hardcoded secrets, structured logging, idempotent sync, full RBAC coverage, developer onboarding docs.

## Technical Context

**Language/Version**: Python 3.11 (container), local python 3.11/3.13

**Primary Dependencies**: fastapi==0.115.6, uvicorn==0.32.1, jinja2==3.1.4, python-multipart==0.0.20, httpx==0.28.1, dbfread==2.0.7 (see `src/requirements.txt`)

**Storage**: SQLite at `data/cmt.db` (env `DATABASE_URL`); source of truth is legacy FoxPro DBF files synced by `tools/sync_db.py`

**Testing**: none currently — must add pytest + httpx TestClient

**Target Platform**: Docker (docker-compose: `dashboard` + `sync` services), port 8000

**Project Type**: web-service (server-rendered Jinja2 + JSON API endpoints)

**Performance Goals**: dashboard pages <500ms p95; sync of ~50 DBF tables within 5-min timeout

**Constraints**: no write features (read-only dashboard); DBF files are read-only input; must keep docker-compose two-service topology; all 5 roles must keep working

**Scale/Scope**: ~50 DBF tables → SQLite; 5 user roles; ~10 routes; single docker host

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- GATE 1 — Incremental delivery: work split per user story (US1 security, US2 onboarding, US3 observability), each independently testable.
- GATE 2 — Verified, not vibes: every change backed by pytest or curl-level verification; Strix re-run proves security closure.
- GATE 3 — Simplicity first: no new frameworks; SQLite + stdlib `hashlib`/`hmac`/`logging` preferred; no ORM, no caching layer.

## Project Structure

### Documentation (this feature)

```text
specs/cmt-dashboard/
├── spec.md              # Stage 2 output (user stories, FRs, success criteria)
├── plan.md              # This file
└── tasks.md             # Stage 2 output (task list)
docs/
├── srs.md               # SoT#1
├── information_architecture.md  # SoT#2
├── design_system.md     # SoT#3
├── user_flows/uc-*.md   # SoT#4
├── data_model.md        # SoT#6
├── system_logics/uc-*.md # SoT#7
├── yagni-cuts.md        # Stage 4
└── security-baseline.md # Stage 6
```

### Source Code (repository root)

```text
src/app/          # FastAPI app (auth.py, db.py, queries.py, main.py, i18n.py, sync.py)
tools/            # sync_db.py, extract_schema.py, Dockerfile
data/             # cmt.db (mounted volume)
DBF/              # legacy source files (read-only mount)
tests/            # NEW: pytest suite
```

## Phase 0: Research

- Read `src/app/auth.py`, `src/app/main.py`, `src/app/db.py`, `src/app/queries.py`, `src/app/i18n.py`, `tools/sync_db.py` — DONE (already analyzed in Stage 1/2).
- Key findings:
  - `auth.py`: in-memory `UserStore`, SHA-256 with static salts, hardcoded seed users (`superadmin`/`admin`/`manager`/`trisco`), session tokens in a plain dict, no expiry.
  - `main.py`: `require_login()` on all app routes; `/sync` gated `require_role("management")`; `/api/summary` and `/api/health` unauthenticated (intentional? must decide); cookie `cmt_session` httponly+samesite=lax but no `secure` flag.
  - `db.py`: plain SQL, WAL mode; `table_count` interpolates table name into SQL (only called with constants today).
  - `queries.py`: `create_unified_view` duplicated verbatim in `tools/sync_db.py` — single-source-of-truth violation.
  - `sync_db.py`: full wipe + rebuild, atomic `os.replace`, verification step; but creates view via duplicated code; no logging; SQLite temp path `.db-sync`.
  - `i18n.py`: fine; cookie `cmt_lang` no secure flag.
  - No tests, no README, no `.gitignore` for `data/`, `*.pyc` etc.

## Phase 1: Design

### 1. Auth persistence (FR-001, FR-002)

- Add tables `users` and `sessions` to SQLite (created on app startup by `db.py`).
- `UserStore` reads users from `users` table; seeded from env `CMT_USERS` (format unchanged) or a seed script, with `secrets.token_hex` salts and per-user password hashing via `hashlib.pbkdf2_hmac('sha256', ...)`.
- Session tokens stored in `sessions` table with `created_at` + `expires_at`; cookie gets `secure=True` when behind TLS (env `CMT_COOKIE_SECURE`), keep httponly+samesite=lax.
- `/api/summary` + `/api/health` decision: keep public but document in security-baseline.md as accepted risk; `require_login()` for everything else.

### 2. Sync idempotency (FR-005)

- Extract `create_unified_view` to a shared module (e.g., `tools/schema.py`) imported by both `tools/sync_db.py` and `src/app/queries.py`.
- Wrap per-table sync in a savepoint; on failure keep temp DB and exit 1 (already done), but also write the failing table + error to stderr in structured format.
- Add `--check` mode: verify row counts then exit without rebuilding (for cron health checks).

### 3. Structured logging (FR-006)

- Add `src/app/logging_config.py`: `logging.config.dictConfig` JSON formatter.
- Middleware logs each request: method, path, status, duration_ms, user (from session), request_id.
- Auth events: `user_login` success/fail, `user_logout`, `session_expired`.
- Sync events via `tools/sync_db.py` `--json-logs` flag or `SYNC_LOG_JSON=1` env.

### 4. RBAC completeness (FR-004)

- Inventory all routes (`main.py`): ensure every route uses `require_login()` or explicit public marker; `/sync` stays `require_role("management")`.
- Customer role: verify `customer_company` filter applied to shipments + invoices queries (already done via `company_filter`).

### 5. Dependency hygiene

- `pip-audit` in CI (or documented pre-commit step); pin transitive deps via lockfile.

## Phase 2: Tasks

See `tasks.md` (generated alongside). Summary of task groups:

- T001–T005: foundational (tests, auth persistence, logging config)
- T010–T014: US1 security hardening
- T020–T023: US2 onboarding (README, .gitignore, docker-compose polish)
- T030–T033: US3 observability
- T040–T043: hardening phase + Strix re-run

## Phase 3: Implementation

Order: T001–T005 → T010–T014 → T020–T023 → T030–T033 → T040–T043. Each task references the SoT artifact in `docs/` that justifies it.

## Phase 4: Testing

```bash
cd /d/PROJECTS/cmt-dash
python -m pytest tests/ -q
```

Acceptance: all US1–US3 acceptance scenarios pass; `strix -m deep -t .` returns 0 Critical/High.

## Quickstart

```bash
# Docker path (recommended)
docker compose up --build

# Local path
pip install -r src/requirements.txt
python tools/sync_db.py          # builds data/cmt.db
uvicorn app.main:app --app-dir src --port 8000
```

## Rollout

1. Merge hardening branch after review gate (Stage 8).
2. Deploy: `docker compose up -d --build`.
3. Set env: `CMT_USERS` (or run seed), `CMT_COOKIE_SECURE=1` behind TLS, `SYNC_LOG_JSON=1`.
4. Re-run `strix` post-deploy to confirm closure.