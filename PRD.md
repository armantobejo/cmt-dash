# PRD — CMT Dashboard: Laravel Rebuild

| | |
|---|---|
| **Status** | Approved for planning — 2026-09-22 |
| **Product** | CMT logistics dashboard (read-only over legacy FoxPro DBF) |
| **Rebuild decisions** | Laravel rebuild · full feature parity · Blade + Bootstrap + SQLite · parallel run → cutover (owner decisions, 2026-09-22) |

**Related documents** (sources of truth that this PRD defers to, does not replace):
[`DESIGN.md`](DESIGN.md) — UI law · [`docs/srs.md`](docs/srs.md) — hardening requirements ·
[`docs/information_architecture.md`](docs/information_architecture.md) — IA (partially stale, see §1.4) ·
[`docs/data_model.md`](docs/data_model.md) — data model · [`docs/security-baseline.md`](docs/security-baseline.md) — security posture ·
[`CHANGELOG.md`](CHANGELOG.md) — as-built feature record · [`README.md`](README.md) — ops & env vars

---

## 1. Product overview

CMT is a freight-forwarding company whose day-to-day operations run on **five legacy FoxPro
(DBF) company databases**, actively written by the legacy application. The dashboard is a
**read-only, modern web front-end** over those DBF files: it syncs them into SQLite and serves
dense, finance-grade tables to operations, finance, and management staff.

The creative north star is unchanged from [`DESIGN.md`](DESIGN.md): **a quiet, dense ledger —
the interface disappears and the numbers stay sharp.**

### 1.1 Why rebuild in Laravel

- **Owner stack preference**: the owner wants to maintain the product in PHP/Laravel going forward.
- **Full feature parity first**: the current app (FastAPI) is the functional spec; nothing new
  is added in the rebuild — parity is the scope.
- **Fidelity constraints carry over**: the atomic-sync integrity guarantees, the read-only
  surface, the 5-role RBAC model, and the DESIGN.md system all carry over unchanged.

### 1.2 (DECIDED) Frontend framework — Filament adopted as the view layer

