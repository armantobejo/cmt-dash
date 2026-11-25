# Decision: NA_D vs OPRCOST_R — distinct concepts, OPRCOST_R feeds Operational Cost line

## Verdict
**Distinct** — different parent tables, zero key overlap.

## Evidence (live DBF across 5 companies)

| Metric | CMT | CSM | MAL | SBY | CME |
|--------|-----|-----|-----|-----|-----|
| NA_D rows | 103,990 | 14,467 | 76,709 | 451 | 29 |
| NA_D parents | 9,725 | 1,646 | 10,084 | 61 | 4 |
| NA_D.UNIK → NA.UNIK resolve | **99.96%** | 100% | 99.91% | 98.36% | 75% |
| NA_D.UNIK ∩ OPRCOST_R.UOPRCOST | **0** | **0** | **0** | **0** | **0** |
| NA_D.UNIKR ∩ OPRCOST_R.UNIK | **0** | **0** | **0** | **0** | **0** |
| OPRCOST_R orphans (no OPRCOST parent) | 66 | 3 | 26 | 66 | 66 |

Source: `tools/legacy_report_evidence.py:measured_facts()` — lines 286-305; JSON snapshot `docs/report-dbf-coverage/legacy-evidence.json:/measured_facts/na_d_vs_oprcost_r`. Counts are live rows from that snapshot; `docs/report-dbf-coverage.md:174-177` carries an older CMT snapshot (9,530 parents / 102,154 rows, same 99.96% and same zero overlap) — the DBF tree is live and grows between runs, so both are correct for their run.

## Interpretation
- **NA_D** is the cost-line child of **import `NA`** records (parent key `NA_D.UNIK` → `NA.UNIK`).
- **OPRCOST_R** is the cost-line child of **`OPRCOST`** records (parent key `OPRCOST_R.UOPRCOST` → `OPRCOST.UOPRCOST`); `OPRCOST.EI` carries export/import side.
- Both share a similar cost-line shape (`JENISCOST`, `DESCRIPTIO`, `CURRENCY`, `QTY`, `JUMLAH`) but have **different parent keys, different grain, zero overlap**. Not redundant — both legitimate.

## Operational Cost (line) section feeds from **OPRCOST_R** (not NA_D)

Stated explicitly in the detail-page query implementation:
- `src/app/queries.py:560-563` — comment block declaring the resolution and choice.
- `src/app/queries.py:570-576` — actual SQL joining `OPRCOST_R` → `OPRCOST` on `UOPRCOST`.

Confirmed by legacy program binding:
- `UPD_OC.PRG` F3 detail handler (`CekDetil`) browses `OPRCOST_R` (`for uoprcost = Oprcost.uoprcost`), never references `NA_D` — `docs/report-dbf-coverage.md:60`.

## NA_D screen binding
**unverified-locally** — missing artifact = readable legacy report program (packed `.PRG` executables, no decompiled source). `docs/report-dbf-coverage.md:180-182`, `kanban/review/verify-inferred-report-mappings.md:51-53`.

## Ingest status
- `OPRCOST_R` — **in-ingest**: listed in `tools/sync_db.py:43` (`_REQUIRED_TABLES`, now 38 names after second-layer AR/AP tables were added).
- `NA_D` — **not ingested**: absent from `_REQUIRED_TABLES`; missing artifact = `NA_D` DBF not in the sync whitelist, gated by `docs/decisions/q11-second-layer-ingest-brief.md:58`.