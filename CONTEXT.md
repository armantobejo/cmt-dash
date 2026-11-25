# Agent Context & Brain Protocol

## Brain Plugin — Mandatory Usage

**Every session MUST start and end with the brain plugin.**

### Start of Session
```bash
python memory/brain.py load
```
This loads:
- `context.json` — project description, stack, architecture, pending work, decisions
- `preferences.json` — code style, commit convention, testing, deployment
- `history.jsonl` — recent session history (last 20 entries)

### During Session
After completing significant work, log it immediately:
```bash
python memory/brain.py log "Description of what was done" --tags "tag1,tag2"
```

When making architectural decisions:
```bash
python memory/brain.py save --decision "Decision description"
```

When adding/removing pending work:
```bash
python memory/brain.py save --pending "New task"
python memory/brain.py save --clear-pending
```

### End of Session
Log the session summary:
```bash
python memory/brain.py log "Session summary: what was accomplished" --tags "session-summary"
```

Sync back to global brain:
```bash
python memory/brain.py sync-repo
```

---

## Project: cmt-dashboard

**CMT logistics dashboard** — FastAPI + Jinja2 + CoreUI + SQLite

### Stack
| Layer | Technology |
|-------|-----------|
| Backend | FastAPI (Python 3.13) |
| Frontend | CoreUI 5 Bootstrap + Jinja2 templates |
| Database | SQLite (DBF sync via sync_db.py) |
| Container | Docker Compose (dashboard + sync services) |
| i18n | JSON translation files (en, id, zh) |

### Key Files
- `src/app/main.py` — FastAPI entry point
- `src/app/queries.py` — UnifiedShipment view + joins
- `src/app/auth.py` — Session cookie auth, roles: management/customer
- `src/app/db.py` — SQLite wrapper
- `src/app/templates/` — Jinja2 templates (CoreUI sidebar layout)
- `src/app/static/` — JS, CSS, CoreUI bundle
- `src/app/sync.py` + `tools/sync_db.py` — DBF → SQLite sync
- `tests/` — pytest (63 tests)

### Key Tables
- `UnifiedShipment` (view: NA + CARGO merged)
- `OPRCOST` (operational costs per shipment)
- `INVOICE` (invoices, READO=1 means paid)
- `DRCRFULL` (DN/CN register, payment splits)
- `CONSIGNEE` / `SHIPPER` (lookup tables)
- `VESSEL`

### Conventions
- Commits: `feat/fix/chore(scope): description`
- Code style: Follow existing patterns, minimal changes
- Testing: Run `pytest` before committing, all 63 tests must pass
- Deployment: `docker-compose up --build -d`

### Current Branch
`feat/system-hardening`
