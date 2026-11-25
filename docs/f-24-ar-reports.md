# F-24 Account Receivables Reports

## Route Path
- `GET /aging/ar` + `GET /api/reports/aging/ar` — Aging A/R (invoice + debit note)
- `GET /ar-receipt` + `GET /api/reports/ar-receipt` — Receipt A/R

## Page Purpose
A/R aging and receipt reporting: bucketed aging (1, 30, 31, 60, 61, 90, 90 days), contra bond, receipt, and discrepancy surfaces for receivables.

## Visible Columns / Surfaces
- Aging A/R — Invoice: buckets from SAGING (AGING1..7), rows from INVOICE (BYR_USD, DIRPKAN, SELISIH_US, LUNAS, KB)
- Aging A/R — Debit Note: buckets from SAGING, rows from DRCRFULL / DRCRFULL_R (BAYAR, DIRPKAN, SELISIH, LUNAS)
- Contra Bond — In: AR_CONTRA / AR_CONTRA_R (empty in all five companies; renders zero rows with note)
- Receipt A/R — Invoice: ARS_R (K_INVOICE, NOINVOICE, BAYAR, SELISIH) — second-layer table
- Discrepancy — Invoice: ARS_R.SELISIH, INVOICE.SELISIH_US, INVOICE.SELISIH_ID, AR_AGENT_D.SELISIH, DRCRFULL.SELISIH
- Receipt — Cash/Bank: ARS, TERIMA, TERIMA_R (JENIS_BYR, KD_BANK, NO_GBCEKTT, TGL_KLIRIN) — second-layer

## Filters & Sort Options
- Company (required), as_of date, min_amount, counterparty, page, per_page
- Aging API validates as_of (ISO date) and requires company

## Data Source
INVOICE, DRCRFULL / DRCRFULL_R, AR_CONTRA / AR_CONTRA_R, ARS / ARS_R, TERIMA / TERIMA_R, AR_AGENT_D — second-layer tables per Q-11 ingest.

## Acceptance Criteria
- Buckets and rows match both apps; empty contra tables render zero rows with note
- Customer sees only own-company rows in all AR reports
- API validation (as_of, company required) enforced

*API endpoints require staff role; company filter applied per logged-in user's customer_company.*
