"""
DBF to SQLite Synchronization Service

This script connects to a directory of DBF files and synchronizes them
to a SQLite database. It's designed to be run periodically (e.g., via cron)
to keep the web dashboard's data fresh.

Key Features:
- Full sync: Wipes and re-creates tables on each run for simplicity.
- Robust type mapping and data cleaning.
- Handles common DBF file issues like encoding errors and bad date formats.
- Batched inserts for better performance on large tables.
- Verification step to ensure row counts match between DBF and SQLite.
"""
import json
import os
import sqlite3
import sys
import time
from datetime import date
from pathlib import Path

from dbfread import DBF
from dbfread import FieldParser as _DBFFieldParser
from schema import create_unified_view  # noqa: E402 (importable when run as `python tools/sync_db.py`)

# --- Configuration ---
DBF_DIR = os.environ.get("DBF_DIR", "/data/dbf")
DB_PATH = os.environ.get("DATABASE_URL", "/data/cmt.db")
DATA_DIR = os.environ.get("DATA_DIR", str(Path(DB_PATH).parent))
BATCH_SIZE = 1000

# --- Table selection ---
# The dashboard only reads a fixed set of tables.  Syncing every .dbf in
# DATA/ + INIT/ also pulls in FoxPro app-internal files (menus, logon logs,
# accounting ledgers like JE_D / AP / SALDO_ACCT) that can be hundreds of MB,
# which blows past the sync timeout.  Default to the tables the app queries.
# Override with SYNC_TABLES (comma-separated, case-insensitive) or sync
# everything with SYNC_ALL_TABLES=1.
_REQUIRED_TABLES = {
    "CARGO", "NA",
    "INVOICE", "INVOICE_R",
    "OPRCOST", "OPRCOST_R",
    "DRCRFULL", "DRCRFULL_R",
    "SHIPPER", "CONSIGNEE", "AGENT", "VESSEL", "SATUAN",
    "USER", "JE", "JE_D",
    # General ledger support (accounting/financial reports):
    # ACCOUNT = chart of accounts, SALDO_ACCT = monthly per-account balances
    "ACCOUNT", "SALDO_ACCT",
    # Second-layer DBF (A/R receipt / A/P payment journalism):
        # ARS = A/R receipts, ARS_R = ARS rows; AR_AGENT/_D = AR agent detail;
        # AP / AP_R = A/P payments; AP_AGENT/_D = AP agent detail;
        # BAYAR/_R = payments; TERIMA/_R = receipts;
        # RC/_D = receipts clearing; RCREQ/_D = receipt requests;
        # CLEARING_D = clearing detail. (Contra Bond / AP_CONTRA excluded - Tier C, empty.)
        "ARS", "ARS_R", "AR_AGENT", "AR_AGENT_D",
        "AP", "AP_R", "AP_AGENT", "AP_AGENT_D",
        "BAYAR", "BAYAR_R", "TERIMA", "TERIMA_R",
        "RC", "RC_D", "RCREQ", "RCREQ_D",
        "CLEARING_D",
        # Quotation / vendor detail (second-layer, A/R receipt / A/P payment journalism):
        "QUOTATION", "QUOTATION_R", "VENDOR", "VENDOR_D",
        # Manifest / aging / sales invoice (second-layer, Q-11 Option A):
        # MANIFEST = shipment manifest; SAGING = aging buckets; SI/SI_D = sales invoices.
        "MANIFEST", "SAGING", "SI", "SI_D",
    }
_env_sync_tables = os.environ.get("SYNC_TABLES", "").strip()
if _env_sync_tables:
    _REQUIRED_TABLES = {t.strip().upper() for t in _env_sync_tables.split(",") if t.strip()}
SYNC_ALL_TABLES = os.environ.get("SYNC_ALL_TABLES", "") == "1"


def _is_required_table(table_name: str) -> bool:
    """True if *table_name* should be synced (whitelist or SYNC_ALL_TABLES=1)."""
    return SYNC_ALL_TABLES or table_name in _REQUIRED_TABLES

# --- Structured logging ---
SYNC_LOG_JSON = os.environ.get("SYNC_LOG_JSON", "") == "1"
_events: list[dict] = []


