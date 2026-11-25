# System Logic: Data Sync (`tools/sync_db.py`)

**Derives from**: `docs/srs.md` FR-007, `docs/data_model.md`

## Sync Flow

1. Read DBF dir (`DBF_DIR`, default `DBF/`).
2. Create temp SQLite at `.db-sync` (same dir as target).
3. For each DBF table: drop + recreate table, insert rows (type conversion, cleaning).
4. Create `UnifiedShipment` view (shared SQL from `tools/schema.py`).
5. Verify row counts vs DBF source.
6. Atomic `os.replace` temp → `cmt.db`. Live DB never partially synced.
7. On any failure: keep temp DB, exit 1, report failing table + error.

Idempotent: full wipe + rebuild from immutable DBF source; N runs → identical valid `cmt.db`.

## Sync Logging (opt-in JSON)

Env `SYNC_LOG_JSON=1` switches output to JSON lines (default stays human-readable).

| Event | Fields |
|---|---|
| `sync_start` | `ts`, `dbf_dir` |
| `table_synced` | `ts`, `table`, `rows`, `ms` |
| `sync_fail` | `ts`, `table`, `error` |
| `sync_complete` | `ts`, `total_rows`, `ms`, `ok` |

## `--check` Mode

Verify row counts only, no rebuild. Exit 0 if counts match, 1 otherwise. For cron health checks.