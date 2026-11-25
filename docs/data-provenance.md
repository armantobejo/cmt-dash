# Data Provenance Decision Record

**Scope:** Shipment-detail page (5 sections) — settled provenance so UI work can start.

---

## Section → Source Table Mapping

| Section | Source Tables (parent → child) | Key Join Columns | Grain |
|---------|-------------------------------|------------------|-------|
| **Manifest** | `MANIFEST` (second-layer, not in 18-table ingest) | — | One row per shipment (pending ingest) |
| **Invoice (line)** | `INVOICE` → `INVOICE_R` | `INVOICE.NOINVOICE` = `INVOICE_R.NOINVOICE`<br>`INVOICE.K_INVOICE` = `INVOICE_R.K_INVOICE` | One line per `JENISCOST` per invoice |
| **Operational Cost (line)** | `OPRCOST` → `OPRCOST_R` | `OPRCOST.UOPRCOST` = `OPRCOST_R.UOPRCOST` | One line per `JENISCOST` per cost header |
| **DR/CN FCL (line)** | `DRCRFULL` → `DRCRFULL_R` | `DRCRFULL.UNIK` = `DRCRFULL_R.UNIK`<br>Split: `UnifiedShipment.DIMENSI` IN (`'20'`,`'40'`,`'40 HQ'`,`'45'`) | One line per `JENISCOST` per DR/CN note (FCL subset) |
| **DR/CN LCL (line)** | `DRCRFULL` → `DRCRFULL_R` | `DRCRFULL.UNIK` = `DRCRFULL_R.UNIK`<br>Split: `UnifiedShipment.DIMENSI` IN (`'CBM'`,`'KGS'`,`'M3'`,`'CTNS'`,`'DOC'`) | One line per `JENISCOST` per DR/CN note (LCL subset) |

> **Note:** `MANIFEST` table absent from `tools/sync_db.py:_REQUIRED_TABLES` (lines 40–59). `DRCRFULL` FCL/LCL split defined at `queries.py:376–377` and used in Summary (`queries.py:393–402`) but **NOT implemented in Detail page** (`get_shipment_detail` at `queries.py:663–690` classifies by amount sign only).

---

## NA_D vs OPRCOST_R Verdict

**Distinct concepts — zero key overlap.** Operational Cost (line) section feeds from **`OPRCOST_R`**.

| Aspect | NA_D | OPRCOST_R |
|--------|------|-----------|
| Parent table | `NA` (import) | `OPRCOST` (export/import, `EI` column) |
| Parent key | `NA_D.UNIK` → `NA.UNIK` | `OPRCOST_R.UOPRCOST` → `OPRCOST.UOPRCOST` |
| Resolve rate (CMT) | 99.96% (4 orphans) | Orphans: 66 (no `OPRCOST` parent) |
| `NA_D.UNIK` ∩ `OPRCOST_R.UOPRCOST` | **0** (all 5 companies) | **0** |
| `NA_D.UNIKR` ∩ `OPRCOST_R.UNIK` | **0** | **0** |

**Evidence:**
- Live DBF counts across 5 companies: `tools/legacy_report_evidence.py:286–305`; JSON snapshot `docs/report-dbf-coverage/legacy-evidence.json:/measured_facts/na_d_vs_oprcost_r`
- Detail-page query chooses `OPRCOST_R` explicitly: `queries.py:618–633` (comment + SQL)
- Legacy program binding: `UPD_OC.PRG` F3 handler browses `OPRCOST_R`, never `NA_D` — `docs/report-dbf-coverage.md:60`

**NA_D screen binding:** **unverified-locally** — missing artifact = readable legacy report program (packed `.PRG` executables, no decompiled source). Per `docs/report-dbf-coverage.md:180–182`, `kanban/review/verify-inferred-report-mappings.md:51–53`.

**Ingest status:**
- `OPRCOST_R` — **in-ingest**: listed in `tools/sync_db.py:43` (`_REQUIRED_TABLES`, 38 tables after second-layer AR/AP additions)
- `NA_D` — **not ingested**: absent from `_REQUIRED_TABLES`; gated by `docs/decisions/q11-second-layer-ingest-brief.md:58` (Tier D, needs named verifier)

---

## Evidence Path per Claim

| Claim | Evidence Path | Status |
|-------|---------------|--------|
| 5 sections mapped to DBF tables | `tests/parity/fixtures/real_schema.json` (CMT company) | verified |
| PK/FK/join keys + grain for each | `queries.py:622–633` (OPRCOST_R), `queries.py:641–659` (INVOICE_R), `queries.py:681–690` (DRCRFULL_R) | verified |
| FCL/LCL dim lists | `queries.py:376–377` | verified |
| FCL/LCL split in Summary only | `queries.py:393–402` vs `queries.py:663–690` | verified |
| MANIFEST not in 18-table ingest | `tools/sync_db.py:40–59` | verified |
| NA_D vs OPRCOST_R zero overlap (5 cos) | `tools/legacy_report_evidence.py:286–305`, `docs/report-dbf-coverage/legacy-evidence.json` | verified |
| Operational Cost line uses OPRCOST_R | `queries.py:618–633`, `docs/report-dbf-coverage.md:60` | verified |
| NA_D screen binding unknown | `docs/report-dbf-coverage.md:180–182` | **unverified-locally** (missing artifact) |
| OPRCOST_R orphans = 66 (CMT) | `docs/decisions/q11-second-layer-ingest-brief.md:58` | verified |

---

## Open Questions / Blockers

1. **MANIFEST ingest (second-layer)** — pending owner decision on Q-11 Option A (26 tables). See `docs/decisions/q11-second-layer-ingest-brief.md:49,107–110`. Blocks manifest section entirely.

2. **Detail-page FCL/LCL split for DR/CN** — query + template sections not implemented. Only Summary has it (`queries.py:393–402`). Blocks DR/CN FCL/LCL line sections in Detail.

3. **NA_D screen binding** — missing artifact: decompiled legacy `.PRG` report program (packed executables). Requires named verifier (Arman per Q-11 sign-off). Blocks NA_D provenance.

4. **OPRCOST_R orphan policy** — 66 parent keys in CMT with no `OPRCOST` row (identical across 5 companies). Tier D in Q-11 (`q11-second-layer-ingest-brief.md:93`). Affects A/P aging if that report lands.

5. **Schema snapshot** — `tests/parity/fixtures/real_schema.json` must be regenerated (`python tools/dump_real_schema.py`) when second-layer tables are added; schema gates (`tests/test_schema_gate.py`, Laravel `ProductionSchemaTest.php`) will fail otherwise.

---

## Acceptance

All 5 sections covered. Every claim traceable to a file path/line or explicitly flagged `unverified-locally` with named missing artifact.