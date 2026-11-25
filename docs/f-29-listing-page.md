# F-29 Listing Page (Performance Tracker)

## Route Path
- **By Shipping Lines**: `/performance/by-shipping-lines`  
- **By Shipper**: `/performance/by-shipper`  
- **By Agent**: `/performance/by-agent`  
- **By Consignee (Import Only)**: `/performance/by-consignee-import`

> *Note: The Shipping Lines and Consignee Import pages are currently blocked due to missing VENDOR and NA table syncs.*

## Page Purpose
Provides a performance overview grouped by the selected dimension (shipping line, shipper, agent, or consignee). Displays key metrics (shipment count, revenue, costs, gross profit, margin, average rate) for the chosen grouping, enabling operators to monitor operational efficiency and identify outliers.

## Visible Columns

| Grouping | Columns (left‑to‑right) |
|----------|------------------------|
| **By Shipping Lines** | Shipping Line Code, Shipping Line Name |
| **By Shipper** | Shipper Code, Shipper, Shipment Count, Revenue (USD), Revenue (IDR), Operational Cost (USD), Operational Cost (IDR), Gross Profit (USD), Gross Profit (IDR), Margin %, Avg Rate (USD) |
| **By Agent** | Agent, Agent Code, Name, Shipment Count, Revenue (USD), Revenue (IDR), Operational Cost (USD), Operational Cost (IDR), Gross Profit (USD), Gross Profit (IDR), Margin %, Avg Rate (USD) |
| **By Consignee (Import Only)** | Consignee Code, Consignee Name |

*All monetary values are formatted as currency (USD or IDR) according to the site’s locale settings.*

## Filters & Sort Options

- **Search**: Available on the primary key column (e.g., “Shipper Code”, “Agent”, “Consignee Code”). Implemented via Filament’s built‑in searchable column feature.
- **Sort**: Clickable column headers enable ascending/descending sort on the following fields:
  - Shipment Count
  - Revenue (USD / IDR)
  - Operational Cost (USD / IDR)
  - Gross Profit (USD / IDR)
  - Margin %
  - Avg Rate (USD)
- **Pagination**: Configurable per‑page sizes (25, 50, 100) via the `cmt.performance_per_page` configuration.
- **Company Filter**: When applicable (Shipper, Agent), the page automatically limits data to the logged‑in user’s assigned company (`app(CmtUser::class)->customerCompany`).

## Data Source
The underlying data is derived from the following database tables (as defined in `tools/sync_db.py` `_REQUIRED_TABLES`):

- **By Shipper** – `INVOICE`, `SHIPPER`, `NA.SHIPPER`
- **By Shipping Lines** – `VENDOR` (shipping‑line master) or `VESSEL.NM_VESSEL`
- **By Agent** – `CARGO.AGENT`, `NA.AGENT`, `AGENT`
- **By Consignee (Import Only)** – `NA.CONSIGNEE`, `CONSIGNEE`

All metrics are calculated in the backend query (`CmtQueries::getPerformanceBy…`) and passed to the view as a plain array of rows.

## Acceptance Criteria
- **Route & Purpose**: Clearly documented above; each grouping page URL and its business intent are explicit.
- **Filters/Sort**: Search, sortable columns, pagination, and company filter are listed and verified against the source code.
- **Data Origin**: Table sources are explicitly noted for each grouping.

*Document placed in `docs/f-29-listing-page.md` for reference by developers, analysts, and stakeholders.*