(Detailed in §3.2 and tracked in Q-09.)
**Decision**: the rebuild adopts [Filament](https://filamentphp.com/) as the admin panel / view
layer, replacing the CoreUI + Bootstrap + hand-authored Blade templates that the PAR-2026-09-22
PRD prescribed. **DESIGN.md remains binding for color, type, spacing, motion, and the
"interface disappears, numbers stay sharp" aesthetic** — Filament is the wiring and component
infrastructure, not a free pass to re-theme the system. Concretely:

- **What changed**: Blade template authoring → Filament resources/pages/**tables**. The app is
  still Laravel MVC; Filament sits on top of the same controllers/services/query classes, with
  the raw DBF-reflected SQLite schema underneath (no migrations on business tables, same as
  before).
- **What did not change**: the 5-role RBAC model, the read-only guarantee (F-21), the atomic-sync
  contract (FR-007), the money/currency/EXRATE rendering discipline, the i18n requirement (all
  strings through `__()`), the flat dark aesthetic, the no-animation-beyond-120ms rule, and the
  parity-first scope (nothing new added — Filament is a different way to render the same parity
  surface).
- **Why**: it gives a maintainable, typed admin scaffolding with built-in tables, filters, actions,
  and pagination without hand-rolling every grid — while we still own the design tokens and the
  parity contract. The FastAPI app is still the functional spec; Filament pages are verified
  side-by-side against it during parallel run (§8).

### 1.2b Data scope — two DBF layers (as-built discovery, 2026-10-13)
The 18-table sync whitelist (§3, Q-10) is the management-scope ingest. It is **not** every DBF file the legacy app maintains. Measured from the live tree with `tools/dbf_inventory.py` (dbfread 2.0.7, header-only reads; per-report mapping in [`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md)):

- Each company directory under `DBF_DIR` (`DBF/CMT/`, `DBF/CME/`, `DBF/CSM/`, `DBF/MAL/`, `DBF/SBY/`) holds **110 DBF files in `DATA/`** (SBY 112) and **148–151 in total** across `DATA/` + `INIT/`. Union across the five companies: **151 names, 148 common to all five** — the exceptions are `DEFAAPP` (absent in CME) and two `JE.SYNC-CONFLICT-*` backups in SBY.
- **All 18 whitelist tables are present and populated in all five companies**, so the current ingest is not blocked by a missing source file.

Report-bearing tables outside the 18 (each populated — the ones a §3.2 report actually needs):

- **AR/AP journalism layer** — `ARS.DBF`, `ARS_R.DBF`, `AR_AGENT.DBF`, `AR_AGENT_D.DBF`; `AP.DBF`, `AP_R.DBF`, `AP_AGENT.DBF`, `AP_AGENT_D.DBF`. These carry the A/R and A/P journal entries that the management receivables/payables reports render on screen (receipts, vendor/agent payments, and per-invoice allocation via the `_R` line tables). `AR.DBF`, `AR_D.DBF`, `AR_CONTRA.DBF`, `AR_CONTRA_R.DBF`, `AP_CONTRA.DBF` and `AP_CONTRA_R.DBF` exist too but are empty in all five companies — see the vestigial list below.
- **Bank / clearing / receipt journal** — `BAYAR.DBF`, `BAYAR_R.DBF`, `TERIMA.DBF`, `TERIMA_R.DBF`, `CLEARING_D.DBF` (populated), `SI.DBF`, `SI_D.DBF` (intraship received). `CLEARING.DBF` itself is empty in all five companies (vestigial list below).
- **Aging bucket configuration** — `SAGING.DBF` is a single row, `AGING1..7` = 1, 30, 31, 60, 61, 90, 90, defining the 1–30 / 31–60 / 61–90 / 90+ buckets. It holds no report rows, but the aging reports (F-24/F-25) cannot be reproduced faithfully without it.
- **Rate data** — `QUOTATION.DBF` + `QUOTATION_R.DBF` (agent × destination per-container rates: `KD_CITY`, `LCL`, `MIN`, `D20`, `D40`, `D40HQ`, `D45`) and `VENDOR_D.DBF` (vendor tariff). This is the source behind "Rate by Destination" (F-28). `EXRATE.DBF` is **not** a destination rate table — it is currency exchange rates.
- **Statement of account** — `SOA.DBF`, `SOA_D.DBF`, populated in CMT/MAL only (1–3 rows each), so F-27 is more likely derived from invoices plus A/R receipts than read from these.
- **Debit/Credit note journalism** — `CRDRNOTE.DBF`, `CRDRNOTE_R.DBF`, `CRDRNOTE_S.DBF` — ship-finance note detail (incentive/collection), **not** the DR/CN FCL/LCL split referenced in §3.2.1, which is `DRCRFULL`/`DRCRFULL_R` and already in the 18.
- **Reference / master and request data** — `VENDOR.DBF`, `VENDOR_D.DBF`, `PELABUHAN.DBF`, `CITY.DBF`, `MANIFEST.DBF`, `NUMBER.DBF`, `COST.DBF`, `CARGO_R.DBF`, `NA_R.DBF`, `NA_D.DBF`, `RC.DBF`, `RC_D.DBF`, `RCREQ.DBF`, `RCREQ_D.DBF`, `PERMOHONAN_E.DBF` (CSM/MAL only), plus `SATUAN.DBF`, `CONSIGNEE.DBF`, `SHIPPER.DBF` and `VESSEL.DBF` (already in the 18).

**Vestigial — present but empty in all five companies (34 tables).** These must not be treated as parity candidates: `AP_CONTRA`, `AP_CONTRA_R`, `AR`, `AR_CONTRA`, `AR_CONTRA_R`, `AR_D`, `BAL_COBA`, `CLEARING`, `CMEMO`, `CMEMO2`, `COMMODITY`, `CSI_SC`, `CURLIFT`, `GENCDX`, `GENDBF`, `INCOME6`, `INVOICE_ILG`, `INVOICE_ILG_R`, `ITEM`, `KURS`, `MANICONT`, `MERGE`, `PEB`, `PERMOHONAN`, `PERMOHONAN_D`, `P_DRCRNOTE`, `Q_SDETAIL`, `SALDO`, `SALDO_AGENT`, `SALDO_VENDOR`, `TBALANCE`, `TJE_D`, `VENDOR_MAR_D`, `VENDOR_MAR_S`. Two consequences worth stating plainly: **Contra Bond — In/Out (F-24/F-25) cannot be reproduced from current data at all**, and `SALDO_VENDOR`/`SALDO_AGENT` are not viable aging sources.

**Decision rule**: a DBF is in parity scope if and only if a management screen / report in the legacy UI actually renders it. The table inventory, record counts and field lists behind it are reproducible with `python tools/dbf_inventory.py --json docs/report-dbf-coverage/inventory.json`, and the per-report mapping is in [`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md). The gap list in Q-10 (management reports whose 18-table source is missing) and Q-11 (the second-layer DBFs above) are the two distinct out-of-scope buckets — they are not the same question.
### 1.3 Non-goals (unchanged from the original product)

- **No write/edit features** on business data — the dashboard never modifies DBF or synced data
  (per the original product concept; the only writable tables remain `users`/`sessions`, plus
  the Laravel equivalents). **The `POST /sync` trigger endpoint is exempt**: it initiates the
  background DBF→SQLite sync job (a backend batch process), not a business-data form; the sync
  itself writes business tables via the `dbf:sync` command / `SyncService`, not the frontend.
- **No replacement of the legacy FoxPro system.**
- **No new frontend framework** — CoreUI-style Bootstrap markup is preserved in Blade (DESIGN.md styling is binding; the admin-panel layer is Filament; the app is still Laravel MVC underneath).
- No Livewire or Inertia as **additional** frontend frameworks. No caching layer. No multi-tenancy. (Filament is now the view layer — see §1.2; its own Livewire dependency is not an additional framework and is not a non-goal.)
- No TLS in the app; production fronts it with a reverse proxy (`CMT_COOKIE_SECURE`/`SESSION_SECURE_COOKIE` behind TLS).

### 1.4 Current state (verified from code, 2026-09-22)

The FastAPI app has grown well past the hardening SRS. As-built today:

- **User management UI** exists (list/create/edit/delete, admin/superadmin) — the SRS listed it
  as future work; it is now in scope for parity.
- **General Ledger** with journal drill-down, **Sales Dashboard**, and **Data Quality
  (superadmin)** pages exist and are part of parity.
- **Shipments page** is unified with a `type` filter (I/E/other) and invoice-link filter —
  the separate `/imports`, `/exports`, `/vessels` routes from the original IA are **gone**.
- **Multi-company DB switcher** (cookie `cmt_db` + middleware) switches the active company DB per request.
- Sync ingests **the 18 management-required tables** (not all DBF files — each company has 110 in `DATA/`), preserves `users`/`sessions` across the
  atomic swap, and runs as a subprocess with `SYNC_TIMEOUT_SECONDS` (600s default). The 18-table whitelist is the scope-of-parity ingest; the remaining tables per company (110 in `DATA/`, 148–151 with `INIT/`; 34 of them empty in every company) hold ship-finance, bank/clearing, A/R–A/P, intraship, reference, and exercise/master data that is out of Filament parity scope pending owner decisions (Q-10, Q-11) — see §1.2b and [`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md).

---

## 2. Users & roles

The 5-role hierarchy carries over verbatim. `customer` data segregation is enforced at query level.

| Role | Level | Access |
|---|---|---|
| `superadmin` | 5 | Everything, including user deletion and Data Quality |
| `admin` | 4 | Everything except user deletion (creates/edits users, no Data Quality) |
| `management` | 3 | All data + trigger sync (`POST /sync`) |
| `staff` | 2 | View all data, no sync |
| `customer` | 1 | Only own company's shipments/invoices via `customer_company` filter |

**Data segregation rule**: a `customer`-role user is filtered to their `customer_company`
value in every query they can reach (shipments, invoices, dashboard, sales, ledger). Staff and
above see all data of the **active company DB**; the DB switcher is visible to all roles but
only changes which company DB is read, never widens role limits.

---

## 3. Architecture & data flow (target)

```text
legacy FoxPro app ──realtime writes──▶ DBF/  (source of truth, read-only)
                                             │
                        php artisan dbf:sync (cron / compose one-shot service)
                                             ▼
                                  data/*.db  (SQLite, per company + default)
                                             │
              Laravel (routes → controllers → Blade)  [read-only over business data]
                                             ▼
                                        Browser
```

- **Laravel app**: classic MVC — routes, controllers, Blade templates, no ORM writes to
  business data. Business tables are read with DB::select / query builder against the existing
  column-oriented schema (DBF column names are uppercase and preserved; Eloquent models are
  used only for `users`/`sessions`-class app tables). Because the SQLite schema is a direct
  reflection of the DBF files, **no Laravel migrations create or alter business tables** —
  the sync process owns them.
- **Sync command**: `php artisan dbf:sync` replaces `tools/sync_db.py`. Same guarantees:
  1. 18-table management ingest (list carried over verbatim): CARGO, NA, INVOICE, INVOICE_R, OPRCOST, OPRCOST_R, DRCRFULL, DRCRFULL_R, SHIPPER, CONSIGNEE, AGENT, VESSEL, SATUAN, USER, JE, JE_D, ACCOUNT, SALDO_ACCT. This is the scope-of-parity whitelist; it is **not** every DBF file in `DBF_DIR` — each company has 110 DBF files in `DATA/` (148–151 with `INIT/`; 34 are empty in all five companies).
  2. Builds a temp DB, then atomic `rename()` swap — a failed sync never corrupts the live DB.
  3. `users` + `sessions` tables (and Laravel's `cache`/`jobs` tables if adopted) are
     **preserved across the swap** (carry-over logic like the Python sync's preserve step).
  4. `UnifiedShipment` view recreated in the temp DB each run; its SQL has a **single source
     of truth**: one PHP class (e.g. `UnifiedShipmentSql`) used by both the sync command and
     any app-side view usage. No duplicated view SQL, ever.
  5. Emits structured JSON events (`sync_start`, `table_synced`, `sync_fail`, `sync_complete`).
  6. Subprocess/run timeout `SYNC_TIMEOUT_SECONDS` (default 600s). The 18-table whitelist is the current scope-of-parity ingest; the second DBF layer documented in §1.2b (110 DBF files per company in `DATA/`, 148–151 with `INIT/`) is tracked as Q-11 / F-31.
- **Multi-DB**: five company DBs in `data/`; header selector sets cookie `cmt_db`; a middleware
  resolves the active DB path per request and falls back to the default DB on invalid values.
- **Docker**: two services as today — `dashboard` (php-fpm/artisan serve or Octane-free php-fpm)
  and `sync` (one-shot `php artisan dbf:sync`, exits).

### 3.1 One-to-one port map

| Python (as-built) | Laravel target |
|---|---|
| `src/app/main.py` (routes) | `routes/web.php` + controllers |
| `src/app/auth.py` (`User`, `require_login`, `require_role`, cookie helper) | middleware + Laravel auth (session guard) |
| `src/app/queries.py` | query-service classes (`app/Support/Queries/*`) using the same SQL |
| `src/app/db.py` (SQLite wrapper, active-DB ContextVar) | a `CompanyDb` manager (per-request active DB) + DB::connection proxying |
| `src/app/middleware.py` (DB switcher, request logging) | Laravel middleware (same names, same behavior) |
| `src/app/i18n.py` + JSON files | Laravel `lang/` files (id, en, zh) + `__()` helper |
| `src/app/templates/*.html` (Jinja2) | `resources/views/*.blade.php` (Blade) |
| `tools/sync_db.py` + `tools/schema.py` | `app/Console/Commands/DbfSync.php` + `app/Support/Dbf/Schema.php` |
| `CMT_USERS` env seeding | Laravel seeder reading `CMT_USERS` (format preserved) |
| `pip-audit` → `composer audit` gate | in CI + docs |
| **Ship-finance / AR-AP / bank journal DBFs (Q-11, F-31)** | **Not in current 18-table ingest; no query class exists.** Pending owner decision (Q-11). If added, port per company, starting from the field signature in §1.2b — do NOT re-discover fields by grepping DBF at sync time in the port (sync owns that; the port consumes the synced SQLite tables). |

---

### 3.2 (DECIDED) Legacy software report inventory — what management requires viewable

These are the report surfaces the management team requires to be viewable in the dashboard. They are the report outputs of the legacy software; the rebuild reproduces them as Filament pages (tables, filters, drill-downs) backed by the same synced SQLite data.
#### 3.2.1 Projects / Jobs — CARGO.DBF + NA.DBF (merged single view)

The legacy system maintains two DBF sources that are presented as one merged view:
- **CARGO.DBF** (Shipping Instruction — Export side)
- **NA.DBF** (Notice of Arrival — Import side)

These are merged into a single Projects/Jobs view. Both the **Summary** and **Detail** presentations exist.

**Summary view** — displays header-level data per record, including:
- Status of B/L (Bill of Lading status)
- Invoice data
- Operational Cost data
- Debit/Credit Note FCL data
- Debit/Credit Note LCL data

- **Menu label**: the legacy report menu names this pair one report, `Status B/L & Invoice` (menu row 3); §3.2.1 carries it as two elements rather than as a separate report row.

> Note: the legacy system separates Debit/Credit Note into FCL and LCL buckets — the reason for the split is not documented. The merged view carries both fields forward as separate columns; do not collapse them without an owner decision.

**Detail view** — expands the same record to show detail-level data:
- Manifest data
- Invoice data (line-level)
- Operational Cost data (line-level)
- Debit/Credit Note FCL data (line-level)
- Debit/Credit Note LCL data (line-level)

#### 3.2.2 Account Receivables

| Report | Description |
|---|---|
| Aging A/R — Invoice | Outstanding invoice aging |
| Aging A/R — Debit Note | Outstanding debit-note aging |
| Contra Bond — In | Contra-bond incoming |
| Receipt A/R — Invoice | Receipts against invoices |
| Discrepancy — Invoice | Invoice discrepancy records |
| Receipt — Cash/Bank | Cash/bank receipts |

> **Menu reconciliation (2026-10-13)**: the legacy menu spells this first row `Aging A/R - Invoive` —
> the misspelling is in the source data and is identical in all five companies. This PRD uses the
> report's real name; the misspelled form is reproduced only in the verbatim menu table of
> [`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md) §1.1, which maps it back to this row.
> One report, two spellings — not two reports.

#### 3.2.3 Account Payables

| Report | Description |
|---|---|
| Aging A/P — Operational Cost | Outstanding operational-cost aging |
| Aging A/P — Credit Note | Outstanding credit-note aging |
| Contra Bond — Out | Contra-bond outgoing |
| Payment A/P — Vendor | Payments to vendors |
| Payment A/P — Agent | Payments to agents |
| Payment A/P — Refund Cost | Refund-cost payments |
| Payment — Cash/Bank | Cash/bank payments |

#### 3.2.4 Finance

| Report | Description |
|---|---|
| Listing Journal | Journal entry listing |
| General Ledger | GL account listing |
| Trial Balance: Mutation | Trial balance (mutation view) |
| Trial Balance: Balance Sheet & Income Statement | TB — balance sheet + income statement combined |
| Balance Sheet | Balance sheet |
| Income Statement | Income statement |
| Other Finance Reports | Other finance reports (catch-all bucket — enumerate per legacy UI) |

#### 3.2.5 Statement of Account

- **Statement of Account** — account statement view (customer/vendor-facing statement).

#### 3.2.6 Listing Reference

| Report | Description |
|---|---|
| Agent | Agent reference listing |
| Shipper | Shipper reference listing |
| Rate by Destination | Rate table by destination |
| Consignee / Notify | Consignee and notify-party reference |
| Account | Account reference listing |

- **Menu reconciliation**: the legacy menu names these same five reports `List Agent`, `List Shipper/ Forwarder`, `List Consignee/ Notify`, `List Account` and `Rate By Destination` — same reports, different words; nothing was renamed away from the menu or dropped.

#### 3.2.7 Performance Tracker

| Report | Scope |
|---|---|
| Performance Tracker — By Shipper | Performance metrics grouped by shipper |
| Performance Tracker — By Shipping Lines | Performance metrics grouped by shipping line |
| Performance Tracker — By Agent | Performance metrics grouped by agent |
| Performance Tracker — By Consignee | Performance metrics grouped by consignee (Import only) |

- **Menu reconciliation**: the legacy menu carries a single `Track Performance` entry (menu row
  37); the four rows above are that one report expanded into its grouping dimensions, not four
  separate reports.

#### 3.2.8 Miscellaneous views

| Report | Description |
|---|---|
| Petty Cash Request Listing | Petty-cash request listing |
| Refund Cost Request Listing | Refund-cost request listing |
| Recurring Costs | Recurring-cost listing |

#### 3.2.9 Menu-reconciled reports — Volume, Lifting, Sales, Gross Profit & Loss

Four labels that the legacy report menu presents (rows 1, 2, 4 and 8 of the PAD3 = REPORT block,
identical in all five companies) were missing from this inventory. **Decision (owner-delegated
2026-10-13): all four are in scope**, with the reason recorded per report below. Three of them
also emit a Word document (`MODULWORD`: `VOLUME_R`, `LIFTING_R`, `GROSSPROFIT`), so they are report
outputs rather than incidental screens. Evidence and regeneration:
[`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md) §1.1 and §6,
`python tools/legacy_report_evidence.py`.

| Report | Decision | Evidence, and what the decision implies |
|---|---|---|
| Volume | **In scope** — enumeration level | Menu row 1. `MODULWORD.VOLUME_R` writes `TEMPLATE\VOLUME.DOC` parameterised by a start date (`XTGLAWAL`) plus a value (`XNILAIC`), i.e. a period report. Its inputs are already inside the 18-table ingest: `CARGO` and `NA` carry `CONTAINERN` (655/1 052 and 6 054/9 542 non-blank in CMT), `MEAST` (605/1 052 and 7 098/9 542) and `GW`. The **column set is `unverified-locally`**: the template `.DOC` is not shipped in this tree and the report program is packed, so a legacy template or screenshot is required before the page can be specified. |
| Lifting | **In scope** — enumeration level | Menu row 2. `MODULWORD.LIFTING_R` writes `TEMPLATE\LIFTING.DOC` with the same parameter shape as Volume (start date plus value) and the same measured inputs — container and weight counts on `CARGO` / `NA`. Column set `unverified-locally` for the same reason. |
| Sales | **In scope** — already covered by F-10 | Menu row 4. The as-built app renders this report as the Sales Dashboard (`/sales`, F-10): summary, 12-month trend, top customers and top salesman, grouped by the shipment's `SALES` code over `INVOICE` joined to `UnifiedShipment`. No new page is required; the row exists so the menu label cannot be read as dropped. |
| Gross Profit & Loss | **In scope** — derived from the 18 | Menu row 8. `MODULWORD.GROSSPROFIT` writes `TEMPLATE\LOSSPROFIT.DOC` parameterised by a start and an end date, i.e. a period profit-and-loss statement. Shipment detail already computes the same figure — gross profit is `INVOICE.GRAND_USD` and `INVOICE.GRAND_RP` minus `OPRCOST.GRAND_USD` and `OPRCOST.GRAND_RP` — so this report is an aggregation of two tables already in the 18: a missing **query**, not missing data. |

> **Counts above**: the population figures in this table are live-row counts taken on
> 2026-10-13. `DBF/` is written while it is read, so [`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md)
> — regenerated by `python tools/legacy_report_evidence.py` — is authoritative for exact figures and
> carries the snapshot id it measured; this table cites them as *evidence that the sources are
> available*, not as frozen totals.

> **Scope note**: this inventory is the management-required report surface. Each row is a Filament page to reproduce. Where the legacy UI exposes parameters (date range, company, shipper filter, etc.), the Filament page carries the same filter surface. The Finance bucket's "Other Finance Reports" is a catch-all — enumerate the actual reports visible in the legacy UI before claiming parity on that row.

---

## 4. Feature parity inventory

Each feature = as-built behavior from the FastAPI app, to be reproduced 1:1 in Laravel.
Rows marked (SRS) carry the original FR IDs from [`docs/srs.md`](docs/srs.md).

| # | Feature | Parity behavior | Role |
|---|---|---|---|
| F-01 | Login/logout, session cookie | `cmt_session` (HttpOnly, SameSite=Lax, `Secure` via env behind TLS), persistent sessions | public / all |
| F-01b | — | Laravel's `database` session driver on SQLite; 8h TTL; expired sessions rejected + cleaned up (SRS FR-003) | — |
| F-02 | Env-based user seeding | `CMT_USERS=user:pass:role[:company]` format preserved; Laravel hasher (bcrypt/argon2id) replaces PBKDF2 (FR-002) | seed only |
| F-03 | User management UI | list / create / edit / delete users; delete is superadmin-only + self-deletion blocked (as-built; SRS listed it as future) | admin / superadmin |
| F-04 | Dashboard (home) | summary stats + unpaid-shipments table + Top Customers (hidden for customer role), customer-filtered | all |
| F-05 | Shipments list | unified list w/ search, sort, pagination, `type` filter (imports `I` / exports `E` / other), invoice-link filter; customer-filtered | all |
| F-06 | Shipment detail | info card (incl. ETD/ETA/ATA, vessel+voyage, shipper/consignee) + invoice lines + gross profit (IDR via per-document EXRATE) | all |
| F-07 | Invoices list | search, sort, pagination, shipper code → name resolution; customer-filtered. No status filter on this page — status filtering lives on the shipments page as the `invoice` param (as-built; see `docs/parity-checklist.yaml` KG-4) | all |
| F-08 | Invoice detail | line items grouped by `K_INVOICE`, payment summary, corner totals convert USD at each document's own EXRATE | all |
| F-09 | General Ledger | chart of accounts list + account journal drill-down (newest-first default) | all |
| F-10 | Sales Dashboard | Top Salesman, Top Customers, Monthly Revenue cards; period filter (`month/year/round/all`); customer-filtered | all |
| F-11 | Data Quality | GL anomaly scan (unbalanced/orphan/bad-date/bad-DK), scoped to active company DB | superadmin |
| F-12 | Vessel info | vessel data via shipment detail joins (`VESSEL` table) | all |
| F-12b | — | Vessel entity remains in the sync ingest list regardless of UI surface | — |
| F-12c | — | No standalone vessels page — as-built has none; do NOT re-add one (YAGNI cut) | — |
| F-13 | Company DB switcher | header dropdown, cookie `cmt_db`, per-request middleware resolution with invalid-value fallback | all roles, active-DB scoping only |
| F-14 | Manual sync trigger | `POST /sync` runs `dbf:sync` as a subprocess with timeout; frontend endpoint that triggers backend batch job — no business data in request body | management+ |
| F-15 | Health API | `GET /api/health` public liveness | public |
| F-16 | Summary API | `GET /api/summary` login-gated (SRS FR-009) | login |
| F-17 | Sync-status API | `GET /api/sync-status` — last sync time + staleness vs `STALE_AFTER`; parity test captures `POST /sync` response (status, JSON) to enforce trigger endpoint parity | login |
| F-18 | i18n | ID/EN/ZH; every user-visible string via `__()`; badges map machine values through label helpers | all |
| F-19 | Structured JSON logging | per-request access logs (method, path, status, duration_ms, user, request_id), auth events, sync events (SRS FR-006) | — |
| F-20 | Custom 404 | translated error page | — |
| F-21 | Read-only guarantee | no business-data write path; only `users`/`sessions` (+ Laravel's own tables) are writable (FR-005). **Exemption**: `POST /sync` triggers the backend `dbf:sync` batch job — the sync itself writes business tables via `SyncService`/`dbf:sync`, not the frontend request | — |
| F-22 | Projects / Jobs — Summary | Merged CARGO.DBF + NA.DBF header view: Status of B/L, Invoice, Operational Cost, Debit/Credit Note FCL, Debit/Credit Note LCL | all |
| F-23 | Projects / Jobs — Detail | Same records expanded to manifest, invoice (line), operational cost (line), debit/credit note FCL (line), debit/credit note LCL (line) | all |
| F-24 | Account Receivables reports | Aging A/R — Invoice, Aging A/R — Debit Note, Contra Bond — In, Receipt A/R — Invoice, Discrepancy — Invoice, Receipt — Cash/Bank | all |
| F-25 | Account Payables reports | Aging A/P — Operational Cost, Aging A/P — Credit Note, Contra Bond — Out, Payment A/P — Vendor, Payment A/P — Agent, Payment A/P — Refund Cost, Payment — Cash/Bank | all |
| F-26 | Finance reports | Listing Journal, General Ledger, Trial Balance: Mutation, Trial Balance: Balance Sheet & Income Statement, Balance Sheet, Income Statement, Other Finance Reports | all |
| F-27 | Statement of Account | Account statement view | all |
| F-28 | Listing Reference | Agent, Shipper, Rate by Destination, Consignee/Notify, Account | all |
| F-29 | Performance Tracker | By Shipper, By Shipping Lines, By Agent, By Consignee (Import only) | all |
| F-30 | Miscellaneous views | Petty Cash Request Listing, Refund Cost Request Listing, Recurring Costs | all |
| F-31 | Ship Finance & AR/AP Journalism | A/R + A/P journal entries (`ARS`/`ARS_R`, `AR_AGENT`/`AR_AGENT_D`; `AP`/`AP_R`, `AP_AGENT`/`AP_AGENT_D`), Bank/Clearing/receipt journal (`BAYAR`/`BAYAR_R`, `TERIMA`/`TERIMA_R`, `CLEARING_D`), Intraship A/R (`SI`/`SI_D`), Debit/Credit note journalism (`CRDRNOTE`/`CRDRNOTE_R`/`CRDRNOTE_S`) — **coverage INVESTIGATED / pending owner decision (Q-10/Q-11).** Measured live with `tools/dbf_inventory.py` (dbfread 2.0.7): these exist under every company's `DATA/` dir and all five hold the same set (per-company totals 148–151 DBF files, 110 of them in `DATA/`). None is in the 18-table management ingest and no query class exists in `src/app/queries.py`. **Data-bearing** (row counts and per-report mapping in [`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md)): ARS/ARS_R, AR_AGENT/AR_AGENT_D, AP/AP_R, AP_AGENT/AP_AGENT_D, BAYAR/BAYAR_R, TERIMA/TERIMA_R, CLEARING_D, SI/SI_D, CRDRNOTE/CRDRNOTE_R/CRDRNOTE_S, SAGING, QUOTATION/QUOTATION_R, VENDOR/VENDOR_D, MANIFEST, COST, RC/RC_D, RCREQ/RCREQ_D. **Vestigial** (empty in all five, so not parity candidates): `AR`, `AR_D`, `AR_CONTRA`/`AR_CONTRA_R`, `AP_CONTRA`/`AP_CONTRA_R`, `CLEARING`, `SALDO_VENDOR`, `SALDO_AGENT`, `PERMOHONAN`/`PERMOHONAN_D`, `VENDOR_MAR_D`/`VENDOR_MAR_S` — so Contra Bond In/Out cannot be reproduced from current data at all. Until the owner picks the subset, this row is **open scope**, not parity. | all / pending owner decision (open scope) |
| F-32 | Volume, Lifting & Gross Profit reports (menu-reconciled) | The three §3.2.9 reports added from the legacy menu: period aggregates over `CARGO` / `NA` (container, measurement and weight fields) and over `INVOICE` minus `OPRCOST` — every source is already inside the 18-table ingest, so no new table is required. `Sales` from the same menu block is covered by F-10. **Status: enumeration-level, not parity-ready** — the Volume and Lifting column sets are `unverified-locally` (template `.DOC` not shipped, report program packed) and need a legacy template or screenshot before a page is specified. | all |

**Parity test rule**: for every row above, the Laravel page must render the same data and
respect the same role gates as the FastAPI app, verified side-by-side during parallel run (§8).

---

### F-21 — Read-only guarantee: canonical path, rationale, acceptance criteria

**Selected canonical write path for business data**: The **only** path that writes business tables
(shipments, invoices, ledger, agents, shippers, consignees, operational cost, debit/credit notes)
is the backend batch sync job — invoked by the Laravel `dbf:sync` command / `SyncService`. The
frontend `POST /sync` endpoint is a **trigger only**: it accepts no business data in the request
body, requires `management+` role + CSRF, and spawns the `dbf:sync` subprocess with a timeout.
The sync itself performs `INSERT`/`INSERT OR REPLACE` into all 18 business tables inside a temp
DB, then atomically swaps it into place (FR-007). The frontend never constructs or sends business
INSERT/UPDATE/DELETE statements.

**Rationale**:
- The dashboard's product concept is a **read-only view** over legacy FoxPro DBF files. Business
  data originates in the legacy app; the dashboard only mirrors it.
- `POST /sync` is an **operational control** (management triggers a refresh), not a data-entry
  form. Classifying it as a "business write" would forbid the manual refresh capability that
  F-14 explicitly requires.
- The `filament-view-layer.md` decision record (§5) codifies: "Read-only guarantee (F-21). Business
  tables are sync-owned; converted pages expose read-only tables only. No mutations, no Filament
  actions that write business data." The converted Invoices page (F-07) is a Filament **Page** with
  a read-only table, no Resource, no actions — this is the pattern for all §3.2 report pages.

**Acceptance criteria** (testable, enforced in parity + lint):
1. **No business-data forms**: `grep -r "method=POST\|method=\"post\"" resources/views/ --include="*.blade.php` returns only `/login`, `/logout`, `/users/*`, `/set-lang`, `/set-db`, `/sync`. Zero POST forms targeting business tables.
2. **`/sync` request body is empty of business data**: the route handler reads only auth/session; no Eloquent/model mass-assignment, no `request()->all()` on business fields.
3. **All business writes route through `dbf:sync` / `SyncService`**: static analysis (`grep -r "INSERT\|UPDATE\|DELETE" app/ --include="*.php" | grep -v "users\|sessions\|cache\|jobs\|SyncService\|DbfSync"`) returns zero hits on business tables.
4. **Filament business pages are read-only**: every converted §3.2 page uses a `Page` class with `records()` hook, no `FilamentResource`, no `Action` that mutates business data. Parity test asserts no mutating action routes registered.
5. **Parity test captures `/sync` POST**: F-17 parity test records status + JSON response of `POST /sync` against the Python baseline, enforcing trigger parity.

**Rejected alternatives**:
| Alternative | Why rejected |
|---|---|
| Treat `POST /sync` as a business write path (no exemption) | Violates F-21 as written; removes the manual sync trigger (F-14) that management requires; sync is an operational batch job, not user data entry. |
| Move sync to background cron only (no frontend trigger) | F-14 explicitly requires a manual sync trigger for management+; owner expects on-demand refresh. |
| Add Filament Resources with forms for business tables | Contradicts `filament-view-layer.md` decision (read-only, no forms), violates F-21, adds write surface where none exists in the Python app. |
| Exempt all authenticated POST endpoints | Over-broad; `/users/*` are auth administration (explicitly allowed), `/set-lang` and `/set-db` are cookie setters (no DB write). Only `/sync` is the operational trigger. |

---

## 5. Functional requirements (re-targeted from SRS)

| ID | Requirement | Laravel implementation note |
|---|---|---|
| FR-001 | Users & sessions persist in SQLite | Laravel `users` table + `database` session driver |
| FR-002 | No hardcoded credentials; per-user salted hashes | Laravel Hash facade (bcrypt/argon2id); `CMT_USERS` env seeding only |
| FR-003 | Opaque session tokens, expiry, cleanup, `session_expired` logging | `database` session driver; 8h TTL config; middleware logs expiry |
| FR-004 | Cookie flags | `cmt_session`-equivalent Laravel session cookie: HttpOnly + SameSite=Lax always, `Secure` via `SESSION_SECURE_COOKIE` |
| FR-005 | RBAC on every route | middleware on every route; public whitelist = `/login` GET/POST, `/api/health`, `POST /set-lang`; `/set-db` login-gated (verify at build time) |
| FR-005b | Customer data segregation at query level | every customer-visible query takes the company filter — port `_company_filter()` discipline |
| FR-006 | Structured JSON logs (access, auth, sync) | Laravel `Log` with JSON formatter or monolog JSON handler; request_id per request |
| FR-007 | Idempotent, observable, atomic sync | DbfSync command: temp DB → `rename()` swap; per-table counts; JSON events; unified-view SQL in ONE PHP class |
| FR-008 | Dependency audit gate | `composer audit` zero High/Critical or documented accepted-risk |
| FR-009 | Health public, summary gated | as-built: `/api/health` public, `/api/summary` behind login |

---

## 6. UI constraints — DESIGN.md is binding

The rebuild changes the rendering engine (Jinja2 → Blade), not the design system:
- IBM Plex Sans, dark-first tokens, table-card pattern, compact tables, `text-nowrap` dates/money.
- `font-variant-numeric: tabular-nums` on every right-aligned number column.
- Money always rendered with explicit currency via the money filter; mixed-currency totals converted via EXRATE before summing.
- Shipment-kind colors on badges + trend chart only; one badge per concept; `-subtle` badge pairs.
- No shadows on cards, no animation beyond the 120ms hover; sidebar active-indicator bar is the one authored moment.
- Do not hardcode hex in templates — extend `--color-*` tokens (CSS variables); CoreUI-era hard rules carry over to Blade components (`<x-*>` components where helpful, no re-theming).

---

## 7. Non-functional requirements

| ID | Category | Requirement |
|---|---|---|
| NFR-001 | Security | Pre-launch deep pentest (Strix or equivalent): 0 Critical, 0 High (deferred by owner in the current app; required before cutover) |
| NFR-001b | Security | `composer audit` clean (0 High/Critical) at build time |
| NFR-002 | Performance | Dashboard pages < 500ms p95; full sync of the 18 tables < 5 min |
| NFR-003 | Reliability | Failed sync never corrupts the live DB (temp file + atomic rename) |
| NFR-004 | Maintainability | Zero duplicated view SQL; tests cover auth + queries + company filter |
| NFR-005 | Onboarding | Fresh clone → running app in ≤ 2 shell commands (compose up; or `composer install && artisan dbf:sync && artisan serve`) |
| NFR-006 | i18n | All new strings added to id/en/zh; no raw machine values in UI |

---

## 8. Migration plan — parallel run → cutover

Owner decision: **parallel run, then cutover**. The Python app remains the fallback until owners sign off.

### Phase gates

| Phase | Gate (measurable) |
|---|---|
| P0 — Scaffold | Laravel project created; SQLite connection + multi-DB manager + DB switcher middleware working |
| P1 — Auth | Login/logout, `CMT_USERS` seeder, roles middleware, session TTL + cookie flags, user management CRUD |
| P2 — Shipments & vessels | Shipments list + detail (type/invoice filters, gross profit), vessel joins |
| P2b — Finance | Invoices list + detail (status filters), GL + journal drill-down, Sales Dashboard |
| P2b2 — Quality & APIs | Data Quality page, health/summary/sync-status APIs, custom 404, structured logging |
| P3 — Sync | `dbf:sync` command with atomic swap + users/sessions preservation + JSON events |
| P4 — Hardening parity | `composer audit`, RBAC audit on every route, i18n completeness, tests (auth, queries, company filter) |
| P5 — Parallel run | Both apps run against the same synced SQLite DBs; side-by-side page comparisons per role |
| P6 — Cutover | Parity checklist 100% + NFR-001 pentest clean → switch users to Laravel; Python app retained as fallback for a grace period |

### Phase status — 2026-09-23 (as-built snapshot)

Evidence is per-commit on `feat/system-hardening` (P0–P2 @ `39655ed`…`7fa6fae`, P3 `d058852`, gates `eb24a20`, CI `bc823ed`, GLOB protection `5689754`, data-quality `efaf784`). All suites green at this snapshot: Laravel 89/89, Python 87/87, parity captures 10/10.

| Phase | Status | Evidence | Remaining |
|---|---|---|---|
| P0 — Scaffold | **Complete** | `CompanyDbSwitcherTest` (12): multi-DB manager, cookie validation + silent fallback, switcher parity (middleware-direct since the temporary `/p0-status` surface was removed) | — |
| P1 — Auth | **Complete** | `CmtAuthTest` (15): `CMT_USERS` seeding, login/logout, session TTL + cleanup, cookie flags, role gates; `UserAdminController` + `/users*` routes | — |
| P2 — Shipments & vessels | **Complete** | `P2PagesTest` (19): type/invoice filters, gross profit, role-conditional columns, baseline-signature assertions against `tests/parity/baseline/python.json` | — |
| P2b — Finance | **Partial** | Invoices list + detail ported and gated (`P2PagesTest`, checklist F-06/F-06d incl. production-shape s5) | GL + journal drill-down, Sales Dashboard — not ported (production audit reports them not-yet-ported, so no route can 500) |
| P2b2 — Quality & APIs | **Partial** | Data Quality page complete: `P4DataQualityTest` (13) + `CmtAnomalies` on the shared spec-table construction (`efaf784`) | health/summary/sync-status APIs, custom 404, structured logging |
| P3 — Sync | **Complete** | `P3SyncTest` (9): atomic swap incl. Windows locked-target fallback, users/sessions preservation, JSON events, per-company discovery; `tools/compare_sync_dbs.py` byte-compare vs the Python sync; `DBF_SYNC_BACKEND=python` escape hatch contract-tested | Re-run the byte-compare on real-tree sync changes (fixtures kept small for suite speed) |
| P4 — Hardening parity | **Mostly complete** | `composer audit` 0 advisories (verified 2026-09-23); `ProductionSchemaTest` (5) + `CrossAppVectorsTest` (15) + production audit (200 calls × 5 real DBs, rollup==detail reconciliation); schema gates (`tests/test_schema_gate.py`) | RBAC sweep closed 2026-09-23: the temporary unguarded `/p0-status` surface was removed (Python has no equivalent route — defect-for-defect parity), its 5 resolver tests rewired middleware-direct; only public GET remaining is `/login`. A distinct i18n key-coverage sweep has not been run |
| P5 — Parallel run | **Setup ready** | Python baseline harness + committed `python.json` (10 captures); `tools/compare_parity.py` + mechanical exit-0 contract (`docs/decisions/p5-parity-diff-contract.md`); gates pipeline `python tools/run_gates.py` (Makefile + hosted CI, nightly full run) | Laravel capture harness (emits the Laravel-side capture JSON) not yet implemented; `laravel/storage/app/private` is gitignored, so the contract doc's committed-capture path needs a committed location decided first |
| P6 — Cutover | Not started | — | Blocked on P5 parity + SC-001 pentest |

Gates added since this PRD was written (now part of the definition of done):
**schema-drift gates** (committed `tests/parity/fixtures/real_schema.json` snapshot of the five real company DBs, enforced by `tests/test_schema_gate.py` + `laravel/tests/Feature/ProductionSchemaTest.php`), **production query audits** (every query fn × every branch on copies of the real DBs, `_safe_query`/`safeQuery` un-swallowed, rollup==detail reconciliation — origin: the CONTAINERN and bad_date GLOB incidents, `docs/decisions/invoice-container-schema-drift.md`), and **`tools/run_gates.py`** running all of it in one command. See `docs/parity-checklist.yaml` `meta.schema_gates`.

### Parallel-run setup

- Both apps read the same `data/*.db` files. During parallel run the Python app is treated as
  a read-only comparison target: no logins, no writes — only the Laravel app writes `users`/`sessions`.
- Sessions are NOT shared across the two apps — users log in per app during comparison.
- Compare outputs per route per role: same rows, same order, same totals, same role gates.
- Any parity bug → fix in Laravel, re-run comparison, log to the parity checklist.
- **Verification is tracked in [`docs/parity-checklist.yaml`](docs/parity-checklist.yaml)** —
  the machine-readable, per-role step list for P5 (19 features, 60 steps, role × feature
  coverage matrix, and the known gaps to reproduce defect-for-defect).
- **The P5 gate is mechanical**: the Laravel side emits a structurally identical capture JSON
  (spec: [`docs/decisions/p5-parity-diff-contract.md`](docs/decisions/p5-parity-diff-contract.md))
  and `python tools/compare_parity.py <laravel.json>` exits 0 only on parity with the Python
  baseline.

---

## 9. Success metrics

| ID | Metric |
|---|---|
| SC-001 | Pre-cutover pentest: 0 Critical, 0 High findings (NFR-001) |
| SC-002 | Parity checklist 100% (every F-row verified side-by-side per role) |
| SC-003 | Fresh clone → running Laravel app in ≤ 2 shell commands |
| SC-004 | Sync run N times in a row → valid DB every time; failed run never corrupts the live DB |
| SC-005 | `composer audit` 0 High/Critical at cutover |
| SC-006 | Role-matrix audit: 100% of routes have explicit middleware |
| SC-007 | Test suite green: auth, queries, company filter, sync preservation |

---

## 10. Open questions

| # | Question | Owner | Status |
|---|---|---|---|
| Q-01 | ~~Which PHP DBF-reading library?~~ **DECIDED 2026-09-22**: `hisamu/php-xbase` behind an in-repo `DbfReader` facade; Python sync kept as escape hatch (`DBF_SYNC_BACKEND=python`) and as the P3 parallel-run sync-of-record — see [`docs/decisions/dbf-reading.md`](docs/decisions/dbf-reading.md) | engineering | decided |
| Q-02 | ~~Laravel version pin~~ **DECIDED 2026-09-22 (P0 scaffold)**: Laravel 12 (composer `^12.0`), PHP `^8.2` constraint (runs on this machine's 8.2.28; prod image may pin 8.3+), located at `laravel/` in-repo | engineering | decided |
| Q-03 | PHPUnit vs Pest for the ported test suite | engineering | open |
| Q-04 | Fate of `memory/`, `.specify/` scaffolding during rebuild (ponytail-debt items 1–2) | engineering | open |
| Q-05 | Missing `IDEA.md` — referenced by README + SRS but absent from repo | owner | open |
| Q-06 | After cutover: archive, keep as fallback, or delete the Python app? | owner | open |
| Q-07 | Dispatching/queue choice if any async work is needed later | engineering | not needed for parity |
| Q-08 | Whether to keep the `cmt_session` cookie name for UX continuity (users stay logged in across cutover) — requires compatible session table schema; else new cookie name and everyone logs in once | engineering | open |
| Q-09 | **Filament as the view layer** (DECIDED, §1.2 / §3.2) — which Filament version, theme/customization approach, and whether to keep any Blade fallbacks for non-Filament pages | engineering | decided |
| Q-10 | **Legacy report DBF coverage — INVESTIGATED, then reconciled against the measured tree (see [`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md)).** The sync ingests 18 tables (`tools/sync_db.py` `_REQUIRED_TABLES`): CARGO, NA, INVOICE, INVOICE_R, OPRCOST, OPRCOST_R, DRCRFULL, DRCRFULL_R, SHIPPER, CONSIGNEE, AGENT, VESSEL, SATUAN, USER, JE, JE_D, ACCOUNT, SALDO_ACCT. **Covered by the 18 tables**: F-22/F-23 (Projects/Jobs — UnifiedShipment from NA+CARGO + OPRCOST/OPRCOST_R + INVOICE/INVOICE_R + DRCRFULL/DRCRFULL_R), F-24 Aging A/R Invoice + F-25 Aging A/P Operational Cost + F-24/F-25 Debit/Credit Note aging (INVOICE/INVOICE_R, OPRCOST/OPRCOST_R, DRCRFULL/DRCRFULL_R), and the whole F-26 journal family (JE, JE_D, ACCOUNT, SALDO_ACCT). **Resolved since the first pass** (two earlier claims here were wrong): the Balance Sheet / Income Statement family is **derivable from the 18** — `ACCOUNT` carries `TYPE`, `GROUP` and `SLD_NORMAL`, so it is a missing query, not missing data; and F-28 "Rate by Destination" **has a source** — `QUOTATION`/`QUOTATION_R` (agent × destination per-container rates) plus `VENDOR_D`, while `EXRATE` is currency rather than destination. F-29 By Shipping Lines likewise has `VENDOR` (`KD_SHIPLIN`, `NAME`). **Not reproducible at all from current data**: F-24/F-25 Contra Bond — In/Out, because `AR_CONTRA(_R)` and `AP_CONTRA(_R)` are empty in all five companies (§1.2b). **Still open**: F-26 "Other Finance Reports" (enumerate the legacy UI), F-27 Statement of Account (derived from invoices + A/R receipts, or an unpopulated `SOA`), and F-30 Petty Cash Request Listing (`PERMOHONAN` is empty everywhere; `PERMOHONAN_E` holds data only in CSM/MAL). Any report whose source is not in the 18, or whose query is absent from `queries.py`,  stays out of Filament parity until an owner confirms the derivation. | engineering | open |


| Q-11 | **Ship-finance & AR-AP journal DBF coverage — measured (see [`docs/report-dbf-coverage.md`](docs/report-dbf-coverage.md)).** A second layer of DBF files sits under each company's `DATA/` dir alongside the 18 management tables. Measured with `tools/dbf_inventory.py` (dbfread 2.0.7, header-only reads), replacing the earlier "~87 files" estimate, which was wrong: each company holds **110 DBF files in `DATA/`** (SBY 112) and **148–151 in total** once `INIT/` is included; union 151 names, 148 common to all five (exceptions: `DEFAAPP`, and two `JE.SYNC-CONFLICT-*` backups in SBY). `AR_R.DBF` is **not present in any company** and has been removed from this record. **Data-bearing second-layer tables**: ARS/ARS_R, AR_AGENT/AR_AGENT_D, AP/AP_R, AP_AGENT/AP_AGENT_D, BAYAR/BAYAR_R, TERIMA/TERIMA_R, CLEARING_D, SI/SI_D, CRDRNOTE/CRDRNOTE_R/CRDRNOTE_S, SAGING (1 row, the aging buckets 1–30/31–60/61–90/90+), QUOTATION/QUOTATION_R, VENDOR/VENDOR_D, MANIFEST, COST, CARGO_R, NA_R, NA_D, RC/RC_D, RCREQ/RCREQ_D, PERMOHONAN_E (CSM/MAL only), CITY, PELABUHAN, EXRATE. **Vestigial — 34 tables empty in every company, not candidates**: AP_CONTRA, AP_CONTRA_R, AR, AR_CONTRA, AR_CONTRA_R, AR_D, BAL_COBA, CLEARING, CMEMO, CMEMO2, COMMODITY, CSI_SC, CURLIFT, GENCDX, GENDBF, INCOME6, INVOICE_ILG, INVOICE_ILG_R, ITEM, KURS, MANICONT, MERGE, PEB, PERMOHONAN, PERMOHONAN_D, P_DRCRNOTE, Q_SDETAIL, SALDO, SALDO_AGENT, SALDO_VENDOR, TBALANCE, TJE_D, VENDOR_MAR_D, VENDOR_MAR_S. **Recommended split**: (a) Tier A ingest candidates — the data-bearing tables above that a §3.2 report actually renders; (b) Tier B derivable from Tier A plus the 18 — Balance Sheet, Income Statement, TB-BS+IS, Statement of Account, Performance Tracker; (c) Tier C drop as vestigial; (d) Tier D verify first — `NA_D` against `OPRCOST_R`, `CARGO_R`/`NA_R`, `PERMOHONAN_E`, Discrepancy — Invoice. **Decision needed**: which subset joins the sync whitelist; whether F-31 is parity or a new feature; and whose legacy report screenshots to verify the inferred mappings against. | engineering + owner | open |

Q-08 note: session table compatibility is unlikely (Laravel's `sessions` schema differs from the current one), so default = new cookie, one re-login at cutover.

---

## 11. Appendix — env vars carried over (README parity)

| Var | Default | Description |
|---|---|---|
| `DATABASE_URL` | `data/cmt.db` | Default SQLite file path |
| `DBF_DIR` | `DBF/` | DBF source directory |
| `CMT_USERS` | *(none)* | Seed users `user:pass:role[:company]` — the ONLY way to create users initially |
| `CMT_COOKIE_SECURE` | *(off)* | → Laravel `SESSION_SECURE_COOKIE` |
| `CMT_LANG` | `id` | Default language |
| `SYNC_TIMEOUT_SECONDS` | `600` | Sync subprocess timeout (Python) → `DBF_SYNC_TIMEOUT` |
| `SYNC_LOG_JSON` | *(off)* | JSON sync events → native JSON logging in Laravel |
| `STALE_AFTER` | *(set in app)* | Sync staleness threshold for `/api/sync-status` |

---

*PRD ends. Parity checklist live-tracking happens in the parallel-run phase (§8), not in this doc.*