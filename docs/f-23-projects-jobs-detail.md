# F-23 Projects / Jobs — Detail

## Route Path
- Detail is surfaced per reference via `GET /shipments/detail?ref=<known_ref>` (F-05d) until a dedicated `GET /projects-jobs/detail` route is registered — no `/projects-jobs/detail` route exists in `src/app/main.py` yet.

## Page Purpose
Detail view of a single project/job: manifest, invoice lines, operational cost lines, DR/CN FCL lines, DR/CN LCL lines, container/dimension lines, and import cost lines.

## Visible Sections
- Manifest (NOMOR, DATE, VESSEL, CONTAINERN, ETD_1, ETA_1) — MANIFEST / second-layer table
- Invoice lines grouped by K_INVOICE with SATUAN resolution — INVOICE_R
- Operational cost lines grouped by UOPRCOST — OPRCOST_R
- DR/CN FCL lines (KD_SHIPLIN set) — DRCRFULL_R
- DR/CN LCL lines (KD_SHIPLIN not set) — DRCRFULL_R
- Container/dimension lines — CARGO_R, NA_R (second-layer)
- Import cost lines — NA_D (child of NA, not duplicate of OPRCOST_R)

## Filters & Sort Options
- Reference lookup (`ref`) — known ref renders detail; unknown ref → 303 back to `/projects-jobs/summary`
- Customer guard: foreign-company ref → 303 back to `/projects-jobs/summary`

## Data Source
Second-layer tables (MANIFEST, INVOICE_R, OPRCOST_R, DRCRFULL_R, CARGO_R, NA_R, NA_D) per the PRD second-layer ingest (Q-11).

## Acceptance Criteria
- All sections render per reference; unknown/foreign refs redirect 303
- Data origins match the parity checklist (PRD row F-23)

*Status: detail route not yet registered — enumerated from docs/parity-checklist.yaml pending route implementation.*
