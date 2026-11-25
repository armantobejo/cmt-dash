# F-28 Listing Reference Report Pages

**Status:** Implemented (Laravel Filament)  
**Scope:** Four reference listing pages — Agent, Shipper, Consignee/Notify, Account  
**Data source:** 18-table DBF→SQLite ingest (`AGENT`, `SHIPPER`, `CONSIGNEE`, `ACCOUNT`)

---

## Quick Reference

| Page | Route | Source Table | Primary Key | Default Sort | Page Size Config |
|------|-------|--------------|-------------|--------------|------------------|
| Agent | `/admin/agents` | `AGENT` | `(AGENT, CODE)` | `name` ASC | `cmt.agents_per_page` (default 25) |
| Shipper | `/admin/shippers` | `SHIPPER` | `KD_SHIPPER` | `name` ASC | `cmt.shippers_per_page` (default 25) |
| Consignee / Notify | `/admin/consignees` | `CONSIGNEE` | `KD_CONSIG` | `name` ASC | `cmt.consignees_per_page` (default 25) |
| Account | `/admin/accounts` | `ACCOUNT` | `KD_ACCOUNT` | `code` ASC | `cmt.accounts_per_page` (default 25) |

---

## 1. Agent Page (`/admin/agents`)

### Purpose
Reference listing of all agents from the `AGENT` DBF table. Agents represent shipping agents who handle shipments on behalf of shippers/consignees.

### Report Sections (Rendered Columns)

| # | Column Label | DBF Field / Expression | Type | Formatting |
|---|--------------|------------------------|------|------------|
| 1 | Agent Code | `AGENT` | string | — |
| 2 | Sub Code | `CODE` | string | — |
| 3 | Name | `NAME` | string | — |
| 4 | Invoice Agent | `AGENTINV` | string | — |
| 5 | Rate | `RATE` | numeric | `CmtMoney::format()` (USD/IDR) |
| 6 | Address | `ADDRESS1` + `ADDRESS2` + `ADDRESS3` | string | concatenated |
| 7 | Phone | `TELP` | string | — |
| 8 | Fax | `FAX` | string | — |
| 9 | PIC | `PIC` | string | — |
| 10 | Account Code | `KD_ACCOUNT` | string | — |
| 11 | Credit Account | `ACCT_CR` | string | — |

### Available Filters & Search
- **Search**: Case-insensitive partial match on `AGENT`, `CODE`, `NAME`
- **Company filter**: Applied for `customer` role via `companyFilter()` (joins through shipment linkage — see Note)

### Sort Options
| Sort Key | DBF Column | Default Direction |
|----------|------------|-------------------|
| `agent` | `AGENT` | ASC |
| `code` | `CODE` | ASC |
| `name` | `NAME` | **ASC (default)** |
| `rate` | `RATE` | ASC |

Invalid sort keys fall back to `name` ASC.

### Data Origin
- **Source table**: `AGENT` (DBF-synced via `tools/sync_db.py` — part of 18-table ingest)
- **Query method**: `CmtQueries::getAgents($page, $perPage, $search, $sort, $sortDir, $companyFilter)`
- **Connection**: `CompanyConnectionFactory` + `CompanyDbManager` → active company SQLite file
- **Row shape**: Raw `stdClass` rows from `CmtQueries::safeQuery()` — no Eloquent models on business tables

---

## 2. Shipper Page (`/admin/shippers`)

### Purpose
Reference listing of all shippers/forwarders from the `SHIPPER` DBF table. Shippers are the parties who consign cargo.

### Report Sections (Rendered Columns)

