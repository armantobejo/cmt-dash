# Q-11 decision brief — which second-layer DBFs join the sync (one page)

**For:** owner · **From:** engineering · **Date:** 2026-10-14 · **Decision:** PRD §10 `Q-11` / §4 `F-31`
**Evidence:** [`docs/report-dbf-coverage.md`](../report-dbf-coverage.md) (measured with `tools/dbf_inventory.py` and `tools/legacy_report_evidence.py`). Every count in the table below is re-derived by `python tools/q11_brief_numbers.py` — exit 0 means this brief still matches `docs/report-dbf-coverage/inventory.json`.

**The choice in one line.** Each company's `DATA/` dir holds **110 DBF files** (112 in SBY); only 18 tables are synced. Pick which of the rest join the whitelist, and say whether `F-31` is parity or new scope. No report page, query or PRD edit follows from this decision automatically — each accepted tier becomes its own follow-up item.

## What each answer unblocks — the six blocked items

| Blocked item in `kanban/todo/` | Needs from your answer | Answer that unblocks it |
|---|---|---|
| `ar-ap-aging-report-pages` | `SAGING` (1 row) in the whitelist — **or** approval of a constants module citing `SAGING` | Q1 any option / Q1c |
| `ar-ap-receipt-payment-report-pages` | the receipt + payment tables (Tier A) | Q1 Option A |
| `statement-of-account-page` | `ARS`/`ARS_R` for the receipt half — or: ship invoice-side now, receipts after ingest | Q1 Option A, or the split |
| `rate-by-destination-page` | `QUOTATION`/`QUOTATION_R` (+ `VENDOR`/`VENDOR_D`) | Q1 Option A |
| `volume-lifting-report-column-spec` | **not Q-11** — needs `TEMPLATE\VOLUME.DOC` / `LIFTING.DOC` (or a printed sample) | Q4 |
| `decide-second-layer-dbf-ingest-subset` | this brief + your sign-off at the bottom | Q1–Q4 |

## Measured counts (recomputed, not retyped)

Tier is **A** = ingest, **A\*** = proposed ingest (report needs it, §4 did not classify it), **B** = report is *derived* from Tier A + the 18 (its archive tables need no ingest), **C** = vestigial, **D** = verify first.

