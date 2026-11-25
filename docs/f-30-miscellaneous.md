# F-30 Miscellaneous Views

## Route Path
- No dedicated routes registered yet in `src/app/main.py`; documented below from docs/parity-checklist.yaml pending page implementation.

## Page Purpose
Small, company-specific listing and dictionary surfaces: petty cash requests, refund cost requests, recurring cost dictionary.

## Visible Surfaces
- Petty Cash Request Listing: PERMOHONAN_E (CSM 318, MAL 403 rows only; empty in CME / CMT / SBY) — page renders with company-specific note
- Refund Cost Request Listing: RCREQ / RCREQ_D (APPLICANT, TOT_IDR, PROFITLOSS, UINVOICE_R) — second-layer; CME empty (0 rows)
- Recurring Costs: COST cost-type dictionary (JENISCOST filled; COST / SELLING all zero in all five) — renders as dictionary view, not a rate table

## Filters & Sort Options
- None defined beyond company scoping where applicable

## Data Source
PERMOHONAN_E, RCREQ / RCREQ_D, COST — second-layer / dictionary tables; vestigial tables (AR, AR_D, AR_CONTRA/R, AP_CONTRA/R, CLEARING, SALDO_VENDOR, SALDO_AGENT, PERMOHONAN/D, VENDOR_MAR_D/S) empty in all five companies.

## Acceptance Criteria
- Each page renders with its company-specific note (including empty-row notes)
- Contra Bond In/Out cannot be reproduced from current data (noted)

*Status: enumeration-level; no routes registered yet — pending page implementation.*
