# F-26 Finance Reports

## Route Path
- `GET /ledger` + `GET /ledger/detail?k=<acct>` — General Ledger list + detail
- `GET /sales` — Sales summary, trend, top customers, top salesman (covered by F-10)

## Page Purpose
Finance reporting surfaces: journal listing, general ledger by account, trial balance (mutation / balance sheet / income statement), and sales summary.

## Visible Columns / Surfaces
- Listing Journal: JE, JE_D rendered as journal entries
- General Ledger: JE, JE_D, ACCOUNT with account type filter D/G, search, sort, pre-2000 corrupted dates handled
- Trial Balance — Mutation: JE, JE_D, ACCOUNT, SALDO_ACCT
- Trial Balance — Balance Sheet & Income Statement: derived from ACCOUNT (TYPE, GROUP, SLD_NORMAL) + SALDO_ACCT
- Balance Sheet / Income Statement: derived from ACCOUNT classification + SALDO_ACCT
- Other Finance Reports: catch-all — parity means same absence on both apps

## Filters & Sort Options
- Account type filter (D/G), search, sort on General Ledger
- Period (`p=month|year|round|all`) on Sales

## Data Source
JE, JE_D, ACCOUNT, SALDO_ACCT — in-ingest tables; NOT company-filtered by design (every role sees the active DB's ledger).

## Acceptance Criteria
- Journal / ledger / trial balance match both apps; pre-2000 corrupted dates render without crashing
- Sales summary / trend / top customers / top salesman match per period
- Not company-filtered (by design)