def emit(event: str, **fields) -> None:
    """Emit a sync event. JSON when SYNC_LOG_JSON=1, else human-readable text."""
    fields["event"] = event
    if SYNC_LOG_JSON:
        _events.append(fields)
        print(json.dumps(fields, ensure_ascii=False, default=str))
    else:
        parts = [f"{k}={v}" for k, v in fields.items()]
        print(f"[sync] {event}: " + " ".join(parts))

# --- Type Mapping & Conversion ---

def dbf_to_sqlite_type(field_type, field_len=0, field_dec=0):
    """Maps DBF field types to SQLite types and a converter function name."""
    if field_type in ('C', 'M'):
        return 'TEXT', 'text'
    if field_type == 'L':
        return 'INTEGER', 'bool'
    if field_type == 'D':
        return 'TEXT', 'date'
    if field_type == 'N':
        return ('INTEGER' if field_dec == 0 else 'REAL'), ('int' if field_dec == 0 else 'float')
    return 'TEXT', 'text'  # Default for other types like B, F, I, 0

def convert_val(value, converter_name):
    """Cleans and converts a single value based on the target type."""
    if value is None:
        return None
    if isinstance(value, str):
        value = value.strip()
    if not value:
        return None

    try:
        if converter_name == 'text':
            return str(value)
        if converter_name == 'bool':
            return 1 if str(value).strip().upper() in ('T', 'Y', '1', 'TRUE') else 0
        if converter_name == 'date':
            if isinstance(value, date):
                return value.isoformat()
            if isinstance(value, str) and len(value) == 8 and value.isdigit():
                return f"{value[:4]}-{value[4:6]}-{value[6:8]}"
            return str(value) # Keep as is if format is unexpected
        if converter_name == 'int':
            return int(float(value))
        if converter_name == 'float':
            return float(value)
    except (ValueError, TypeError):
        return None # Return NULL if conversion fails
    return str(value)

# --- Main Sync Logic ---

def _collect_dbf_files(base_dir: str) -> list[tuple[str, str]]:
    """Collect (filename, filepath) pairs from *base_dir*.

    Supports two layouts:
      - Flat:   *.dbf files directly in base_dir
      - Nested: DATA/ and/or INIT/ subdirs containing *.dbf files
    """
    base = Path(base_dir)
    data_dir = base / 'DATA'
    init_dir = base / 'INIT'

    files: list[tuple[str, str]] = []
    if data_dir.is_dir() or init_dir.is_dir():
        # New nested layout — scan DATA then INIT
        for subdir in sorted([d for d in [data_dir, init_dir] if d.is_dir()]):
            for filename in sorted(os.listdir(subdir)):
                if filename.lower().endswith('.dbf'):
                    files.append((filename, str(subdir / filename)))
    else:
        # Legacy flat layout
        for filename in sorted(os.listdir(base)):
            if filename.lower().endswith('.dbf'):
                files.append((filename, str(base / filename)))
    return files


def get_dbf_tables():
    """Scans the DBF directory and loads all tables into memory."""
    tables = {}
    if not Path(DBF_DIR).exists():
        print(f"Error: DBF directory not found at {DBF_DIR}", file=sys.stderr)
        sys.exit(1)

    print(f"Scanning for DBF files in {DBF_DIR}...")
    for filename, filepath in _collect_dbf_files(DBF_DIR):
        table_name = os.path.splitext(filename)[0].upper()

        if not _is_required_table(table_name):
            print(f"  - Skipping {table_name} (not in required table set; set SYNC_ALL_TABLES=1 to sync all)")
            continue

        # Custom field parser that handles corrupt dates/numbers gracefully
        # instead of letting exceptions corrupt the iterator state.
        class _RobustParser(_DBFFieldParser):
            def parse(self, field, data):
                """Catch any field-level parsing error and return None
                instead of crashing the entire table iteration."""
                try:
                    return super().parse(field, data)
                except (ValueError, OverflowError, TypeError):
                    return None

            def parseD(self, field, data):
                try:
                    return super().parseD(field, data)
                except (ValueError, OverflowError):
                    return None

            def parseN(self, field, data):
                try:
                    return super().parseN(field, data)
                except (ValueError, OverflowError):
                    return None

            def parseL(self, field, data):
                try:
                    return super().parseL(field, data)
                except (ValueError, OverflowError, TypeError):
                    return None

        for encoding in ('latin-1', 'cp437', 'utf-8'):
            try:
                table = DBF(filepath, encoding=encoding, ignore_missing_memofile=True,
                            char_decode_errors='ignore', parserclass=_RobustParser)
                records = list(table)
                field_meta = [(f.name, f.type, f.length, f.decimal_count) for f in table.fields]
                
                # Deduplicate field names
                seen_names = set()
                unique_meta = []
                for meta in field_meta:
                    if meta[0] not in seen_names:
                        unique_meta.append(meta)
                        seen_names.add(meta[0])

                tables[table_name] = {
                    'records': records,
                    'field_meta': unique_meta,
                }
                print(f"  - Found {table_name} ({len(records)} records, {len(unique_meta)} fields, encoding: {encoding})")
                break
            except Exception as e:
                print(f"    - Failed to read {filename} with encoding {encoding}: {e}")
        else:
            print(f"  - FAILED to load {filename} with any encoding. (path: {filepath})", file=sys.stderr)
    return tables

