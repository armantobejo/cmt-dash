# F-28 listing: component inventory and state persistence

## Scope and sources

F-28 is the Listing Reference family: Agents (`/agents`), Shippers (`/shippers`), Consignees/Notify (`/consignees`), and Accounts (`/accounts`). Rate by Destination has its own page/spec and is not part of these four listings. Reference sources: `BUILD_SPEC_F28_F29.md` §§2–3, 8; `docs/f-28-listing-reference.md`; current Python/Jinja templates and handlers in `src/app/templates/{agents,shippers,consignees,accounts}.html` and `src/app/main.py`.

The build spec describes desired behavior; current rendered pages are server-side Jinja2/HTML, not a client-side component framework. No reusable FilterBar, SortControl, or Paginator component is currently extracted. The matching UI patterns are duplicated in each template.

## F-28 behavior from spec

All four pages list read-only DBF-synced reference rows with case-insensitive partial search, allow-listed sorting, offset pagination, translated labels, and an empty state. Query/data contract: `{rows, total, page, per_page, pages}`. Company scoping is required where applicable for customer users; reference-data scoping still needs a policy decision (global vs shipment-linked records, noted as an ambiguity in the build spec §5).

| Page | Search fields | Allowed sort keys | Spec default sort | Page size in build spec | Extra filter |
|---|---|---|---|---:|---|
| Agents | `CODE`, `NAME`, `AGENT` | `code`, `name`, `agent`, `rate` | `name ASC` | 25 (`cmt.agents_per_page`) | — |
| Shippers | `KD_SHIPPER`, `NAME`, `KD_BU` | `code`, `name`, `bu`, `type` | `name ASC` | 25 (`cmt.shippers_per_page`) | — |
| Consignees | `KD_CONSIG`, `NAME` | `code`, `name` | `name ASC` | 25 (`cmt.consignees_per_page`) | — |
| Accounts | `KD_ACCOUNT`, `NM_ACCOUNT` | `code`, `name`, `type`, `group` | `code ASC` | 25 (`cmt.accounts_per_page`) | `type_filter` (`detail` / `group`) is present in current page contract; confirm desired filter values against query implementation |

Search results combine with the applicable company scope. Sort keys must be allow-listed; invalid values fall back to that page's default. Pagination is offset-based, with `page >= 1`; the build spec does not define a visible page-size selector (the server/config owns page size).

## Existing UI inventory (not extracted reusable components)

| Pattern | Current implementation and inputs | User action / event | State carried |
|---|---|---|---|
| Filter bar / search | Inline `<form method="get">` in each listing template. Inputs: `q` search text; Accounts also has `type_filter`. Search form has hidden `sort` and `dir`. Placeholder/labels use `_t()`. | Native GET form submit; full-page request and server render. Search submit omits `page`, naturally returning to first page. No custom JS event. | `q`, `sort`, `dir`; plus `type_filter` on Accounts. Company filter is specified but not visibly implemented in these templates. |
| Sort control | Inline Jinja `sort_header(key, label)` macro repeated in each template. Context: `sort`, `sort_dir`, `search`; Accounts additionally uses `type_filter`. Emits sortable header links with direction icon. | Anchor GET navigation; same key toggles direction (`desc` → `asc`), a new key defaults to `desc` in the current macro. Full-page request. | `q`, `sort`, `dir`; Accounts also preserves `type_filter`. It does not include current `page`, so changing sort starts at page 1. |
| Paginator | Inline page-number `<nav>` repeated in each template. Context: result `page`/`pages`, `page_nums`, `search`, `sort`, `sort_dir`; Accounts also `type_filter`. Rendered only when `pages > 1`. | Page-number anchor GET navigation; full-page request. Active page marked in markup. No separate previous/next buttons or page-size control. | `page`, `q`, `sort`, `dir`; Accounts also `type_filter`. |

**No component props/events exist today.** For implementation tickets needing a reusable contract, use these minimal server-rendered inputs and browser-native events; do not imply an Alpine/React/Vue component exists:

- **FilterBar inputs:** `action`, `search_value`, `search_placeholder`, optional `type_filter_value`/options, current `sort`/`dir`, plus project context if page is project-scoped. Submit is native GET; no emitted JS event.
- **SortControl inputs:** allow-listed `key`, display `label`, current `sort`/`dir`, preserved filter values/context. Link navigation is native GET; no emitted JS event.
- **Paginator inputs:** `page`, `pages`, `page_nums`, preserved filters/sort/context. Page link is native GET; no emitted JS event.

## Current implementation differences to keep visible

The Python handlers in `src/app/main.py` currently pass fixed `per_page=50`, default to `sort=code` and `dir=asc`, and expose `q` as the search parameter. These differ from the build spec's 25-row config defaults and name-sort defaults on Agents, Shippers, and Consignees. Current templates don't render a company filter control. Treat the build spec as target contract; reconcile these differences before claiming exact parity. State links currently preserve `q`, `sort`, `dir` and Account `type_filter`, but there is no `per_page` UI/state.

## Persistence decision: URL query state, project-scoped when applicable

Use URL query parameters as the canonical listing state. This matches existing server-rendered GET forms/links, supports refresh, browser back/forward, bookmarks, and sharing, and avoids competing URL/localStorage state.

Canonical state keys:

```text
project_id=<stable project ID>   # only on project-scoped listings
page=1
per_page=<allowed page size>    # include only if user can change page size
q=<search text>
sort=<allow-listed key>
dir=asc|desc
<page-specific filters>         # e.g. type_filter=detail|group
```

Preserve `project_id` and all active filters/sort in form actions and paginator/sort links. When `project_id` changes, reset `page` to 1; do not carry the prior project's search/filter/sort/page state unless product explicitly requests it. Validate project ID against the authenticated user's authorization on the server; URL state is not authorization. Validate all query values and apply safe defaults for unknown values.

For these four F-28 master-data routes, there is currently no project context or `project_id`; scope state by route (`/agents`, `/shippers`, `/consignees`, `/accounts`) and use only applicable query parameters. Do not add a synthetic project ID. If a future listing is inside project context, include its stable project ID in the URL (prefer a path segment if route design allows; otherwise `project_id` query parameter) and preserve it in every generated link.

Do not persist search, sort, or page in localStorage: it can silently override a copied URL and leak state between projects/users on a shared browser. Existing global localStorage keys (`cmt_theme`, `cmt_sidebar_narrow`) are UI preferences, not listing state. If a future requirement needs a non-shareable preference, namespace it by user, page, and project, e.g. `cmt:list:<user-id>:<page-slug>:<project-id>`, and keep URL values authoritative whenever present.

## Implementation checklist

- Keep every filter/sort/page action a GET and preserve relevant query state.
- Reset page on search, filter, sort-key, or project changes.
- Keep page size server/config-controlled unless spec adds a selector; enforce allowed sizes if exposed.
- Confirm company-scoping policy for reference rows and enforce it server-side.
- Reconcile current Python defaults (50/code) with spec defaults (25/name for three pages) before treating UI as spec-complete.
- Keep empty-state, labels, and search placeholders translated.
