# F-32 Volume, Lifting & Gross Profit Reports

## Route Path
- `GET /gross-profit` + `GET /api/reports/gross-profit` — Gross Profit & Loss
- `GET /sales` — Sales summary (covered by F-10)

## Page Purpose
Volume, lifting, and gross profit reporting from the same menu block; Volume and Lifting are enumeration-level and blocked on legacy report templates; Gross Profit is derived (INVOICE − OPRCOST).

## Visible Surfaces
- Volume report: period aggregates over CARGO / NA (container, measurement, weight fields) — BLOCKED on TEMPLATE VOLUME.DOC or legacy screenshot
- Lifting report: period aggregates over CARGO / NA — BLOCKED on TEMPLATE LIFTING.DOC or legacy screenshot
- Gross Profit & Loss: INVOICE minus OPRCOST over a date pair — derived, no template needed, parity-ready once query defined

## Filters & Sort Options
- Volume / Lifting: period (blocked, not defined)
- Gross Profit: company, start, end date pair

## Data Source
CARGO / NA (container, measurement, weight); INVOICE, OPRCOST (both in the 18-table ingest). Sales from the same menu block covered by F-10.

## Acceptance Criteria
- Gross Profit & Loss parity-ready once query defined and executed on production-shaped DB
- Volume / Lifting remain blocked pending TEMPLATE VOLUME.DOC / LIFTING.DOC (or legacy screenshot)

*Status: enumeration-level, not parity-ready — Volume and Lifting unverified-locally.*