def create_schema(cursor, table_name, field_meta):
    """Generates and executes the CREATE TABLE statement."""
    cols = ['"rowid_" INTEGER PRIMARY KEY'] # Use an explicit PK
    for name, ftype, flen, fdec in field_meta:
        sql_type, _ = dbf_to_sqlite_type(ftype, flen, fdec)
        # Avoid conflict with sqlite's own rowid
        col_name = f'"{name.replace("ID", "_id") if name.upper() == "ID" else name}"'
        cols.append(f'{col_name} {sql_type}')
    
    create_sql = f'CREATE TABLE "{table_name}" ({", ".join(cols)})'
    cursor.execute(f'DROP TABLE IF EXISTS "{table_name}"')
    cursor.execute(create_sql)

def insert_data(cursor, table_name, table_info):
    """Inserts records into the specified table in batches."""
    field_meta = table_info['field_meta']
    records = table_info['records']
    
    if not records:
        return 0

    col_names = ', '.join(f'"{n.replace("ID", "_id") if n.upper() == "ID" else n}"' for n,_,_,_ in field_meta)
    placeholders = ', '.join(['?'] * len(field_meta))
    insert_sql = f'INSERT INTO "{table_name}" ({col_names}) VALUES ({placeholders})'

    converters = [dbf_to_sqlite_type(t,l,d)[1] for _,t,l,d in field_meta]
    
    rows_to_insert = []
    for rec in records:
        row = [convert_val(rec.get(fname), conv) for (fname,_,_,_), conv in zip(field_meta, converters)]
        rows_to_insert.append(tuple(row))

    cursor.executemany(insert_sql, rows_to_insert)
    return len(rows_to_insert)


def _copy_app_tables(src_path, dest_conn):
    """Preserve app-internal tables (users, sessions) across the atomic swap.

    The sync builds a fresh DB and os.replace()s it over the live one, which
    would otherwise drop the app's user accounts and sessions on every sync.
    Copy those tables (schema + rows) from the old DB into the new one first.
    """
    if not Path(src_path).exists():
        return
    src = sqlite3.connect(str(src_path))
    try:
        for table in ("users", "sessions"):
            try:
                ddl = src.execute(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name=?", (table,)
                ).fetchone()
                rows = src.execute(f'SELECT * FROM "{table}"').fetchall()
            except sqlite3.OperationalError:
                continue  # not present in the old DB; nothing to preserve
            if not ddl or not ddl[0]:
                continue
            dest_conn.execute(f'DROP TABLE IF EXISTS "{table}"')
            dest_conn.execute(ddl[0])
            if rows:
                placeholders = ",".join(["?"] * len(rows[0]))
                dest_conn.executemany(
                    f'INSERT INTO "{table}" VALUES ({placeholders})', rows
                )
            # Keep AUTOINCREMENT counters in sync so new ids never collide.
            # Only tables with an `id` column participate (sessions keys on token).
            col_names = [r[1] for r in dest_conn.execute(f'PRAGMA table_info("{table}")')]
            if "id" in col_names:
                dest_conn.execute(
                    "INSERT OR REPLACE INTO sqlite_sequence(name, seq) "
                    f"VALUES (?, (SELECT COALESCE(MAX(id), 0) FROM \"{table}\"))",
                    (table,),
                )
    finally:
        src.close()