| # | Column Label | DBF Field / Expression | Type | Formatting |
|---|--------------|------------------------|------|------------|
| 1 | Code | `KD_SHIPPER` | string | — |
| 2 | BU | `KD_BU` | string | — |
| 3 | Name | `NAME` | string | — |
| 4 | Address | `ADDRESS1` + `ADDRESS2` + `ADDRESS3` | string | concatenated |
| 5 | Phone | `TELP` | string | — |
| 6 | Fax | `FAX` | string | — |
| 7 | Customer Type | `KD_JNSCUST` | string | badge (lookup if needed) |
| 8 | Payment Term | `TERM` | string | — |
| 9 | Account Code | `KD_ACCOUNT` | string | — |
| 10 | NPWP | `NPWP` | string | — |
| 11 | NPWP Address | `NPWP_ALAMA` + `NPWP_ALAM2` | string | concatenated |
| 12 | NPWP Date | `NPWP_DATE` | date | — |
| 13 | SIUP No | `NO_SIUP` | string | — |
| 14 | SIUP Date | `TGL_SIUP` | date | — |
| 15 | Note | `NOTE` | string | — |

### Available Filters & Search
- **Search**: Case-insensitive partial match on `KD_SHIPPER`, `NAME`, `KD_BU`
- **Company filter**: Applied for `customer` role via `companyFilter()` on `SHIPPER.NAME` column directly

### Sort Options
| Sort Key | DBF Column | Default Direction |
|----------|------------|-------------------|
| `code` | `KD_SHIPPER` | ASC |
| `name` | `NAME` | **ASC (default)** |
| `bu` | `KD_BU` | ASC |
| `type` | `KD_JNSCUST` | ASC |

Invalid sort keys fall back to `name` ASC.

### Data Origin
- **Source table**: `SHIPPER` (DBF-synced via 18-table ingest)
- **Query method**: `CmtQueries::getShippers($page, $perPage, $search, $sort, $sortDir, $companyFilter)`
- **Connection**: Active company SQLite via `CompanyDbManager`
- **Row shape**: Raw `stdClass` rows

---

## 3. Consignee / Notify Page (`/admin/consignees`)

### Purpose
Reference listing of consignees and notify parties from the `CONSIGNEE` DBF table. Single table serves both roles (consignee = cargo receiver; notify = party to notify on arrival).

### Report Sections (Rendered Columns)

| # | Column Label | DBF Field / Expression | Type | Formatting |
|---|--------------|------------------------|------|------------|
| 1 | Code | `KD_CONSIG` | string | — |
| 2 | Name | `NAME` | string | — |
| 3 | Address | `ADDRESS1` + `ADDRESS2` + `ADDRESS3` | string | concatenated |
| 4 | Phone | `TELP` | string | — |
| 5 | Fax | `FAX` | string | — |

### Available Filters & Search
- **Search**: Case-insensitive partial match on `KD_CONSIG`, `NAME`
- **Company filter**: Applied for `customer` role via `companyFilter()` (joins through shipment linkage)

### Sort Options
| Sort Key | DBF Column | Default Direction |
|----------|------------|-------------------|
| `code` | `KD_CONSIG` | ASC |
| `name` | `NAME` | **ASC (default)** |

Invalid sort keys fall back to `name` ASC.

### Data Origin
- **Source table**: `CONSIGNEE` (DBF-synced via 18-table ingest)
- **Query method**: `CmtQueries::getConsignees($page, $perPage, $search, $sort, $sortDir, $companyFilter)`
- **Connection**: Active company SQLite via `CompanyDbManager`
- **Row shape**: Raw `stdClass` rows

---

## 4. Account Page (`/admin/accounts`)

### Purpose
Reference listing of chart of accounts from the `ACCOUNT` DBF table. Used for general ledger, journal entries, and financial reporting.

### Report Sections (Rendered Columns)

| # | Column Label | DBF Field / Expression | Type | Formatting |
|---|--------------|------------------------|------|------------|
| 1 | Code | `KD_ACCOUNT` | string | — |
| 2 | Name | `NM_ACCOUNT` | string | — |
| 3 | Type | `TYPE` | string | badge (lookup: A/L/E/I/R) |
| 4 | Group | `GROUP` | string | — |
| 5 | Normal Balance | `SLD_NORMAL` | string | badge (D/K) |
| 6 | Entry Allowed | `ENTRY` | boolean | badge (Yes/No) |
| 7 | Active From | `ACTIVE` | date | — |
| 8 | Inactive From | `NOACTIVE` | date | — |
| 9 | Created | `BARU` | date | — |

