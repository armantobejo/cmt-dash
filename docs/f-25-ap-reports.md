# F-25 Account Payables Reports

## Route Path
- `GET /aging/ap` + `GET /api/reports/aging/ap` — Aging A/P
- `GET /ap-payment` + `GET /api/reports/ap-payment` — Payment A/P

## Page Purpose
A/P aging and payment reporting: bucketed aging, contra bond, vendor / agent / refund cost payment surfaces for payables.

## Visible Columns / Surfaces
- Aging A/P — Operational Cost: buckets from SAGING, rows from OPRCOST / OPRCOST_R (BAYAR, DIRPKAN, SELISIH, LUNAS, KD_SHIPLIN)
- Aging A/P — Credit Note: buckets from SAGING, rows from DRCRFULL / DRCRFULL_R
- Contra Bond — Out: AP_CONTRA / AP_CONTRA_R (empty in all five; renders zero rows with note)
- Payment A/P — Vendor: AP / AP_R (KD_SHIPLIN, TGL_JT, TOT_BAYAR; AP_R.INVOICE_NO, BAYAR, SISA_USD) — second-layer
- Payment A/P — Agent: AP_AGENT / AP_AGENT_D (AGENT, CODE, TOTBAYAR) — second-layer
- Payment A/P — Refund Cost: RC / RC_D (NO_REF, JENIS_BYR, KD_BANK, TGL_KLIRIN, TOT_BAYAR; RC_D.UINVOICE_R) — second-layer
- Payment — Cash/Bank: BAYAR / BAYAR_R, TERIMA / TERIMA_R — second-layer

## Filters & Sort Options
- Company (required), as_of date, min_amount, counterparty, page, per_page
- Aging API validates as_of and requires company

## Data Source
OPRCOST / OPRCOST_R, DRCRFULL / DRCRFULL_R, AP_CONTRA / AP_CONTRA_R, AP / AP_R, AP_AGENT / AP_AGENT_D, RC / RC_D, BAYAR / BAYAR_R, TERIMA / TERIMA_R — second-layer tables per Q-11 ingest.

## Acceptance Criteria
- Buckets and rows match both apps; empty contra tables render zero rows with note
- Customer sees only own-company rows in all AP reports
- API validation (as_of, company required) enforced

*API endpoints require staff role; company filter applied per logged-in user's customer_company.*