<!-- q11-numbers:begin -->
| Table | Tier | Report it serves (PRD section) | Rows CME/CMT/CSM/MAL/SBY |
|---|---|---|---|
| `ARS` | A | 3.2.2 Receipt A/R - Invoice; 3.2.5 SOA receipt half (F-24, F-27) | 23/3298/2188/7678/35 |
| `ARS_R` | A | 3.2.2 Receipt A/R - Invoice (UPD_INV.PRG join); 3.2.5 F-27 | 471/13205/3139/20383/519 |
| `AR_AGENT` | A | 3.2.2 agent DN/CN receipts (UPD_DN.PRG join) (F-24) | 1/442/98/925/0 |
| `AR_AGENT_D` | A | 3.2.2 agent DN/CN receipts (UPD_DN.PRG join) (F-24) | 2/1363/216/2672/1 |
| `AP` | A | 3.2.3 Payment A/P - Vendor (UPD_OC.PRG join) (F-25) | 8/23802/5747/10453/170 |
| `AP_R` | A | 3.2.3 Payment A/P - Vendor (UPD_OC.PRG join) (F-25) | 135/64436/13946/74936/697 |
| `AP_AGENT` | A | 3.2.3 Payment A/P - Agent (F-25) | 1/640/103/737/0 |
| `AP_AGENT_D` | A | 3.2.3 Payment A/P - Agent (F-25) | 1/3557/255/4019/0 |
| `BAYAR` | A | 3.2.3 Payment - Cash/Bank (F-25) | 13/14033/4271/14149/55 |
| `BAYAR_R` | A | 3.2.3 Payment - Cash/Bank (F-25) | 161/161/5957/17776/156 |
| `TERIMA` | A | 3.2.2 Receipt - Cash/Bank (F-24) | 6/4381/1516/4640/44 |
| `TERIMA_R` | A | 3.2.2 Receipt - Cash/Bank (F-24) | 94/4813/2024/5406/142 |
| `CLEARING_D` | A* | 3.2.2/3.2.3 cash-vs-bank journal detail (F-24, F-25) | 4/4/4/0/4 |
| `RC` | A | 3.2.3 refund-cost receipts (F-25) | 1/267/74/386/0 |
| `RC_D` | A | 3.2.3 refund-cost receipts (F-25) | 35/750/212/3369/34 |
| `RCREQ` | A | 3.2.3 Payment A/P - Refund Cost; 3.2.8 request listing (F-25, F-30) | 0/487/123/487/0 |
| `RCREQ_D` | A | 3.2.3 Payment A/P - Refund Cost; 3.2.8 request listing (F-25, F-30) | 6/1956/246/3608/6 |
| `SI` | A* | 3.2.2/3.2.3 intraship A/R, named by the receipt/payment item (F-31) | 110/1067/1136/3380/50 |
| `SI_D` | A* | 3.2.2/3.2.3 intraship A/R, named by the receipt/payment item (F-31) | 421/1392/1149/3485/362 |
| `QUOTATION` | A | 3.2.6 Rate by Destination (F-28) | 62/62/0/0/62 |
| `QUOTATION_R` | A | 3.2.6 Rate by Destination (F-28) | 633/633/0/0/633 |
| `VENDOR` | A | 3.2.6 Rate by Destination (vendor); 3.2.7 By Shipping Lines (F-29) | 921/1069/961/880/935 |
| `VENDOR_D` | A | 3.2.6 Rate by Destination vendor tariff (F-28) | 2623/2688/2672/2136/2661 |
| `MANIFEST` | A | 3.2.1 Projects/Jobs - Detail manifest (F-23) | 104/243/209/1087/104 |
| `COST` | A | 3.2.8 Recurring Costs (F-30) - dictionary only, holds no rates (2.8) | 272/524/335/486/284 |
| `SAGING` | A | 3.2.2/3.2.3 bucket boundaries for all four aging rows (F-24, F-25) | 1/1/1/1/1 |
| `SOA` | B | 3.2.5 Statement of Account - archive, derived source (F-27) | 0/1/0/1/0 |
| `SOA_D` | B | 3.2.5 Statement of Account - archive, derived source (F-27) | 0/2/0/3/0 |
| `AR_CONTRA` | C | 3.2.2 Contra Bond - In (availability answer, not an ingest) | 0/0/0/0/0 |
| `AR_CONTRA_R` | C | 3.2.2 Contra Bond - In (availability answer, not an ingest) | 0/0/0/0/0 |
| `AP_CONTRA` | C | 3.2.3 Contra Bond - Out (availability answer, not an ingest) | 0/0/0/0/0 |
| `AP_CONTRA_R` | C | 3.2.3 Contra Bond - Out (availability answer, not an ingest) | 0/0/0/0/0 |
| `NA_D` | D | which legacy screen reads NA_D (5.1: it is not OPRCOST_R) | 29/102181/14459/76192/395 |
| `CARGO_R` | D | 3.2.1 Projects/Jobs - Detail container lines (F-23) | 18/50/49/1489/18 |
| `NA_R` | D | 3.2.1 Projects/Jobs - Detail container lines (F-23) | 9/1307/289/2866/44 |
| `PERMOHONAN_E` | D | 3.2.8 Petty Cash Request Listing (CSM/MAL only) (F-30) | 0/0/324/423/0 |
| `CRDRNOTE` | D | 3.2 debit/credit-note journalism (F-31) - no 3.2 row binds it yet | 0/0/0/29/0 |
| `CRDRNOTE_R` | D | 3.2 debit/credit-note journalism (F-31) - no 3.2 row binds it yet | 102/102/0/600/102 |
| `CRDRNOTE_S` | D | 3.2 debit/credit-note journalism (F-31) - no 3.2 row binds it yet | 181/181/0/46/181 |