def _discover_companies(dbf_dir: str) -> list[tuple[str, str]]:
    """If *dbf_dir* contains subdirectories that themselves hold .dbf files,
    return [(company_name, subdirectory_path), ...].  Otherwise return an
    empty list (meaning the DBF files live directly in *dbf_dir*).

    Supports two layouts:
      - Legacy flat:  DBF/CMT/*.dbf
      - New nested:   DBF/CMT/DATA/*.dbf + DBF/CMT/INIT/*.dbf
    """
    base = Path(dbf_dir)
    companies = []
    for child in sorted(base.iterdir()):
        if not child.is_dir():
            continue
        # New nested layout: DATA/ and/or INIT/ subdirs contain .dbf files
        if (child / 'DATA').is_dir() and any((child / 'DATA').glob('*.dbf')):
            companies.append((child.name, str(child)))
        elif any(child.glob('*.dbf')):
            # Legacy flat layout: .dbf files directly in the company dir
            companies.append((child.name, str(child)))
    return companies


def _sync_single(dbf_dir: str, db_path: str) -> bool:
    """Sync one DBF directory to one SQLite database.  Returns True on success."""
    global DBF_DIR
    orig_dbf_dir = DBF_DIR
    DBF_DIR = dbf_dir
    try:
        emit("sync_start", dbf_dir=dbf_dir, db_path=db_path)
        start = time.perf_counter()

        db_final_path = Path(db_path)
        db_temp_path = db_final_path.with_suffix('.db-sync')

        dbf_tables = get_dbf_tables()
        if not dbf_tables:
            print(f"  No DBF tables found in {dbf_dir}. Skipping.")
            emit("sync_fail", phase="scan", error="No DBF tables found", dbf_dir=dbf_dir)
            return False

        # Ensure data directory exists
        db_final_path.parent.mkdir(parents=True, exist_ok=True)

        conn = sqlite3.connect(db_temp_path)
        cur = conn.cursor()

        total_rows = 0
        all_ok = True
        for table_name, table_info in sorted(dbf_tables.items()):
            print(f"  Syncing table: {table_name}")
            t0 = time.perf_counter()
            try:
                create_schema(cur, table_name, table_info['field_meta'])
                rows = insert_data(cur, table_name, table_info)
                conn.commit()
                ms = int((time.perf_counter() - t0) * 1000)
                print(f"    ...synced {rows} rows.")
                emit("table_synced", table=table_name, rows=rows, ms=ms)
                total_rows += rows
            except Exception as e:
                print(f"    ...ERROR syncing {table_name}: {e}", file=sys.stderr)
                emit("sync_fail", table=table_name, error=str(e))
                conn.rollback()
                all_ok = False

        # Verification
        print("  --- Verification ---")
        mismatches = []
        if all_ok:
            for table_name, info in sorted(dbf_tables.items()):
                try:
                    cur.execute(f'SELECT COUNT(*) FROM "{table_name}"')
                    actual = cur.fetchone()[0]
                    expected = len(info['records'])
                    if expected != actual:
                        mismatches.append(f"    - {table_name}: DBF={expected}, SQLite={actual}")
                except sqlite3.OperationalError:
                    mismatches.append(f"    - {table_name}: Not found in SQLite for verification.")

        conn.close()

        if not all_ok or mismatches:
            print(f"  Sync failed for {dbf_dir}. Temporary database is at: {db_temp_path}")
            if mismatches:
                print("  COUNT MISMATCHES FOUND:")
                for m in mismatches:
                    print(m)
            return False

        # Atomic rename
        try:
            conn = sqlite3.connect(db_temp_path)
            try:
                create_unified_view(conn)
            except sqlite3.OperationalError as e:
                print(f"  [schema] UnifiedShipment skipped: {e}")
            _copy_app_tables(db_final_path, conn)
            # Add performance indexes for detail joins
            for idx_sql in (
                'CREATE INDEX IF NOT EXISTS idx_oprcost_r_uopr ON OPRCOST_R(UOPRCOST)',
                'CREATE INDEX IF NOT EXISTS idx_oprcost_ref ON OPRCOST(REFSHITMEN)',
                'CREATE INDEX IF NOT EXISTS idx_drcrfull_r_unik ON DRCRFULL_R(UNIK)',
                'CREATE INDEX IF NOT EXISTS idx_drcrfull_ref ON DRCRFULL(REFSHITMEN)',
                'CREATE INDEX IF NOT EXISTS idx_invoice_ref ON INVOICE(REFSHITMEN)',
                'CREATE INDEX IF NOT EXISTS idx_invoice_k ON INVOICE(K_INVOICE)',
                'CREATE INDEX IF NOT EXISTS idx_je_d_uje ON JE_D(UJE)',
                'CREATE INDEX IF NOT EXISTS idx_je_d_acct ON JE_D(KD_ACCOUNT)',
                'CREATE INDEX IF NOT EXISTS idx_je_uje ON JE(UJE)',
                'CREATE INDEX IF NOT EXISTS idx_je_modul ON JE(MODUL)',
                'CREATE INDEX IF NOT EXISTS idx_saldo_acct ON SALDO_ACCT(KD_ACCOUNT, TANGGAL)',
                # Partial indexes for the data-quality page's constant
                # predicates (anomaly rows are tiny, so these stay small).
                'CREATE INDEX IF NOT EXISTS idx_je_d_bad_dk ON JE_D(DK) WHERE DK IS NOT NULL AND DK NOT IN (\'D\', \'K\')',
                'CREATE INDEX IF NOT EXISTS idx_je_d_uje_head ON JE_D(UJE) WHERE UJE IS NULL',
            ):
                try:
                    conn.execute(idx_sql)
                except sqlite3.OperationalError:
                    pass  # table may not exist in this company's DB
            conn.commit()
            conn.close()

            os.replace(db_temp_path, db_final_path)
            print(f"  Successfully synced to {db_final_path}")
        except OSError as e:
            print(f"  FATAL: Could not rename temp DB: {e}", file=sys.stderr)
            return False

        print(f"  --- Sync Complete ---")
        print(f"  Total rows synced: {total_rows}")
        emit(
            "sync_complete",
            total_rows=total_rows,
            tables=len(dbf_tables),
            ms=int((time.perf_counter() - start) * 1000),
            ok=True,
        )
        return True
    finally:
        DBF_DIR = orig_dbf_dir


