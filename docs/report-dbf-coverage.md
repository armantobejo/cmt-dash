# Legacy report → DBF coverage matrix

**Purpose.** Decide Q-11 with evidence instead of archaeology: for every report in PRD §3.2, name
the DBF tables it needs, and say whether the current **18-table ingest** already serves it, whether
it needs the **second layer** (the non-ingested DBFs), whether it is **derivable** from tables we
already sync, or whether its candidate tables are **empty** and nothing can be built on them.

**Evidence basis.** Measured live from the real tree with `dbfread` 2.0.7: record counts, field
lists, parent-key linkage and column population per company, plus the legacy app's own menu and
program artifacts. No mapping below is asserted from memory; each row carries its evidence level
and the artifact it came from.

| Level | Meaning |
|---|---|
| `measured` | Read from `DBF/<company>/` in this pass — counts, fields, key linkage, populated columns. |
| `in-app-sql` | Confirmed by the as-built query layer or the `UnifiedShipment` view SQL. |
| `legacy-menu` | The report label is present in the app's own report menu table (`MENU2` / `MENU20`, PAD3 = REPORT). |
| `legacy-source` | The join or field is read out of a legacy program that ships in the tree (`UPD_*.PRG`). |
| `unverified-locally` | Candidate chosen by shape and population, but the report→table binding lives in the packed report program, which is not readable here. |