- Tables empty in every company (Tier C): **34**
- Contra tables (`AR_CONTRA` 0/0/0/0/0, `AR_CONTRA_R` 0/0/0/0/0, `AP_CONTRA` 0/0/0/0/0, `AP_CONTRA_R` 0/0/0/0/0): all zero in all five = **True**
<!-- q11-numbers:end -->

Read the gaps in the count column before choosing: `QUOTATION`/`QUOTATION_R` are **0 in CSM and MAL** (the rate page would render empty there), `RCREQ` is 0 in CME and SBY (§5 item 6), `PERMOHONAN_E` exists only in CSM/MAL, and several receipt/payment tables are near-empty in SBY — the ingest stays company-neutral, but expectations of what each page will show should be set per company.

Tier B is not an ingest question at all: Balance Sheet / Income Statement / TB-BS+IS (§3.2.4) come from `ACCOUNT.TYPE/GROUP/SLD_NORMAL`, already in the 18; Statement of Account (§3.2.5) and the three Performance Tracker variants (§3.2.7) are joins over tables already synced **once the A/R receipt journal exists** — which is why they sit behind Q1.

## Q1 — which tables join the 18-table whitelist?

- **Option A (recommended): accept Tier A — 26 tables.** The 23 tables §4 names plus the three `A*` tables the blocked report items cite (`CLEARING_D`, `SI`, `SI_D`). This unblocks the receipt/payment, aging, rate-by-destination and Statement-of-Account items in one ingest, and lands every table that a §3.2 row demonstrably reads.
- **Option B: minimum slice — `SAGING` alone (1 row).** Unblocks the four aging pages only; everything else stays blocked. Pick this if you want the smallest change surface first.
- **Option C: no ingest now.** Then only the aging pages may proceed, and only via **Q1c** below. Everything else remains blocked.
- **Q1b: `COST` and the `CRDRNOTE` family.** `COST` is a cost-*type dictionary* whose rate columns are empty in all five companies (§2.8), and `CRDRNOTE*` is named by `F-31` but bound to no §3.2 row — recommend accepting both as **considered and declined for now** (ingest them later only if a report actually reads them), so the record distinguishes "declined" from "never considered".
- **Q1c — no-ingest alternative for the aging pages.** If you pick Option C, approve instead a single constants module carrying the bucket boundaries `1/30/31/60/61/90/90` with a citation to `SAGING` and the file that proves them — acceptable because `SAGING` is configuration, not transaction data; hardcoded inline literals in page code remain unacceptable either way.

## Q2 — is F-31 parity, or new scope? **Recommendation: new scope.**

Parity here is measured side-by-side against the Python app ([`docs/parity-checklist.yaml`](../parity-checklist.yaml), whose machine baseline is `tests/parity/baseline/python.json`). The Python app never read any second-layer table, so there is no baseline to compare against — declaring parity would require teaching the fallback app to read tables it was never ported for, or waiving rows the method cannot check. **Consequence for §8:** the P6 gate ("parity checklist 100% + SC-001 pentest") is unchanged and is *not* held open by this work; the second-layer reports become post-cutover feature scope, with PRD `F-31`'s wording moved from "pending owner decision" to "new scope" through the PRD-edit item. Choosing "parity" instead means rewriting the §8 gate and the P5 baseline contract — state it explicitly if that is what you want.

## Q3 — Contra Bond — In / Out: a data-availability answer, not an ingest

The legacy menu presents both labels, yet all four candidate tables (`AR_CONTRA(_R)`, `AP_CONTRA(_R)`) hold **0 rows in every company** (verified in the table above). So the answer is *"the current data cannot reproduce these two reports"*, not *"they never existed"*. For either to be reproducible, one of these must become true: a company tree where a contra table is populated, or a legacy artifact (report program, screenshot) showing which different source the packed program reads. Until then there is nothing to ingest, and `F-24`/`F-25` record this as an availability verdict.

