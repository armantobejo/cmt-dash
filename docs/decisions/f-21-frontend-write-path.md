# Decision Record — F-21 Frontend Write Path (PRD §1.3, F-14, F-17, F-21)

**Status**: Decided — 2026-11-19
**Decides**: PRD F-21 canonical write path, §1.3 /sync exemption, F-14/F-17 acceptance criteria
**Related**: [`PRD.md`](../../PRD.md) §1.2, §1.3, F-21 · [`docs/decisions/filament-view-layer.md`](filament-view-layer.md) §5 · [`docs/srs.md`](../srs.md) FR-005, FR-007

---

## 1. The decision in one line

`POST /sync` is an **exempt trigger endpoint** — it initiates the backend `dbf:sync` batch job (operational control) and does **not** constitute a business-data write path. The **sole** write path for business tables is the `dbf:sync` command / `SyncService` running in a subprocess.

---

## 2. Context

- The product concept: **read-only dashboard** over legacy FoxPro DBF files. Business data originates in the legacy app; the dashboard mirrors it.
- PRD §1.3 (Non-goals): "No write/edit features on business data — the dashboard never modifies DBF or synced data."
- F-14: Manual sync trigger required for `management+` role.
- F-17: Parity test must capture `POST /sync` response against Python baseline.
- F-21: Read-only guarantee — only `users`/`sessions` (+ Laravel app tables) writable.
- `filament-view-layer.md` §5: "Read-only guarantee (F-21). Business tables are sync-owned; converted pages expose read-only tables only. No mutations, no Filament actions that write business data."
- Audit finding (from parent task `t_fe30c092`): `/sync` POST writes ALL 18 business tables via `SyncService` (backend), not the frontend. Filament has zero Resources, only read-only Pages. All other writes (8 endpoints) are auth/cookie only — compliant with F-21.

---

## 3. Decision

### Canonical write path

| Layer | Role |
|-------|------|
| **Frontend** (`POST /sync` route) | Trigger only — accepts no business data in request body, requires `management+` role + CSRF, spawns `dbf:sync` subprocess with timeout |
| **Backend** (`dbf:sync` / `SyncService`) | **Sole writer** of business tables — performs `INSERT`/`INSERT OR REPLACE` into all 18 tables inside a temp DB, then atomic `rename()` swap (FR-007) |

### Exemption rule (PRD §1.3)

> **The `POST /sync` trigger endpoint is exempt**: it initiates the background DBF→SQLite sync job (a backend batch process), not a business-data form; the sync itself writes business tables via the `dbf:sync` command / `SyncService`, not the frontend request.

---

## 4. Rationale

1. **Product concept**: Dashboard is a read-only view. Business data originates in legacy FoxPro app.
2. **F-14 requirement**: Management needs manual sync trigger. Classifying `/sync` as a "business write" would forbid this operational capability.
3. **Architectural consistency**: `filament-view-layer.md` §5 codifies "Read-only guarantee (F-21). Business tables are sync-owned; converted pages expose read-only tables only. No mutations, no Filament actions that write business data." Converted pages are Filament **Pages** with read-only tables — no Resources, no actions.
4. **Operational vs. business data**: `/sync` is an operational control (refresh mirror), not user data entry. The request body carries no business fields.

---

## 5. Acceptance criteria (grep-testable, from PRD F-21)

1. **No business-data forms**: `grep -r "method=POST\|method=\"post\"" resources/views/ --include="*.blade.php` returns only `/login`, `/logout`, `/users/*`, `/set-lang`, `/set-db`, `/sync`. Zero POST forms targeting business tables.
2. **`/sync` request body empty of business data**: route handler reads only auth/session; no Eloquent/model mass-assignment, no `request()->all()` on business fields.
3. **All business writes route through `dbf:sync` / `SyncService`**: static analysis (`grep -r "INSERT\|UPDATE\|DELETE" app/ --include="*.php" | grep -v "users\|sessions\|cache\|jobs\|SyncService\|DbfSync"`) returns zero hits on business tables.
4. **Filament business pages are read-only**: every converted §3.2 page uses a `Page` class with `records()` hook, no `FilamentResource`, no `Action` that mutates business data. Parity test asserts no mutating action routes registered.
5. **Parity test captures `/sync` POST**: F-17 parity test records status + JSON response of `POST /sync` against Python baseline, enforcing trigger parity.

---

## 6. Rejected alternatives

| Alternative | Why rejected |
|-------------|--------------|
| Treat `POST /sync` as a business write path (no exemption) | Violates F-21 as written; removes the manual sync trigger (F-14) that management requires; sync is an operational batch job, not user data entry. |
| Move sync to background cron only (no frontend trigger) | F-14 explicitly requires a manual sync trigger for `management+`; owner expects on-demand refresh. |
| Add Filament Resources with forms for business tables | Contradicts `filament-view-layer.md` decision (read-only, no forms), violates F-21, adds write surface where none exists in the Python app. |
| Exempt all authenticated POST endpoints | Over-broad; `/users/*` are auth administration (explicitly allowed), `/set-lang\|db` are cookie setters (no DB write). Only `/sync` is the operational trigger. |

---

## 7. Verification

- PRD.md updated: §1.3 exemption added, §1.2 "forms" → "tables", F-14/F-17/F-21 rows updated, dedicated F-21 section added with canonical path, rationale, 5 acceptance criteria, 4 rejected alternatives.
- Parity test (`tests/Feature/FilamentInvoicesParityTest` or equivalent) asserts no mutating actions on business models.
- Static analysis grep passes in CI.

---

## 8. Downstream impact

- Any new Filament page converting a §3.2 report **must** use `Page` + read-only table, never `FilamentResource` with forms/actions for business data.
- If a future feature requires a frontend write path for business data, it requires a new decision record overturning this one (owner decision).
- The `SyncService` / `dbf:sync` command remains the **single source of truth** for business-table write logic — no duplication.