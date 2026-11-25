# Changelog

All notable changes to the CMT Dashboard are documented here.

## [Unreleased]

### Added
- General Ledger page with chart of accounts and journal entry drill-down
- Invoice detail page with line items and payment summary
- Sales Dashboard with Top Salesman, Top Customers, and Monthly Revenue cards
- Invoice status filters (Un-issued, Issued, Paid, Issued & Unpaid)
- Gross Profit display on Shipment Details (IDR conversion via per-document EXRATE)
- Shipment Info card now shows ETD, ETA, and ATA
- Shipper code + name resolution on Invoices list and Shipment Detail
- Custom 404 error page with translation support
- `--help` flag for `tools/sync_db.py` with env var documentation
- `CHANGELOG.md` (this file)
- General Ledger and Invoices grouped under "Accounting & Finance" sidebar menu
- Admin group with Users sub-menu in sidebar
- Sub-menu items with smaller icons and muted color scheme

### Changed
- Sync now only ingests 14 tables the app actually queries (was: every .dbf)
- Sync subprocess timeout raised to 600s (was: 300s), configurable via `SYNC_TIMEOUT_SECONDS`
- DBF source structure: data in `DATA/`, init tables in `INIT/` per company
- Invoice line items grouped by `K_INVOICE` (was: `NOINVOICE`, which merged duplicates)
- Sidebar restructured into main groups: Home, Sales, Shipments, Accounting & Finance, Admin
- Invoice corner totals convert USD at each document's own EXRATE

### Fixed
- `/invoices/detail` returning 500 — `q.get_invoice_detail()` was called but never defined; implemented (header + line items with SATUAN names, import/export customer branch)
- Customer-role dashboard returning 500 — `get_summary()` company filter was concatenated without an ` AND ` separator, producing invalid SQL
- Top Salesman card showing raw IDs instead of salesman names
- Invoice shipper code not resolving to company name
- Sync timeout when INIT folders added large accounting tables (JE_D, SALDO_ACCT)

## [0.1.0] - 2026-08-01

### Added
- Initial release: read-only dashboard over FoxPro DBF cargo/accounting data
- Docker-based deployment with auto-sync
- Multi-language support (ID, EN, ZH)
- Role-based access (superadmin, admin, management, staff, customer)
- Company database switcher
- Shipment and invoice listing with search, sort, and pagination
- Sync status API endpoint with staleness detection
- Structured JSON logging
- Request logging middleware
- Health check endpoint