The former `inferred` level ("name/shape match only — must be confirmed against the legacy report
screen") is **retired**. §1.1 shows what legacy report evidence actually survives in the tree: the
report *menu* does, the report *programs* do not. Every row that used `inferred` now cites the menu,
a legacy program, or measured data — and where only the packed program could settle the binding,
the row says `unverified-locally` instead of guessing.

**Regenerate the evidence:**

```
python tools/dbf_inventory.py --json docs/report-dbf-coverage/inventory.json
python tools/dbf_inventory.py --fields SAGING QUOTATION_R ARS_R      # field lists on demand
python tools/legacy_report_evidence.py                               # menu + program + column evidence
python tools/legacy_report_evidence.py --check                       # gate this document
```

---

## 1.1 What legacy report evidence survives in the tree

The rows below were previously guessed from table shape because "the legacy report screen" was
treated as an unavailable artifact. It is partly available, and this is what it is.

**The report menu ships as data.** `MENU2` and `MENU20` each hold a 40-row `PAD3` (REPORT) block —
32 report labels plus 8 `*====` separators — and both are **identical in all five companies**. The
two menus differ from each other in exactly 2 labels: the `MENU20` variant appends "Agent"
(`Aging A/R - Debit Note Agent`, `Aging A/P - Credit Note Agent`). `MENU3` is the module registry
the menu dispatches on (168 action rows; APP `39` = `REQUEST-RC`). `MODULWORD` names the Word-template
report modules (`VOLUME_R` "REPORT VOLUME", `LIFTING_R` "REPORT LIFTING", `GROSSPROFIT` "REPORT GROSS
& PROFIT", `MANIFEST_P`, and the DR/CR note templates); `REPORT_LOV` is the print-format list.

**Three legacy programs ship with source.** `UPD_DN.PRG`, `UPD_INV.PRG` and `UPD_OC.PRG` (3 per
company, **byte-identical across companies**), and they carry the real joins:

| Program | Proc | Join read from the source | Legacy screen |
|---|---|---|---|
| `UPD_DN.PRG` | `CekTerima` | `AR_AGENT` INNER JOIN `AR_AGENT_D` ON `uar_agent`, WHERE `ar_agent_d.utrans = DrCrFull.Unik` | Receipt of an agent DN/CN |
| `UPD_DN.PRG` | `CekBayar` | `AP_AGENT_D` INNER JOIN `AP_AGENT` ON `uap_agent`, WHERE `utrans = pUnik` | Payment of an agent DN/CN |
| `UPD_INV.PRG` | `CekTerima` | `ARS` INNER JOIN `ARS_R` ON `uars`, WHERE `ars_r.k_invoice = Invoice.K_Invoice` | Receipt A/R — Invoice |
| `UPD_OC.PRG` | `CekBayar` | `AP_R` INNER JOIN `AP` ON `UAP`, WHERE `AP_R.Unik = pUnik` | Payment A/P — Vendor |
| `UPD_OC.PRG` | `CekDetil` | browses `OPRCOST_R` `for uoprcost = Oprcost.uoprcost` (the F3 detail of the Operational Cost screen). It does **not** reference `NA_D`. | Operational Cost detail |

**Counts are a snapshot, and the tree is live.** `DBF/` is written to while it is read: `NA_D` gained
exactly one record (file size +101 bytes, exactly one record at its 101-byte record length) and `NA`
gained one during a single session, **with their mtimes pinned at 10:38:58 throughout** — so mtime
cannot date a reading, and a count that shifts between readings is a different snapshot rather than a
Every figure in this document is therefore tied to a named snapshot,
`a710a16b13e0`, which `tools/legacy_report_evidence.py` records together with the file sizes and
mtimes it measured. All counts are **live rows** (record flag byte `0x20`); `NA_D` allocates
201 898 slots for 104 078 live rows — **97 807** slots empty and 13 flagged deleted — so a reader trusting the header's record count would roughly double the table.

**What is not in the tree.** There are **no `.FRX`/`.FRT` report layouts** (0 files) and the 28
`.exe`/`.APP` executables are **packed** — 0 printable strings in `INIT.exe`, ~0.37 in `34984.APP`.
So the report *program* that binds a menu label to a table cannot be read here. Anything only that
program could settle is marked `unverified-locally` with the missing artifact named; it is not
guessed, and it is not silently promoted to `measured`.

**The report menu, verbatim** — `MENU2` order, with the two `MENU20`-only labels appended in place as
`11v` / `18v`. This list is the authority for which report surfaces the legacy UI actually presents:

| # | Legacy report label | PRD §3.2 row |
|---|---|---|
| 1 | Volume | §3.2.9 — in scope, decided 2026-10-13 (see §6) |
| 2 | Lifting | §3.2.9 — in scope, decided 2026-10-13 (see §6) |
| 3 | Status B/L & Invoice | §3.2.1 Projects/Jobs Summary elements (Status of B/L, Invoice data) |
| 4 | Sales | §3.2.9 — in scope, satisfied by F-10 (see §6) |
| 6 | Operational Cost | §3.2.1 Detail elements |
| 7 | Refund Cost | **not enumerated** as a report (see §6) |
| 8 | Gross Profit & Loss | §3.2.9 — in scope, decided 2026-10-13 (see §6) |
| 10 | Aging A/R - Invoive | §3.2.2 Aging A/R — Invoice |
| 11 | Aging A/R - Debit Note | §3.2.2 |
| 11v | Aging A/R - Debit Note Agent | `MENU20`-only label for the same report → §3.2.2 |
| 12 | Contra Bond - In | §3.2.2 |
| 13 | Receipt A/R - Invoice | §3.2.2 |
| 14 | Discrepancy - Invoice | §3.2.2 |
| 15 | Receipt - Cash/ Bank | §3.2.2 |
| 17 | Aging A/P - Operational Cost | §3.2.3 |
| 18 | Aging A/P - Credit Note | §3.2.3 |
| 18v | Aging A/P - Credit Note Agent | `MENU20`-only label for the same report → §3.2.3 |
| 19 | Contra Bond - Out | §3.2.3 |
| 20 | Payment A/P - Vendor | §3.2.3 |
| 21 | Payment A/P - Agent | §3.2.3 |
| 22 | Payment A/P - Refund Cost | §3.2.3 |
| 23 | Payment - Cash/ Bank | §3.2.3 |
| 25 | Listing Journal | §3.2.4 |
| 26 | General Ledger | §3.2.4 |
| 27 | Trial Balance | §3.2.4 (PRD splits Mutation vs BS&IS) |
| 28 | Income Statement | §3.2.4 |
| 29 | Balance Sheet | §3.2.4 |
| 31 | Statement Of Account | §3.2.5 |
| 32 | List Agent | §3.2.6 Agent |
| 33 | List Shipper/ Forwarder | §3.2.6 Shipper |
| 34 | Rate By Destination | §3.2.6 |
| 35 | List Consignee/ Notify | §3.2.6 Consignee / Notify |
| 36 | List Account | §3.2.6 Account |
| 37 | Track Performance | §3.2.7 Performance Tracker |

Against the PRD's own wording the machine classification is now **31 matched by name, 1 near, 0 unmatched
candidates** — the single near case is the legacy typo `Aging A/R - Invoive`. The 10 candidates this
document originally reported split into 4 real gaps (`Volume`, `Lifting`, `Sales`, `Gross Profit & Loss`,
owned by PRD §3.2.9 since 2026-10-13) and 6 that were the same report under different words, now
cross-referenced in PRD §3.2.1, §3.2.6 and §3.2.7. §6 is the ledger of that reconciliation; the
classification itself is regenerated by `python tools/legacy_report_evidence.py` from the current
PRD text, so it cannot silently drift back to a stale claim.

---

## 1. The measured DBF surface

| Measure | Value |
|---|---|
| Companies | `CME`, `CMT`, `CSM`, `MAL`, `SBY` |
| Subdirectories | `DATA`, `INIT` |
| `.dbf` files per company | CME 148, CMT 149, CSM 149, MAL 149, SBY 151 |
| `DATA/` only, per company | CME/CMT/CSM/MAL 110, SBY 112 |
| Distinct table names (union) | 151 |
| Common to all five companies | 148 |
| **Empty in every company** | **34** |

**Not common to all five** — `DEFAAPP` (absent in CME) and two `JE.SYNC-CONFLICT-*` backups in SBY
(evidence that the sync has resolved JE conflicts by parking copies — worth a look separately).

**The 18 whitelist tables exist in all five companies.** `ACCOUNT`, `CARGO`, `NA`, `INVOICE`,
`INVOICE_R`, `JE`, `JE_D`, `OPRCOST`, `OPRCOST_R`, `DRCRFULL`, `DRCRFULL_R`, `SALDO_ACCT`,
`SHIPPER`, `CONSIGNEE`, `AGENT`, `VESSEL`, `SATUAN`, `USER` are all present and populated, so the
existing ingest is not blocked by a missing source file.

**34 tables are empty in every company** — including several that §1.2b currently lists as parity
candidates: `AR`, `AR_CONTRA`, `AR_CONTRA_R`, `AR_D`, `AP_CONTRA`, `AP_CONTRA_R`, `CLEARING`,
`PERMOHONAN`, `PERMOHONAN_D`, `SALDO_AGENT`, `SALDO_VENDOR`, `VENDOR_MAR_D`, `VENDOR_MAR_S`,
`ITEM`, `KURS`, `INVOICE_ILG`, `INVOICE_ILG_R`, `COMMODITY`, `PEB`, `GENDBF`, `GENCDX`, `SALDO`.
A report cannot be reproduced from these; they are vestigial in the current data.

**One table §1.2b names does not exist at all: `AR_R.DBF`.** It is absent from every company and
from `INIT/`. §1.2b, Q-11 and F-31 all name it.

---

## 2. The coverage matrix

Status legend: **`in-ingest`** source is in the 18 · **`second-layer`** needs a non-ingested DBF ·
**`derived`** computable from in-ingest tables · **`empty`** candidate exists but holds 0 rows
everywhere · **`unknown`** no candidate found.

### 2.1 Projects / Jobs — F-22 (Summary), F-23 (Detail)

| Report | Candidate DBF source(s) | Status | Evidence |
|---|---|---|---|
| Projects / Jobs — Summary (F-22) — B/L status, invoice, oprcost, DR/CN | `CARGO`, `NA`, `INVOICE`, `INVOICE_R`, `OPRCOST`, `OPRCOST_R`, `DRCRFULL`, `DRCRFULL_R` | `in-ingest` | `measured`, `unverified-locally` |
| Projects / Jobs — Detail (F-23) — Manifest data | `MANIFEST` (104–1087 rows; `NOMOR`,`DATE`,`VESSEL`,`CONTAINERN`,`ETD_1`,`ETA_1`) | **`second-layer`** | `measured` |
| Projects / Jobs — Detail (F-23) — Invoice line / Oprcost line / DR-CN line | `INVOICE_R`, `OPRCOST_R` (64 029 rows in CMT), `DRCRFULL_R` | `in-ingest` | `measured` |
| Projects / Jobs — Detail (F-23) — container / dimension lines | `CARGO_R` (18–1489), `NA_R` (9–2864) | **`second-layer`** (small) | `measured` |
| Projects / Jobs — Detail (F-23) — import cost lines | `NA_D` (104 078 lines in CMT under 9 728 `NA` parents; `JENISCOST`,`DESCRIPTIO`,`CURRENCY`,`QTY`,`UNIT`,`RATE`,`JUMLAH`) | **`second-layer`** — a real child of `NA`, **not** a duplicate of `OPRCOST_R` | `measured`, `legacy-source` |

**`NA_D` vs `OPRCOST_R` — resolved from the keys, not from shape.** The earlier concern was that
the two share a cost-line shape and might be redundant. They are not: `NA_D.UNIK` resolves to
`NA.UNIK` for **99.96%** of its 9 728 parents (4 orphans) in CMT, and its overlap with
`OPRCOST_R.UOPRCOST` = **0** — likewise `NA_D.UNIKR` against `OPRCOST_R.UNIK` = 0. So `NA_D` is the
cost line of an **import `NA`** record and `OPRCOST_R` is the cost line of an **`OPRCOST`** record
(`OPRCOST.EI` carries the export/import side); different parents, different rows, both needed.
`UPD_OC.PRG`'s F3 detail handler browses `OPRCOST_R for uoprcost = Oprcost.uoprcost` and never
references `NA_D`, so the Operational Cost screen is `OPRCOST_R`-served; which screen consumes
`NA_D` is `unverified-locally` (no readable report program). **`NA_D` is therefore a legitimate
`second-layer` candidate**, and Q-10's "F-22/F-23 covered by the 18 tables" is contradicted on
this point rather than merely unverified.

Two further measured notes on this pair. `NA_D` also **over-allocates**: its file declares
**201 898 record slots** for 104 078 live rows — **97 807** slots empty and 13 flagged deleted — so a
reader that trusted the header's record count would roughly double the table. Every count in this
document and in `inventory.json` is a **live-row** count. And `OPRCOST_R` carries **66 of 9560** parent keys with no
`OPRCOST` row in CMT — CME **66 of 71**, SBY **66 of 188**, CSM **3 of 2 507**, MAL **26 of 11 310** — copied baseline rows rather than company-specific damage; F-25's Aging A/P reads `OPRCOST_R` and must decide what to do with them. And CME carries only 3 `NA` rows against CMT's 9 746, so CME is a
near-empty company for the import side — do not read CME's counts as a small sample of CMT's.
(This paragraph is why: an earlier draft of this matrix measured the Performance Tracker candidates on the first company alphabetically, i.e. CME, and would have reported `INVOICE` as 104 rows instead of CMT's 10 794.)

### 2.2 Account Receivables — F-24

| Report | Candidate DBF source(s) | Status | Evidence |
|---|---|---|---|
| Aging A/R — Invoice | `INVOICE` (`BYR_USD`,`DIRPKAN`,`SELISIH_US`,`LUNAS`,`KB`) | `in-ingest` | `measured` |
| Aging A/R — Debit Note | `DRCRFULL`, `DRCRFULL_R` (`BAYAR`,`DIRPKAN`,`SELISIH`,`LUNAS`) | `in-ingest` | `measured` |
| **Aging bucket boundaries (both aging rows)** | `SAGING` — 1 row: `AGING1..7` = **1, 30, 31, 60, 61, 90, 90** | **`second-layer`** (1 row) | `measured` |
| Contra Bond — In | `AR_CONTRA`, `AR_CONTRA_R` | **`empty`** | `measured` (0 rows × 5) |
| Receipt A/R — Invoice | `ARS_R` (`K_INVOICE`,`NOINVOICE`,`BAYAR`,`SELISIH`; 516–20 381 rows) | **`second-layer`** | `measured` |
| Discrepancy — Invoice | `ARS_R.SELISIH` (2 098 of 13 200 non-zero in CMT), `INVOICE.SELISIH_US` (412 of 10 536) and `INVOICE.SELISIH_ID` (1 661 of 10 536), `AR_AGENT_D.SELISIH` (81 of 1 363), `DRCRFULL.SELISIH` (82 of 5 081) | `derived` — invoice-linked; 4 candidate fields, all carrying values | `measured`, `legacy-menu`, `unverified-locally` |
| Receipt — Cash/Bank | `ARS`, `TERIMA`, `TERIMA_R` (`JENIS_BYR`,`KD_BANK`,`NO_GBCEKTT`,`TGL_KLIRIN`) | **`second-layer`** | `measured` |

`SAGING` deserves emphasis: it holds **no report rows**, but its single row defines the aging
buckets (1–30 / 31–60 / 61–90 / 90+). Reproducing the aging reports without it means hard-coding
those boundaries — a silent parity risk if the legacy table ever changes. It is 1 row; ingest it.

### 2.3 Account Payables — F-25

| Report | Candidate DBF source(s) | Status | Evidence |
|---|---|---|---|
| Aging A/P — Operational Cost | `OPRCOST`, `OPRCOST_R` (`BAYAR`,`DIRPKAN`,`SELISIH`,`LUNAS`,`KD_SHIPLIN`) | `in-ingest` (+`SAGING`) | `measured` |
| Aging A/P — Credit Note | `DRCRFULL`, `DRCRFULL_R` | `in-ingest` (+`SAGING`) | `measured` |
| Contra Bond — Out | `AP_CONTRA`, `AP_CONTRA_R` | **`empty`** | `measured` (0 rows × 5) |
| Payment A/P — Vendor | `AP`, `AP_R` (`KD_SHIPLIN`,`TGL_JT`,`TOT_BAYAR`; `AP_R.INVOICE_NO`,`BAYAR`,`SISA_USD`) | **`second-layer`** | `measured` |
| Payment A/P — Agent | `AP_AGENT`, `AP_AGENT_D` (`AGENT`,`CODE`,`TOTBAYAR`) | **`second-layer`** | `measured` |
| Payment A/P — Refund Cost | `RC` (267 rows in CMT; `NO_REF`,`JENIS_BYR`,`KD_BANK`,`TGL_KLIRIN`,`TOT_BAYAR` — 260 of 267 with a non-zero `TOT_BAYAR`), `RC_D` (750 rows, 741 carrying `UINVOICE_R`) | **`second-layer`** — `RC` is a **payment header** (same column shape as `AP`/`ARS`/`BAYAR`/`TERIMA`/`AR_AGENT`), `RC_D` settles invoice lines | `measured`, `legacy-menu` |
| Payment — Cash/Bank | `BAYAR`, `BAYAR_R`, `TERIMA`, `TERIMA_R` (14007/161 rows in CMT) | **`second-layer`** | `measured` |

`AP`/`AP_R` volumes are large (CMT 23 782 / 64 389), so these are live tables, not vestiges.

### 2.4 Finance — F-26

| Report | Candidate DBF source(s) | Status | Evidence |
|---|---|---|---|
| Listing Journal | `JE`, `JE_D` | `in-ingest` | `in-app-sql` |
| General Ledger | `JE`, `JE_D`, `ACCOUNT` | `in-ingest` | `in-app-sql` |
| Trial Balance: Mutation | `JE`, `JE_D`, `ACCOUNT`, `SALDO_ACCT` | `in-ingest` | `in-app-sql` |
| **Trial Balance: Balance Sheet & Income Statement** | `ACCOUNT` (**`TYPE`, `GROUP`, `SLD_NORMAL`**) + `SALDO_ACCT` | **`derived`** — Q-10 gap resolved | `measured` |
| **Balance Sheet** | as above | **`derived`** | `measured` |
| **Income Statement** | as above | **`derived`** | `measured` |
| Other Finance Reports | — | `unknown` | catch-all bucket |

This corrects Q-10, which listed the balance-sheet/income-statement family as unverifiable.
`ACCOUNT` (3 047 rows in CMT) carries `TYPE`, `GROUP` and `SLD_NORMAL` (normal balance side), which
is exactly the classification needed to split accounts into balance-sheet vs income-statement.
No new table is required — the gap was a missing *query*, not missing *data*.

### 2.5 Statement of Account — F-27

| Report | Candidate DBF source(s) | Status | Evidence |
|---|---|---|---|
| Statement of Account | `SOA` (`ACCT_DR`,`ACCT_CR`,`TOT_DR`,`TOT_CR`), `SOA_D` (`INVOICE_NO`,`KD_ACCOUNT`,`DK`,`JUMLAH`) | `derived` — invoice-driven; `SOA` holds issued statements as an archive | `measured`, `legacy-menu` |

`SOA`/`SOA_D` exist in all five companies but hold almost nothing (CMT 1 `SOA` row, 2 `SOA_D` rows),
and the one statement that does exist is real: `SOA.REF_NO` = `*`, `TOT_DR`/`TOT_CR` populated, and
both `SOA_D` rows name the **same invoice** (`2401-I/00012/SHG/JK`, 2024), one debit and one credit.
So `SOA` is an **archive of statements that were issued**, keyed by invoice, not the working source —
the statement itself is **derived** from `INVOICE` + the A/R receipts, which makes F-27 a query gap,
not a data gap. The legacy report menu carries `Statement Of Account` (`legacy-menu`), so the report
surface is confirmed; that a 2024 statement sits in the table confirms the invoice→statement link.

### 2.6 Listing Reference — F-28

| Report | Candidate DBF source(s) | Status | Evidence |
|---|---|---|---|
| Agent | `AGENT` | `in-ingest` | `measured` |
| Shipper | `SHIPPER` | `in-ingest` | `measured` |
| Account | `ACCOUNT` | `in-ingest` | `measured` |
| Consignee / Notify | `CONSIGNEE` (+ `KD_NOTIFY`,`NOTIFYPART` on `CARGO`/`NA`) | `in-ingest` | `measured` |
| **Rate by Destination** | **`QUOTATION_R`** (`CODE_AGENT`,`KD_CITY`,`DESC_CITY`,`LCL`,`MIN`,`D20`,`D40`,`D40HQ`,`D45`; 633 rows CMT) + `QUOTATION` (62) | **`second-layer`** — Q-10 gap resolved | `measured` |
| Rate by Destination (vendor tariff) | `VENDOR_D` (`LCL`,`D20`,`D40`,`D40HC`,`D45`,`EXPIRED`,`TRANTIME`) | **`second-layer`** | `measured` |

This corrects Q-10's "no RATE/DEST table in the sync list". There is no table *named* RATE, but
`QUOTATION_R` is a rate-by-destination table: one row per agent × city with per-container-size
rates. `EXRATE` is **not** a destination rate table — it is currency exchange rates
(`CURRENCY`,`TANGGAL`,`EXRATE`, 268–281 rows). `VENDOR_D` is the vendor-side tariff equivalent.

### 2.7 Performance Tracker — F-29

| Report | Candidate DBF source(s) | Status | Evidence |
|---|---|---|---|
| Performance Tracker — By Shipper | `INVOICE` (`SHIPPER` filled 10 794/10 794 — note `INVOICE` has **no** `KD_SHIPPER`, only the name), `SHIPPER`, `NA.SHIPPER` | `derived` | `measured`, `legacy-menu` |
| Performance Tracker — By Shipping Lines | `VENDOR` (`KD_SHIPLIN` 1 075/1 075, `NAME` 1 062 — the shipping-line master) or `VESSEL.NM_VESSEL` (5 069/5 081) | **`second-layer`** for the vendor master / `derived` from `VESSEL` | `measured`, `legacy-menu` |
| Performance Tracker — By Agent | `CARGO.AGENT` (537 of 1 102 filled) + `NA.AGENT` (4 624 of 9 746) + `AGENT` | `derived` — key is sparsely populated on the export side | `measured`, `legacy-menu` |
| Performance Tracker — By Consignee (Import only) | `NA.CONSIGNEE` (9 715/9 746), `CONSIGNEE` | `derived` | `measured`, `legacy-menu` |

Q-10 recorded "no shipping-line master beyond VESSEL". `VENDOR` is that master
(`KD_SHIPLIN` = shipping-line code, `NAME`); it is outside the 18 but populated in all five.

### 2.8 Miscellaneous — F-30

| Report | Candidate DBF source(s) | Status | Evidence |
|---|---|---|---|
| Petty Cash Request Listing | `PERMOHONAN` (27 fields) / `PERMOHONAN_E` | **`empty`** (PERMOHONAN_E: CSM 318, MAL 403 only) | `measured` |
| Refund Cost Request Listing | `RCREQ` (CMT 486 rows; `APPLICANT` 483, `TOT_IDR` non-zero 482), `RCREQ_D` (1 954 rows; `PROFITLOSS` non-zero 1 935, `UINVOICE_R` 1 952) | **`second-layer`** — request header + per-invoice detail. `RCREQ` is **empty in CME** (0 rows) | `measured`, `legacy-menu` |
| Recurring Costs | `COST` (`JENISCOST`,`CURRENCY`,`COST`,`SELLING`,`GROUP`; 272–524 rows) | **`unknown`** — the table is a cost-type *dictionary*, not a rate table | `measured`, `legacy-menu` |

**`COST` holds no rates in any company.** Across all five companies (272–524 rows each) the count is **0 non-zero `COST` cells and 0 non-zero `SELLING` cells**; each company has exactly 2 `COST` cells that are
non-blank yet parse to zero, `JENISCOST` is filled in every row, and `GROUP` in 2 rows. Counting
*non-blank* cells instead of non-zero ones is what made an earlier draft of this paragraph claim
`COST` = 0 outright — the two measures disagree, and the non-zero one is the claim that matters. So
`COST` is a dictionary of cost *type names*, not the
recurring-cost rate table the report needs, and the legacy report menu has no `Recurring Costs`
label at all (§1.1). That combination is why this row is `unknown` rather than `second-layer`:
reproducing "Recurring Costs" requires a source this evidence has not found, and F-30 should not be
budgeted on `COST`.

---

## 3. Corrections this evidence forces on the PRD

These are factual corrections to the data-scope record, not stylistic ones. They belong in the
reopened `prd-data-scope-two-dbf-layers` todo, not edited in silently.

| Location | Currently says | Measured |
|---|---|---|
| §1.2b, §3, §1.4, Q-11, F-31 | "~87 DBF files per company" | **110** in `DATA/` (SBY 112); **148–151** including `INIT/`; union 151 |
| §1.2b, Q-11, F-31 | lists `AR_R.DBF` | **does not exist in any company** |
| §1.2b list | omits `SOA`, `SOA_D`, `SAGING`, `TERIMA`, `TERIMA_R`, `SALDO_VENDOR`, `SALDO_AGENT`, `QUOTATION`, `QUOTATION_R`, `COST`, `MANIFEST`, `CARGO_R`, `NA_R`, `NA_D`, `VENDOR_D`, `RC`, `RC_D`, `RCREQ`, `RCREQ_D` | all present; the report-bearing set above |
| §1.2b / Q-11 candidate lists | present `AR`, `AR_CONTRA(_R)`, `AR_D`, `AP_CONTRA(_R)`, `CLEARING`, `PERMOHONAN`, `SALDO_AGENT`, `SALDO_VENDOR`, `VENDOR_MAR_*`, `ITEM`, `KURS`, `INVOICE_ILG(_R)`, `PEB`, `GENDBF`, `GENCDX` as candidates | **0 rows in all five** — mark vestigial, not candidates |
| §3.2.8 Recurring Costs | implies `COST` as the source | `COST.COST` and `COST.SELLING` are **empty in all five companies**; `COST` is a cost-type dictionary |
| §3.2 as a whole | presented as "the report surface management requires" | the legacy report menu presented **4 report labels §3.2 never enumerated** (`Volume`, `Lifting`, `Sales`, `Gross Profit & Loss`) plus 3 it names differently — reconciled 2026-10-13: the four are now owned §3.2.9 rows, the renamed ones are cross-referenced in §3.2.1/§3.2.6/§3.2.7, and the machine classification moved from 21 to 31 matched (§6) |

## 4. Recommended Q-11 split (for the owner)

**Tier A — ingest candidates, because a §3.2 report needs them and they hold data:**

`ARS`, `ARS_R`, `AP`, `AP_R`, `AP_AGENT`, `AP_AGENT_D`, `AR_AGENT`, `AR_AGENT_D`, `BAYAR`,
`BAYAR_R`, `TERIMA`, `TERIMA_R`, `RC`, `RC_D`, `RCREQ`, `RCREQ_D`, `QUOTATION`, `QUOTATION_R`,
`VENDOR`, `VENDOR_D`, `MANIFEST`, `COST`, **`SAGING`** (1 row, defines aging buckets).

**Tier B — derivable from Tier A + the 18, no new table:** Balance Sheet / Income Statement /
TB-BS+IS (via `ACCOUNT.TYPE/GROUP/SLD_NORMAL`), Statement of Account, Performance Tracker by
Shipper/Agent/Consignee.

**Tier C — drop as vestigial (empty in all five):** the 34 tables in §1; explicitly
`AR_CONTRA(_R)` and `AP_CONTRA(_R)` mean **Contra Bond — In/Out cannot be reproduced from current
data at all** — that is a data-availability answer for F-24/F-25, not an ingestion task.

**Tier D — verify before deciding:** `NA_D` (vs `OPRCOST_R`), `CARGO_R`/`NA_R` (container lines,
small counts), `PERMOHONAN_E` (CSM/MAL only), Discrepancy — Invoice.

## 5. Open verification items

Each item now states what closed it, or which artifact would. "Unreadable locally" means the answer
lives in the packed report program (no `.FRX`, packed `.exe`/`.APP` — §1.1).

1. ~~`NA_D` vs `OPRCOST_R` — which one feeds the legacy Detail screen for imports.~~ **Closed by
data.** `NA_D` is `NA`'s cost child (`NA_D.UNIK` resolves to `NA.UNIK` for 99.96% of parents,
overlap with `OPRCOST_R.UOPRCOST` = 0) and `OPRCOST_R` is `OPRCOST`'s child; they are not
redundant. Residual, `unverified-locally`: which screen renders `NA_D`.
2. Contra Bond — In/Out. The tables (`AR_CONTRA(_R)`, `AP_CONTRA(_R)`) are empty in all five, **but
the legacy report menu carries both `Contra Bond - In` and `Contra Bond - Out`** (`legacy-menu`), so
the reports were presented. That sharpens rather than answers the question: the legacy app read
something else, or the data was never migrated in. Owner/screen answer required.
3. Statement of Account — **closed.** Derived from `INVOICE` + A/R receipts; `SOA` is the archive of
issued statements (§2.5), confirmed by the 2024 `SOA_D` invoice link.
4. Discrepancy — Invoice: four candidate fields all carry values (`ARS_R.SELISIH` 2 098,
`INVOICE.SELISIH_US` 412, `AR_AGENT_D.SELISIH` 81, `DRCRFULL.SELISIH` 82 in CMT). Which one the
screen shows is `unverified-locally`; the ranking above is by volume, not by proof.
5. `PERMOHONAN` empty in all five while `PERMOHONAN_E` has CSM/MAL data. The legacy menu has
`Request - Petty Cash` and `Request - Refund Cost` as *entry* modules (PAD2 38/39, `legacy-menu`),
not as report labels, so "Petty Cash Request Listing" / "Refund Cost Request Listing" in §3.2.8 are
the PRD's naming for printed views of those screens. Whether the petty-cash listing is a per-company
feature is still an owner question; the Refund Cost one is served by `RCREQ`/`RCREQ_D` (§2.8).
6. `RCREQ` is empty in CME (0 rows) while holding 486 in CMT — confirm that the refund-cost request
flow is simply unused in CME before wiring it as a company-independent page.
7. `OPRCOST_R` carries 66 of 9333 parent keys with no `OPRCOST` row, the same 66 in every company;
Aging A/P reads `OPRCOST_R`, so decide whether those rows are reported or excluded.
8. The two `JE.SYNC-CONFLICT-*` files in SBY — sync conflict handling is worth its own look.

---

## 6. Report surfaces the legacy menu has, reconciled against PRD §3.2

§1.1 lists the 32 labels the app's report menu actually presents. Classified against §3.2's own
wording the machine result is now **31 matched by name, 1 near, 0 unmatched candidates**; the single
near case is the legacy typo `Aging A/R - Invoive`. The 10 unmatched candidates this section started
from split into 6 that were the same report under different words, and the **4 genuine omissions**
below — which the owner delegated back for a decision on 2026-10-13. Both halves are resolved; the
table is now the ledger of that decision rather than an open gap list:

| Legacy label | Verdict |
|---|---|
| `Volume` | **In scope — PRD §3.2.9** (decided 2026-10-13) — menu row 1; `MODULWORD.VOLUME_R` ("REPORT VOLUME") shows it also emits a Word document, so it is a report output. In scope at *enumeration* level: the column set is `unverified-locally`. |
| `Lifting` | **In scope — PRD §3.2.9** (decided 2026-10-13) — menu row 2; `MODULWORD.LIFTING_R` ("REPORT LIFTING"). In scope at *enumeration* level: the column set is `unverified-locally`. |
| `Sales` | **In scope — PRD §3.2.9** (decided 2026-10-13) — menu row 4, no `MODULWORD` template, but the as-built app already renders it as the Sales Dashboard (F-10), so it needs no new page — only its row, so the label cannot read as dropped. |
| `Gross Profit & Loss` | **In scope — PRD §3.2.9** (decided 2026-10-13) — menu row 8; `MODULWORD.GROSSPROFIT` ("REPORT GROSS & PROFIT") writes `TEMPLATE\LOSSPROFIT.DOC` over a date pair, and the figure is `INVOICE` minus `OPRCOST`, both already in the 18-table ingest: a missing query, not missing data. |
| `Status B/L & Invoice` | same surface — §3.2.1 Summary elements ("Status of B/L", "Invoice data"), not a standalone row |
| `Operational Cost`, `Refund Cost` | the menu's own two report entries; §3.2 mentions "Operational Cost data" only as a Detail element |
| `List Agent`, `List Shipper/ Forwarder`, `List Consignee/ Notify`, `List Account` | same surface — these *are* the §3.2.6 rows (Agent / Shipper / Consignee / Account); only the label differs |
| `Track Performance` | same surface — §3.2.7 expands it into four grouped rows |

**Decided 2026-10-13, owner-delegated: all four are in scope**, written into PRD §3.2.9 with the
reason per report — `Sales` is already covered by the as-built Sales Dashboard (F-10);
`Gross Profit & Loss` is derived from two tables the 18-table ingest already carries; `Volume` and
`Lifting` are accepted at *enumeration* level only, because their column set stays
`unverified-locally` (no `TEMPLATE\*.DOC` ships in this tree and the report programs are packed), so
either page needs a legacy template or screenshot before it can be specified. Decision record:
`kanban/` item `reconcile-report-list-with-legacy-menu`.