## Q4 — legacy artifacts, and who verifies the rest

1. `TEMPLATE\VOLUME.DOC` and `TEMPLATE\LIFTING.DOC`, or a printed sample of either report — the only way to close the column spec for the blocked Volume/Lifting item; without them it closes as `unverified-locally` with your sign-off.
2. A named verifier (you, or whoever holds the legacy installation) for the Tier D questions: which screen reads `NA_D`; which `SELISIH` field "Discrepancy — Invoice" displays (four candidates all carry values — ranking by row count is not proof); whether `PERMOHONAN_E` (CSM/MAL only) is per-company; and the `OPRCOST_R` orphan policy for A/P aging (66 parent keys with no `OPRCOST` row, identical in all five companies).

## Cost of a "yes" — the change surface you are approving

1. **The whitelist is duplicated in both codebases by design**, and a half-landed addition is a silent parity break caught (at best) by the P3 byte-compare `tools/compare_sync_dbs.py`:
   - `tools/sync_db.py` → `_REQUIRED_TABLES` (line 40; env overrides `SYNC_TABLES` / `SYNC_ALL_TABLES`)
   - `laravel/app/Support/Dbf/SyncService.php` → `SyncService::DEFAULT_TABLES`, plus the mirror comment in `laravel/config/cmt.php` (`sync_tables`)
2. **Field signatures are not hand-maintained**: the sync derives each table's columns from the DBF field metadata (`SyncService::createSchema`, and `tools/schema.py` for the `UnifiedShipment` view, which Q-11 does not touch). The durable signature is therefore the committed snapshot `tests/parity/fixtures/real_schema.json`.
3. **That snapshot must be regenerated in the same change** (`python tools/dump_real_schema.py`), or the schema gates — `tests/test_schema_gate.py` and `laravel/tests/Feature/ProductionSchemaTest.php` — fail for the right reason and block the PR. The snapshot diff *is* the re-baseline review; read it.
4. **No queries, pages or write paths in this decision.** Each accepted tier spawns its own follow-up todo, per the accepted subset below.

## Sign-off (owner) — record your answer here, once

- **Date / answered by:** Arman
- **Q1:** Option A (26 tables) 
- **Q2:** F-31 = new scope (recommended) 
- **Q3:** Contra Bond = unavailable from current data (recommended) 
- **Q1:** Option A (26 tables) — Tier A's 23 tables (ARS, ARS_R, AP, AP_R, AP_AGENT, AP_AGENT_D, AR_AGENT, AR_AGENT_D, BAYAR, BAYAR_R, TERIMA, TERIMA_R, RC, RC_D, RCREQ, RCREQ_D, QUOTATION, QUOTATION_R, VENDOR, VENDOR_D, MANIFEST, COST, SAGING) plus the three `A*`/`Q1c` items cited by the blocked report items (`CLEARING_D`, `SI`, `SI_D`). 
- **Q2:** F-31 = new scope (recommended).
- **Q3:** Contra Bond = unavailable from current data (recommended); tables empty in all five, nothing to ingest.
- **Q4:** `TEMPLATE\VOLUME.DOC`/`LIFTING.DOC` unavailable → accept `unverified-locally` verdicts; named verifier: Arman (holds the legacy installation) for the Tier D verification questions.

**Rejected for now (considered, not forever):** `COST` and the `CRDRNOTE` family — conditionally accepted as Tier A ingest but marked "considered and declined for now"; ingest later only if a §3.2 report actually reads them. The rest of `A*`/`Q1c` is accepted, not declined, so the distinction is on record.

After sign-off this file becomes the decision record for Q-11/F-31 (exactly once, rejected tables named), PRD §10 `Q-11` and §4 `F-31` are amended through the PRD-edit todo, and the six blocked items are reopened against the accepted subset.
