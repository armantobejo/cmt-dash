# F-27 Statement of Account

## Route Path
- `GET /statement-of-account` + `GET /api/reports/statement-of-account`

## Page Purpose
Customer statement of account: derived from INVOICE plus A/R receipts, over a date pair with currency (USD/IDR), pagination, and sort direction.

## Visible Columns / Surfaces
- Statement lines (invoice + A/R receipts) per account/company over the selected date range
- SOA / SOA_D: archive of issued statements, not the working source

## Filters & Sort Options
- Company, account, date_from, date_to, currency (USD | IDR), per_page, sort_dir
- Defaults: date_from = first of month, date_to = today, currency = USD, sort_dir = asc

## Data Source
INVOICE + A/R receipts (ARS / ARS_R); SOA / SOA_D is the archive of issued statements. Company-scoped.

## Acceptance Criteria
- SOA_D rows link to invoice (example: 2401-I/00012/SHG/JK)
- Customer sees only own-company statements
- Currency and pagination bounds enforced (per_page ≤ 500)
