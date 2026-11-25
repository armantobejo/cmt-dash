# CMT Dashboard

Web-based read-only dashboard over a legacy FoxPro (DBF) cargo/accounting database.
Modern front-end for an actively-used legacy system. See [`IDEA.md`](IDEA.md) for the
original concept.

## Quickstart

### Docker (recommended)

```bash
docker compose up --build
# dashboard: http://localhost:8000
```

Two services:

| Service | Purpose |
|---|---|
| `dashboard` | FastAPI + Jinja2 web app (port 8000) |
| `sync` | One-off DBF → SQLite sync job (runs on compose up, exits) |

### Local (no Docker)

```bash
python -m venv .venv && .venv/Scripts/activate    # Windows
pip install -r src/requirements.txt -r src/requirements-dev.txt
DBF_DIR="$(pwd)/DBF" DATABASE_URL="$(pwd)/data/cmt.db" python tools/sync_db.py
uvicorn app.main:app --app-dir src --port 8000
```

### Testing

```bash
python -m pytest tests/
```

## Architecture

```text
legacy FoxPro app  ──realtime writes──▶  DBF/  (source of truth, read-only)
                                              │
                          tools/sync_db.py (cron / docker `sync` service)
                                              ▼
                                      data/cmt.db  (SQLite)
                                              │
                              FastAPI (src/app/) — Jinja2 + CoreUI
                                              ▼
                                        Browser
```

- Sync wipes + rebuilds `cmt.db` from `DBF/` atomically (temp file + `os.replace`).
  A failed sync never corrupts the live DB.
- The dashboard is read-only. There is no write/edit feature (per `IDEA.md`).
- `UnifiedShipment` view merges `NA` (imports) + `CARGO` (exports/other); its SQL
  lives in exactly one module: `tools/schema.py`.

## Environment Variables

| Var | Default | Description |
|---|---|---|
| `DATABASE_URL` | `data/cmt.db` | SQLite file path |
| `DBF_DIR` | `DBF/` | DBF source directory |
| `CMT_USERS` | *(none)* | Seed users, comma-separated `user:pass:role[:company]`. Roles: `superadmin`, `admin`, `management`, `staff`, `customer`. |
| `CMT_COOKIE_SECURE` | *(off)* | Set `1` behind TLS to send `Secure` session cookies |
| `CMT_LANG` | `id` | Default language |

> Note: `CMT_USERS` is the ONLY way to create users. Fresh deploys must set it,
> or no one can log in. Example:
> `CMT_USERS=superadmin:change-me-now:superadmin,admin:another-pw:admin`

## Roles

| Role | Access |
|---|---|
| `superadmin` | Everything |
| `admin` | Everything except user management |
| `management` | All data + trigger sync (`POST /sync`) |
| `staff` | All data, no sync |
| `customer` | Only own company's shipments/invoices (`customer_company` filter) |

## Routes

| Route | Auth | Notes |
|---|---|---|
| `GET /login`, `POST /login` | public | session cookie `cmt_session` (HttpOnly, Secure behind TLS) |
| `POST /logout` | login | |
| `GET /`, `/shipments`, `/imports`, `/exports`, `/invoices`, `/vessels` | login | Jinja2 pages |
| `POST /sync` | management+ | triggers DBF sync |
| `GET /api/health` | public | liveness probe |
| `GET /api/summary` | login | dashboard JSON |
| `POST /set-lang` | public | i18n cookie |

## Operations

- **Manual sync:** `POST /sync` (web, management+) or
  `DBF_DIR=DBF DATABASE_URL=data/cmt.db python tools/sync_db.py`
- **Sync JSON logs:** `SYNC_LOG_JSON=1 python tools/sync_db.py` emits machine-readable
  events (`sync_start`, `table_synced`, `sync_fail`, `sync_complete`).
- **Dependency audit:** `pip-audit -r src/requirements.txt`
- **Contributing:** see [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md)