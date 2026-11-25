# F-29 Performance Tracker

**Route paths:** `/performance/by-shipper`, `/performance/by-shipping-lines`, `/performance/by-agent`, `/performance/by-consignee-import`

**Page purpose:** Four performance tracking surfaces showing metrics for:
- By Shipper (aggregate metrics per shipper)
- By Shipping Lines (container-level metrics by shipping line)
- By Agent (agent-level performance metrics)
- By Consignee Import Only (import-only consignee metrics)

**Visible columns:**

|| Page | Columns ||
|---|---|
| **By Shipper** (`/performance/by-shipper`) | shipment_count, revenue_usd, revenue_idr, cost_usd, cost_idr, gp_usd, gp_idr, margin_pct, avg_rate_usd |
| **By Shipping Lines** (`/performance/by-shipping-lines`) | shipment_count, revenue_usd, revenue_idr, cost_usd, cost_idr, gp_usd, gp_idr, margin_pct, avg_rate_usd |
| **By Agent** (`/performance/by-agent`) | shipment_count, revenue_usd, revenue_idr, cost_usd, cost_idr, gp_usd, gp_idr, margin_pct, avg_rate_usd |
| **By Consignee Import Only** (`/performance/by-consignee-import`) | shipment_count, revenue_usd, revenue_idr, cost_usd, cost_idr, gp_usd, gp_idr, margin_pct, avg_rate_usd |

**Available filters (per page):**

- **All pages:** search box (uppercase LIKE on relevant fields), company filter (customer company from auth)
- **By Shipper:** search on KD_SHIPPER / SHIPPER_NAME
- **By Shipping Lines:** search on VENDOR_KD_SHIPLIN / VENDOR_NAME
- **By Agent:** search on AGENT / AGENT_NAME / AGENT_CODE
- **By Consignee Import Only:** search on NA_CONSIGNEE_CODE / NA_CONSIGNEE_NAME

**Sort options (per page):**

- **By Shipper:** shipment_count, revenue_usd, revenue_idr, cost_usd, cost_idr, gp_usd, gp_idr, margin_pct, avg_rate_usd
- **By Shipping Lines:** shipment_count, revenue_usd, revenue_idr, cost_usd, cost_idr, gp_usd, gp_idr, margin_pct, avg_rate_usd
- **By Agent:** shipment_count, revenue_usd, revenue_idr, cost_usd, cost_idr, gp_usd, gp_idr, margin_pct, avg_rate_usd
- **By Consignee Import Only:** shipment_count, revenue_usd, revenue_idr, cost_usd, cost_idr, gp_usd, gp_idr, margin_pct, avg_rate_usd

**Data source (DB view / API endpoint):**

- **By Shipper:** `CmtQueries::getPerformanceByShipper()` — aggregates INVOICE metrics (GRAND_USD/GRAND_RP) by SHIPPER via SHIPPER table join
- **By Shipping Lines:** `CmtQueries::getPerformanceByShippingLines()` — aggregates INVOICE metrics by VENDOR_KD_SHIPLIN via VENDOR table (currently blocked)
- **By Agent:** `CmtQueries::getPerformanceByAgent()` — aggregates INVOICE metrics by AGENT via AGENT table
- **By Consignee Import Only:** `CmtQueries::getPerformanceByConsigneeImport()` — aggregates INVOICE metrics by NA_CONSIGNEE via NA table (blocked due to sync status)

**Acceptance checklist (per the task):**

- ✅ Route and page purpose are clear for all 4 surfaces
- ✅ Filters listed per page (search + company filter)
- ✅ Sort options listed per page
- ✅ Data origin (DB table / view) noted for each page

**Status:**
- State: todo
- Session: 
- Picked:
- Last touch: 2026-10-14
- Worker:
- Blocker: By Shipping Lines needs VENDOR table sync from DBF source (Q-11 item)