### Available Filters & Search
- **Search**: Case-insensitive partial match on `KD_ACCOUNT`, `NM_ACCOUNT`
- **Type filter**: `type_filter` param — `detail` (TYPE='D') or `group` (TYPE='G')
- **Company filter**: Not typically applied (accounts are global reference data), but infrastructure supports it

### Sort Options
| Sort Key | DBF Column | Default Direction |
|----------|------------|-------------------|
| `code` | `KD_ACCOUNT` | **ASC (default)** |
| `name` | `NM_ACCOUNT` | ASC |
| `type` | `TYPE` | ASC |
| `group` | `GROUP` | ASC |

Invalid sort keys fall back to `code` ASC.

### Data Origin
- **Source table**: `ACCOUNT` (DBF-synced via 18-table ingest)
- **Query method**: `CmtQueries::getAccounts($page, $perPage, $search, $sort, $sortDir, $companyFilter, $typeFilter)`
- **Connection**: Active company SQLite via `CompanyDbManager`
- **Row shape**: Raw `stdClass` rows

---

## Shared Infrastructure (All F-28 Pages)

### Pagination
- **Pattern**: Offset-based pagination with `page` (1-indexed) and `per_page` params
- **Response shape**: `{ rows: [...], total: int, page: int, per_page: int, pages: int }`
- **Default page size**: 25 (configurable per page via `cmt.{page}_per_page`)

### Search
- Case-insensitive, partial match across designated text columns
- Uses `UPPER(col) LIKE ?` with `%{term}%`
- Combined with company filter via `AND`

### Empty State
- Filament `emptyStateHeading` + `emptyStateDescription` using translated strings from `lang/cmt/{id,en,zh}.json`
- Keys follow pattern: `"No {entity} found."` (e.g., `"No agents found."`)

### Error State
- `CmtQueries::safeQuery()` catches `Throwable` → returns `[]`
- `CmtQueries::queryOne()` returns `null` on no row or error
- Page renders empty table; no exception bubbles to UI

### Role Gating
- **All roles** (`superadmin` > `admin` > `management` > `staff` > `customer`) have access
- `customer` role receives `customer_company` filter from middleware applied at query level

---

## Data Source Architecture Summary

```
┌─────────────────────────────────────────────────────────────────┐
│                    F-28 Data Flow                                │
├─────────────────────────────────────────────────────────────────┤
│  DBF files (AGENT, SHIPPER, CONSIGNEE, ACCOUNT)                 │
│         │                                                        │
│         ▼                                                        │
│  tools/sync_db.py  (18-table ingest)                            │
│         │                                                        │
│         ▼                                                        │
│  SQLite per company  (CME.db, CMT.db, CSM.db, MAL.db, SBY.db)  │
│         │                                                        │
│         ▼                                                        │
│  CompanyConnectionFactory + CompanyDbManager                    │
│         │                                                        │
│         ▼                                                        │
│  CmtQueries::getAgents() / getShippers() / getConsignees()      │
│  / getAccounts()  →  raw stdClass rows                          │
│         │                                                        │
│         ▼                                                        │
│  Filament Page classes (App\Filament\Pages\*)                   │
│         │                                                        │
│         ▼                                                        │
│  Blade views (resources/views/filament/pages/*.blade.php)       │
│         │                                                        │
│         ▼                                                        │
│  Filament Table + TextColumn + BadgeColumn  →  rendered HTML    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Maintainer Note: Adding a New F-28 Page

To add a new reference listing page in the F-28 family:

### 1. Verify Source Table is Ingested
- Table must be in `tools/sync_db.py:_REQUIRED_TABLES` (18-table or second-layer)
- Confirm populated in all 5 companies: `python tools/dbf_inventory.py --check`

### 2. Add Query Method to `CmtQueries`
```php
// In laravel/app/Support/CmtQueries.php
public static function getNewEntity(
    int $page,
    int $perPage,
    string $search,
    string $sort,
    string $sortDir,
    string $companyFilter
): array {
    // Define allowed sort columns
    const NEWENTITY_SORT_COLUMNS = ['code', 'name', 'custom'];
    
    // Build query using safeQuery(), apply:
    // - pagination (LIMIT/OFFSET)
    // - search (UPPER(col) LIKE %term% on designated columns)
    // - sort (validate against NEWENTITY_SORT_COLUMNS, default sort)
    // - companyFilter (if applicable)
    
    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => (int)ceil($total / $perPage),
    ];
}
```

### 3. Add Config Key
```php
// In laravel/config/cmt.php
'newentity_per_page' => env('CMT_NEWENTITY_PER_PAGE', 25),
```

### 4. Create Filament Page Class
```php
// laravel/app/Filament/Pages/NewEntity.php
namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Support\CmtQueries;
use App\Support\CmtI18n;

