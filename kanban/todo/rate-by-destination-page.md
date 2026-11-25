## Title
Build the Rate by Destination page (F-28 row 3)

## Origin
- Source: PRD §3.2.6 row 3 (Rate by Destination) and the new §4 row F-28; the legacy menu's label is
  `Rate By Destination`.
- Q-10 resolved the previous dead end: `QUOTATION_R` carries `CODE_AGENT`, `KD_CITY`, `DESC_CITY`,
  `LCL`, `MIN`, `D20`, `D40`, `D40HQ`, `D45` (633 rows in CMT) — one row per agent × destination with
  per-container rates; `VENDOR_D` is the vendor-side tariff; `EXRATE` is **currency**, not
  destination, and is not this page's source.
- Maps to: PRD §3.2.6, §4 F-28, §10 Q-10/Q-11.
- Date opened: 2026-10-14

## Acceptance criteria
1. The page renders one row per agent × destination with the rate columns the measured `QUOTATION_R`
   field list defines, and the source tables (`QUOTATION` + `QUOTATION_R`, and `VENDOR_D` if the
   vendor side is shown) are named in the code.
2. The rendered column set matches the measured field list (no invented columns); the check is
   reproducible from `python tools/dbf_inventory.py --fields QUOTATION_R VENDOR_D`, and the rate unit
   (per container type: `LCL`/`MIN`/`D20`/`D40`/`D40HQ`/`D45`) is stated on the page.
3. Agent and destination labels resolve through their reference tables (AGENT, CITY/PELABUHAN) rather
   than raw codes — or the fallback and its reason are stated when a code has no reference row.
4. Rate figures follow §6 (explicit currency, tabular-nums, nowrap); a rate with no currency
   denomination is either excluded or explicitly labelled, not silently summed with money columns.
5. If this item updates the evidence doc, `python tools/legacy_report_evidence.py --dbf-dir DBF --check`
   still exits 0.

## Scope
- In: the page/query after the tables are ingested, its i18n keys, tests, and the column record.
- Out: ingesting `QUOTATION`/`QUOTATION_R`/`VENDOR_D` (the Q-11 decision owns the whitelist change),
  the other §3.2.6 rows (separate item), and treating `EXRATE` as a destination source.

## Status
- State: todo
- Session:
- Picked:
- Last touch: 2026-11-21
- Worker:
- Blocker: Unblocked by Q-11 Option A: `QUOTATION`/`QUOTATION_R` and `VENDOR`/`VENDOR_D` are
 accepted into Tier A of the whitelist (`docs/decisions/q11-second-layer-ingest-brief.md`). Once
 ingested, write the agent × destination listing from the measured `QUOTATION_R` field list.
 Vendor-side sharing of the page is still an open design question (see this todo's open questions).

## Working notes

**This row is why "Rate by Destination" is buildable at all.** Before the coverage work it looked
like a dead end (no destination rate table in the 18, and `EXRATE` is currency). The measured
`QUOTATION_R` field list is the source; the page is a straight listing once the table is ingested,
which makes it one of the cheapest Tier A additions to justify.

**Two sides, one page?** The agent-side rate table (`QUOTATION_R`) and the vendor tariff (`VENDOR_D`)
are different audiences. The legacy report menu shows a single `Rate By Destination` entry, so the
default is one page; if the vendor side is a separate legacy screen, that is a §3.2.6 amendment, not a
second page invented here.

**Ask the owner the "which rates" question while the ingest is pending.** Rate rows carry validity
implicitly through which agent/destination rows exist; whether the screen shows the latest rate per
destination or the whole history changes the page's shape. Better decided before the ingest lands than
after.

## Decision notes

To be recorded when the work lands: whether the vendor tariff shares the page; the column set and its
measured source; label resolution behaviour for missing reference rows; and the rate-unit labelling.

## Proof artifact
- Page + query paths; test name + command asserting the column set and label resolution on a seeded
  fixture built from the measured fields.
- `python tools/dbf_inventory.py --fields QUOTATION_R VENDOR_D` output (the field list the page is
  built from).
- `python tools/legacy_report_evidence.py --dbf-dir DBF --check` → exit 0.

## Contract

**Endpoint**: `GET /api/reports/rate-by-destination`

**Query parameters**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number (1-indexed) |
| `per_page` | int | 50 | Rows per page (min 1) |
| `search` | string | "" | Case-insensitive search across agent code, city code/name, quota code, agent name |
| `sort` | string | "code" | Sort column: `code` (agent), `city`, `quota`, `date` |
| `sort_dir` | string | "asc" | `asc` or `desc` |

**Response shape**
```json
{
  "rows": [
    {
      "UNIK": "string",
      "CODE_AGENT": "string",
      "KD_CITY": "string",
      "DESC_CITY": "string|null",
      "LCL": "number|null",
      "MIN": "number|null",
      "D20": "number|null",
      "D40": "number|null",
      "D40HQ": "number|null",
      "D45": "number|null",
      "PRINT": "string|null",
      "KD_QUOTA": "string",
      "TANGGAL": "string (YYYY-MM-DD)",
      "EXPIRED": "string (YYYY-MM-DD)|null",
      "KD_SHIPPER": "string",
      "agent_name": "string|null",
      "unresolved_agent": 0|1,
      "city_label": "string"
    }
  ],
  "total": "int",
  "page": "int",
  "per_page": "int",
  "pages": "int"
}
```

**Row semantics**
- One row per agent × destination = latest quotation (ROW_NUMBER over `QUOTATION.TANGGAL` desc, partitioned by `QUOTATION_R.CODE_AGENT`, `QUOTATION_R.KD_CITY`)
- `agent_name` from `AGENT.NAME` where `AGENT.AGENT = QUOTATION_R.CODE_AGENT` AND `AGENT.CODE = QUOTATION_R.KD_CITY`; `unresolved_agent=1` when no match
- `city_label` = `QUOTATION_R.DESC_CITY` when present/non-empty, else `QUOTATION_R.KD_CITY` fallback
- Rate unit: **per container** (`LCL` = per m³; `MIN`/`D20`/`D40`/`D40HQ`/`D45` = per container)
- Currency: **not included in this table** — `EXRATE` is currency table, not a destination rate; do not mix

**Empty state**
- `"rows": [], "total": 0, "page": 1, "per_page": 50, "pages": 1`

**Error state**
- HTTP 401 if unauthenticated (route uses `require_login()`)
- HTTP 500 if DB error (not explicitly handled; bubbles to FastAPI default)

**Open questions**
1. Vendor tariff (`VENDOR_D`) — PRD §3.2.6 legacy menu shows single "Rate By Destination" entry; spec leaves open whether vendor side shares this page or is a separate screen. Decision needed before build.
2. Rate history vs latest only — current query picks latest per agent×destination; spec notes "whether the screen shows the latest rate per destination or the whole history changes the page's shape." Confirm with owner.
3. `AGENT` join condition — current impl uses `AGENT.AGENT = CODE_AGENT AND AGENT.CODE = KD_CITY`. Verify this matches data; fallback `unresolved_agent` flag is present but untested.
4. Pagination params currently not used by frontend template (it calls non-paginated `/api/reports/rate-by-destination` via service). Align frontend to use paginated endpoint or drop pagination from contract.
5. Company filter — template sends `company` query param; canonical query uses `search` (multi-field). Document intent: filter by agent name? Currently `search` covers `AGENT.NAME` but not explicit company.