def main():
    """Main synchronization function.

    Detects whether DBF_DIR contains subdirectories (one per company) or
    flat .dbf files.  When subdirectories are found, each is synced to its
    own SQLite DB named {company}.db in DATA_DIR.  Otherwise the original
    single-DB sync runs against DB_PATH.
    """
    companies = _discover_companies(DBF_DIR)

    if companies:
        print(f"Detected {len(companies)} company databases: {', '.join(c[0] for c in companies)}")
        data_path = Path(DATA_DIR)
        data_path.mkdir(parents=True, exist_ok=True)
        overall_start = time.perf_counter()
        results = []
        for company_name, company_dbf_dir in companies:
            print(f"\n{'='*60}")
            print(f"Syncing company: {company_name}")
            print(f"{'='*60}")
            db_path = str(data_path / f"{company_name}.db")
            ok = _sync_single(company_dbf_dir, db_path)
            results.append((company_name, ok))
        print(f"\n{'='*60}")
        print("Multi-company sync summary:")
        for name, ok in results:
            status = "OK" if ok else "FAILED"
            print(f"  {name}: {status}")
        elapsed = int((time.perf_counter() - overall_start) * 1000)
        emit("sync_complete", companies=len(results), ms=elapsed,
             ok=all(ok for _, ok in results))
        if not all(ok for _, ok in results):
            sys.exit(1)
    else:
        # Legacy single-DBF-directory mode
        emit("sync_start", dbf_dir=DBF_DIR, db_path=DB_PATH)
        print(f"No company subdirectories found in {DBF_DIR}, syncing as single DB.")
        start = time.perf_counter()

        db_final_path = Path(DB_PATH)
        db_temp_path = db_final_path.with_suffix('.db-sync')

        dbf_tables = get_dbf_tables()
        if not dbf_tables:
            print("No DBF tables found. Exiting.")
            emit("sync_fail", phase="scan", error="No DBF tables found")
            return

        # Ensure data directory exists
        db_final_path.parent.mkdir(parents=True, exist_ok=True)

        conn = sqlite3.connect(db_temp_path)
        cur = conn.cursor()

        total_rows = 0
        all_ok = True
        for table_name, table_info in sorted(dbf_tables.items()):
            print(f"Syncing table: {table_name}")
            t0 = time.perf_counter()
            try:
                create_schema(cur, table_name, table_info['field_meta'])
                rows = insert_data(cur, table_name, table_info)
                conn.commit()
                ms = int((time.perf_counter() - t0) * 1000)
                print(f"  ...synced {rows} rows.")
                emit("table_synced", table=table_name, rows=rows, ms=ms)
                total_rows += rows
            except Exception as e:
                print(f"  ...ERROR syncing {table_name}: {e}", file=sys.stderr)
                emit("sync_fail", table=table_name, error=str(e))
                conn.rollback()
                all_ok = False

        # --- Verification ---
        print("\n--- Verification ---")
        mismatches = []
        if all_ok:
            for table_name, info in sorted(dbf_tables.items()):
                try:
                    cur.execute(f'SELECT COUNT(*) FROM "{table_name}"')
                    actual = cur.fetchone()[0]
                    expected = len(info['records'])
                    if expected != actual:
                        mismatches.append(f"  - {table_name}: DBF={expected}, SQLite={actual}")
                except sqlite3.OperationalError:
                     mismatches.append(f"  - {table_name}: Not found in SQLite for verification.")

        conn.close()

        if not all_ok or mismatches:
            print("Sync failed. Temporary database is at:", db_temp_path)
            if mismatches:
                print("COUNT MISMATCHES FOUND:")
                for m in mismatches:
                    print(m)
            sys.exit(1)

        # Atomic rename
        try:
            conn = sqlite3.connect(db_temp_path)
            create_unified_view(conn)
            _copy_app_tables(db_final_path, conn)
            # Add performance indexes for detail joins
            for idx_sql in (
                'CREATE INDEX IF NOT EXISTS idx_oprcost_r_uopr ON OPRCOST_R(UOPRCOST)',
                'CREATE INDEX IF NOT EXISTS idx_oprcost_ref ON OPRCOST(REFSHITMEN)',
                'CREATE INDEX IF NOT EXISTS idx_drcrfull_r_unik ON DRCRFULL_R(UNIK)',
                'CREATE INDEX IF NOT EXISTS idx_drcrfull_ref ON DRCRFULL(REFSHITMEN)',
                'CREATE INDEX IF NOT EXISTS idx_invoice_ref ON INVOICE(REFSHITMEN)',
                'CREATE INDEX IF NOT EXISTS idx_invoice_k ON INVOICE(K_INVOICE)',
                'CREATE INDEX IF NOT EXISTS idx_je_d_uje ON JE_D(UJE)',
                'CREATE INDEX IF NOT EXISTS idx_je_d_acct ON JE_D(KD_ACCOUNT)',
                'CREATE INDEX IF NOT EXISTS idx_je_uje ON JE(UJE)',
                'CREATE INDEX IF NOT EXISTS idx_je_modul ON JE(MODUL)',
                'CREATE INDEX IF NOT EXISTS idx_saldo_acct ON SALDO_ACCT(KD_ACCOUNT, TANGGAL)',
                # Partial indexes for the data-quality page's constant
                # predicates (anomaly rows are tiny, so these stay small).
                'CREATE INDEX IF NOT EXISTS idx_je_d_bad_dk ON JE_D(DK) WHERE DK IS NOT NULL AND DK NOT IN (\'D\', \'K\')',
                'CREATE INDEX IF NOT EXISTS idx_je_d_uje_head ON JE_D(UJE) WHERE UJE IS NULL',
            ):
                try:
                    conn.execute(idx_sql)
                except sqlite3.OperationalError:
                    pass  # table may not exist in this company's DB
            conn.commit()
            conn.close()

            os.replace(db_temp_path, db_final_path)
            print(f"Successfully synced to {db_final_path}")
        except OSError as e:
            print(f"FATAL: Could not rename temp DB: {e}", file=sys.stderr)
            sys.exit(1)

        print(f"\n--- Sync Complete ---")
        print(f"Total rows synced: {total_rows}")
        emit(
            "sync_complete",
            total_rows=total_rows,
            tables=len(dbf_tables),
            ms=int((time.perf_counter() - start) * 1000),
            ok=True,
        )

if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description="Sync DBF files to SQLite for the CMT Dashboard.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""environment variables:
  DBF_DIR          DBF source directory (default: /data/dbf)
  DATABASE_URL     SQLite file path (default: /data/cmt.db)
  DATA_DIR         Output directory for company DBs (default: parent of DATABASE_URL)
  SYNC_TABLES      Comma-separated table names to sync (overrides default set)
  SYNC_ALL_TABLES  Set to 1 to sync every .dbf found (slow, for auditing)
  SYNC_LOG_JSON    Set to 1 for machine-readable JSON log output""",
    )
    parser.parse_args()  # just --help for now; future: --dry-run, --company
    main()
