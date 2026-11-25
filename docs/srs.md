# Software Requirements Specification — CMT Dashboard

**Status**: Draft (validated by owner at Stage 3 gate)
**Derives from**: `.hermes/plans/cmt-dash/brief.md`
**Target**: `D:/PROJECTS/cmt-dash`

---

## 1. Introduction

### 1.1 Purpose
Modernize and harden the CMT web dashboard — a read-only reporting front-end over a
legacy FoxPro (DBF) accounting/cargo database. The dashboard itself already exists
(FastAPI + SQLite + Jinja2); this SRS is the source of truth for the **hardening**
pass: security, persistence, observability, and developer experience.

### 1.2 Scope
In scope:
- Replace in-memory auth/sessions with SQLite-backed persistent store
- Remove hardcoded credentials; per-user salted PBKDF2 hashes; env-driven seeding
- Session expiry + secure cookie flags
- Full RBAC coverage audit on every route
- Structured JSON logging (access, auth, sync)
- Idempotent, observable DBF→SQLite sync
- Single-source-of-truth for `UnifiedShipment` view SQL
- Dependency vulnerability scan + fixes
- README, Makefile, .gitignore, CONTRIBUTING

Out of scope (explicitly rejected):
- Any write/edit features on the DB (IDEA.md states "no feature to edit database")
- Replacing the FoxPro legacy system
- New frontend framework or full redesign (CoreUI stays)
- Multi-tenancy, plugin system, caching layer, ORM

### 1.3 Stakeholders & Audiences
The dashboard serves 5 roles (descending privilege):
1. **superadmin** — everything, user management
2. **admin** — everything except user management (TBD detail)
3. **management** — operations + can trigger sync
4. **staff** — day-to-day shipment/invoice lookup
5. **customer** — sees only own company's shipments/invoices (company filter)

### 1.4 Data Flow (as-is, verified from code)
1. Legacy FoxPro app keeps writing DBF files
2. Sync cron (`tools/sync_db.py` or the `sync` docker service) wipes + rebuilds SQLite `data/cmt.db` from DBF dir
3. Dashboard (`src/app/`) reads SQLite via `db.py`/`queries.py`
4. `UnifiedShipment` view merges `NA` (imports, TYPETRAN='I') + `CARGO` (exports/other)

## 2. Functional Requirements

### FR-001 Auth persistence
System MUST store users and sessions in SQLite (new tables `users`, `sessions`),
replacing the in-memory `UserStore` dict.

### FR-002 No hardcoded credentials
System MUST NOT contain plaintext passwords or static salts in source.
Seed data MUST come from env `CMT_USERS` or an explicit seed script/CLI.
Password hashing MUST use PBKDF2-HMAC-SHA256 with per-user random salt.

### FR-003 Session lifecycle
System MUST issue opaque random session tokens (existing `secrets.token_urlsafe(32)`
is fine), persist them with `created_at`/`expires_at`, reject expired sessions,
and log `session_expired` events.

### FR-004 Cookie flags
`cmt_session` cookie MUST stay `HttpOnly` + `SameSite=Lax` and additionally
`Secure` when `CMT_COOKIE_SECURE=1` (set behind TLS). Same treatment for `cmt_lang`.

### FR-005 RBAC completeness
Every route MUST be either explicitly public (documented whitelist) or behind
`require_login()`; privileged actions (`/sync`) MUST use `require_role()`.
Customer role MUST be data-filtered by `customer_company` on shipments + invoices.

### FR-006 Structured logging
System MUST emit JSON logs for: each HTTP request (method, path, status,
duration_ms, user, request_id), auth events (`user_login` ok/fail, `user_logout`,
`session_expired`), and sync events (`sync_start`, `table_synced`, `sync_fail`,
`sync_complete`).

### FR-007 Idempotent sync
`tools/sync_db.py` MUST be runnable repeatedly with identical output DB integrity;
MUST keep atomic `os.replace` swap; MUST report per-table row counts and failures
in machine-readable form; shared `UnifiedShipment` view SQL MUST live in ONE module.

### FR-008 Dependency hygiene
`src/requirements.txt` MUST pass `pip-audit` with zero Critical/High findings
or documented accepted-risk entries.

### FR-009 Health endpoint
`GET /api/health` stays public (liveness). `GET /api/summary` — owner decision:
keep public or gate; must be documented in security-baseline.md either way.

## 3. Non-Functional Requirements

| ID | Category | Requirement |
|----|----------|-------------|
| NFR-001 | Security | Strix deep scan: 0 Critical, 0 High |
| NFR-002 | Performance | Dashboard pages < 500ms p95; sync of ~50 DBF tables < 5 min |
| NFR-003 | Reliability | Sync failure never corrupts `cmt.db` (temp file + atomic rename) |
| NFR-004 | Maintainability | No duplicated view SQL; tests cover auth + queries |
| NFR-005 | Onboarding | Fresh clone → running app in ≤ 2 shell commands |
| NFR-006 | i18n | id/en/zh translation layers keep working; new keys added for new UI text |

## 4. Accepted Risks / Open Questions (for security-baseline.md)

- `GET /api/health` + `GET /api/summary` public by default — risk-accepted for liveness; revisit if internal-only deploy
- No TLS termination in app — fronted by reverse proxy in prod (cookie Secure flag must be set there)
- CMT_USERS env seeding: short-lived plaintext in process env — accepted, standard practice

## 5. Validation (Stage 3 gate)

- [ ] Owner confirms: 5 audiences correct, role matrix correct, no-write constraint correct
- [ ] Owner confirms: public-endpoint whitelist acceptable
- [ ] Owner confirms: PBKDF2 + SQLite auth approach acceptable