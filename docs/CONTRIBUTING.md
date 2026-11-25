# Contributing to CMT Dashboard

Short guide for common tasks. Full SoT (source-of-truth) docs live in `docs/`
(`srs.md`, `information_architecture.md`, `data_model.md`, `design_system.md`,
`user_flows/`, `system_logics/`).

## Setup

See [README.md](../README.md) Quickstart. Run tests before opening a PR:

```bash
python -m pytest tests/
```

## How to add a query

1. Extend `src/app/queries.py` with a `get_*` function using `db.query()` /
   `db.query_one()` — always parameterized, never f-string SQL.
2. If the query counts rows in a new table via `db.table_count()`, add the
   table name to `ALLOWED_TABLES` in `src/app/db.py` (frozen whitelist —
   injection foot-gun guard).
3. Wire the route in `src/app/main.py` with `Depends(require_login())` (or
   `require_role(...)` for privileged actions).
4. Add a test in `tests/` (see existing suites for the pattern).

## How to add a translation

1. Add the key to **all three** files:
   - `src/app/translations/en.json`
   - `src/app/translations/id.json`
   - `src/app/translations/zh.json`
2. Reference it in templates as `{{ _t("your.key") }}`.
3. New UI text must not be hardcoded in templates — i18n is a hard requirement.

## How to add a route

1. Check the RBAC decision matrix in `docs/information_architecture.md` first.
2. Decide: public (whitelist + `# public-endpoint:` comment with rationale) or
   behind `require_login()` / `require_role(...)`.
3. Add the route to `src/app/main.py`.
4. Update the route inventory table in `docs/information_architecture.md`.
5. Test: authenticated + unauthenticated access (see `tests/test_rbac.py`).

## How to add a user

Users come only from the `CMT_USERS` environment variable (no in-source
defaults — see README). There is intentionally no user-management UI.

## Security

- Never hardcode passwords or salts in source. Passwords hash with
  PBKDF2-HMAC-SHA256 (200k iterations, per-user random salt).
- `/api/health` is the only intentionally public data route.
- Run `make audit` (pip-audit) before merging dependency changes.