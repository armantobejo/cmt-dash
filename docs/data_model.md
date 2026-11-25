# Data Model — CMT Dashboard

**Status**: Draft (validated)
**Derives from**: `tools/sync_db.py`, `src/app/queries.py`, `schema.yaml`

This document outlines the SQLite data model, which is a direct reflection of the source DBF files. The `sync_db.py` script performs a full wipe-and-replace, mapping DBF types to SQLite types.

## 1. Tables & Schema

The following tables are created in `cmt.db`. Field types are as inferred by `dbf_to_sqlite_type`.

| DBF Source | SQLite Table | Description |
|---|---|---|
| `ACCOUNT.DBF` | `ACCOUNT` | Chart of accounts. |
| `AGENT.DBF` | `AGENT` | Shipping agents. |
| `AP.DBF`, `AP_R.DBF` | `AP`, `AP_R` | Accounts Payable records. |
| `AR.DBF`, `AR_D.DBF`, `ARS.DBF`| `AR`, `AR_D`, `ARS` | Accounts Receivable records. |
| `CARGO.DBF` | `CARGO` | Export and other non-import shipments. Key source for `UnifiedShipment` view. |
| `CONSIGNEE.DBF`| `CONSIGNEE` | Consignee (recipient) details. |
| `INVOICE.DBF`| `INVOICE` | Invoicing data. |
| `NA.DBF` | `NA` | Import shipments. Key source for `UnifiedShipment` view. |
| `SHIPPER.DBF`| `SHIPPER` | Shipper (sender) details. |
| `VESSEL.DBF` | `VESSEL` | Vessel information. |
| *(...and ~40 other tables)* | *(...)* | Other supporting financial and operational data. |

### New Tables (for Hardening)

These tables will be added to `cmt.db` to support persistent authentication.

#### `users`
| Column | Type | Description |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY |
| `username` | TEXT | UNIQUE NOT NULL |
| `display_name` | TEXT | |
| `role` | TEXT | NOT NULL (superadmin, admin, etc.) |
| `pw_hash` | TEXT | NOT NULL (PBKDF2 hash) |
| `salt` | TEXT | NOT NULL (random salt for hashing) |
| `customer_company` | TEXT | NULLABLE (company name for customer role filtering) |

#### `sessions`
| Column | Type | Description |
|---|---|---|
| `token` | TEXT | PRIMARY KEY (opaque session token) |
| `user_id` | INTEGER | FOREIGN KEY (`users.id`) |
| `created_at` | TEXT | ISO 8601 timestamp |
| `expires_at` | TEXT | ISO 8601 timestamp |

## 2. Views

### `UnifiedShipment`

This is the most critical view, created by `tools/schema.py` (refactored from `queries.py` and `sync_db.py`). It provides a consistent interface for querying both import and export shipments.

**Source**: `SELECT ... FROM NA UNION ALL SELECT ... FROM CARGO`

**Key Derived Columns**:
- `TYPETRAN`: Set to `'I'` for records from `NA`, otherwise uses the value from `CARGO`.
- All columns from `NA` are present; `CARGO` columns that don't exist in `NA` are filled with `NULL`.

This view is essential for simplifying the application logic in `queries.py`, allowing most shipment-related queries to run against a single, predictable schema.

## 3. Data Flow & Integrity

1. **Source of Truth**: The `.DBF` files in the `/data/dbf` directory. These are considered immutable from the perspective of this application.
2. **Synchronization**: The `tools/sync_db.py` script performs a full data transfer:
    - It creates a temporary database (`.db-sync`).
    - It drops and re-creates every table based on the schema of the corresponding `.DBF` file.
    - It inserts all records, performing data type conversion and cleaning.
    - It creates the `UnifiedShipment` view.
    - It performs a verification step (row counts).
    - If successful, it atomically renames the temporary DB to the final path (`cmt.db`), ensuring the live database is never in a partially-synced or corrupt state.
3. **Application Access**: The FastAPI application reads exclusively from `cmt.db`. It never writes to the database, except for the new `users` and `sessions` tables.

## 4. Application-Level Constraints (from YAGNI Sweep)

- `table_count(table)` in `db.py` MUST be backed by a frozen whitelist of allowed table names (see YAGNI cut #5). No dynamic table-name arguments.
