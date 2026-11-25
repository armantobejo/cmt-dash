# F-22 Projects / Jobs — Summary

## Route Path
- `GET /projects-jobs` — merged CARGO + NA header view (summary listing)

## Page Purpose
Summary listing of projects/jobs showing B/L status, invoice, operational cost, DR/CN FCL, and DR/CN LCL columns per shipment, enabling operators to review job-level financial and operational status at a glance.

## Visible Columns
- B/L status, Invoice, Operational Cost, DR/CN FCL, DR/CN LCL (plus shipment reference fields)

## Filters & Sort Options
- Search (`q`): hits REFSHITMEN, BLNO3, consignee/shipper names
- Type filter (`type`): import / export / other
- Sort combos: date | reference | customer × asc | desc
- Pagination: defaults and page-size selector
- Company filter: customer role sees only own-company rows; staff+ see all

## Data Source
Merged CARGO + NA header view (second-layer tables), queried via `q.get_projects_jobs_summary()`; company-scoped by `user.customer_company`.

## Acceptance Criteria
- Route and page purpose documented; default view matches both apps (rows, order, pagination)
- Search, type filter, sort combos, and pagination verified against source
- Company scoping verified for customer vs staff roles

*Placed in docs/f-22-projects-jobs-summary.md for developers, analysts, and stakeholders.*