class NewEntity extends Page
{
    protected static string $view = 'filament.pages.newentity';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Reference';
    protected static ?int $navigationSort = 10;
    
    // Implement getTableRecords() or records() hook
    // Use $this->t() for all visible strings
}
```

### 5. Create Blade View
```blade
<!-- resources/views/filament/pages/newentity.blade.php -->
<x-filament-panels::page>
    <x-slot name="heading">
        {{ __('New Entity') }}
    </x-slot>
    
    <x-filament-panels::table :records="$records" :columns="$columns">
        <!-- Column definitions -->
    </x-filament-panels::table>
</x-filament-panels::page>
```

### 6. Add i18n Keys
Add to all three locale files:
- `laravel/lang/cmt/en.json`
- `laravel/lang/cmt/id.json`
- `laravel/lang/cmt/zh.json`

### 7. Register in AdminPanelProvider
```php
// laravel/app/Providers/Filament/AdminPanelProvider.php
->pages([
    // ...
    \App\Filament\Pages\NewEntity::class,
])
```

### 8. Write Parity Test (per `filament-view-layer.md` §7)
- Blade vs Filament column output parity
- Empty state, pagination, search, sort, error state (500 on DB failure)
- Role gate verification

---

## Legacy Menu Traceability

| Legacy Menu Label (MENU2/MENU20) | PRD §3.2.6 Row | F-28 Page |
|----------------------------------|----------------|-----------|
| `List Agent` | Row 1: Agent | `/admin/agents` |
| `List Shipper/ Forwarder` | Row 2: Shipper | `/admin/shippers` |
| `List Consignee/ Notify` | Row 4: Consignee / Notify | `/admin/consignees` |
| `List Account` | Row 5: Account | `/admin/accounts` |

> **Note:** Rate by Destination (PRD §3.2.6 Row 3) is **not** an F-28 listing page — its source is `QUOTATION`/`QUOTATION_R`/`VENDOR_D` (second DBF layer). Documented separately.

---

## Related Documents

- **Build Specification** (internal): `BUILD_SPEC_F28_F29.md` (workspace) — full data contract, column sets, i18n keys
- **Recon Note** (internal): `RECON_NOTE_F28_F29.md` (workspace) — Python/FastAPI route mapping, test patterns
- **Data Provenance**: `docs/data-provenance.md` — section→source table mappings
- **DBF Coverage Matrix**: `docs/report-dbf-coverage.md` — legacy report → DBF evidence
- **Filament View Layer Decision**: `docs/decisions/filament-view-layer.md` — parity testing policy
- **Frontend Write Path Decision**: `docs/decisions/f-21-frontend-write-path.md` — POST `/sync` architecture