# F-28 listing state: automated test report (filters / sort / pagination / URL persistence)

Scope: the four F-28 listing routes — `/agents`, `/shippers`, `/consignees`, `/accounts`
(`docs/f-28-listing-components-and-state.md`, `docs/f-28-listing-reference.md`).
`/rate-by-destination` is a separate page/spec and is out of scope here.

## Deliverables

| What | Where |
|---|---|
| New test module (102 tests) | `tests/test_f28_listing_state.py` |
| Test-harness fix (BUG-2) | `tests/conftest.py` (`ACCOUNT` fixture table) |

Run:

```bash
py -3.13 -m pytest tests/test_f28_listing_state.py -q
py -3.13 -m coverage run --data-file=.cov_f28 -m pytest tests/test_f28_listing_state.py -q
py -3.13 -m coverage report --data-file=.cov_f28 --include="*/src/app/*"
```

(`py -3.13` — `python3` on this host is 3.14 without `fastapi`; the suite's deps live in 3.13.)

## Result

`99 passed, 3 xfailed` (the 3 xfails are BUG-1, below). No other module regressed:
`tests/test_db_safety.py tests/test_detail_sections.py tests/test_f28_listing_state.py` → 111 passed.
Suite-wide 88 failures are **pre-existing cross-module state pollution**, unchanged by this module:
they are identical with `--ignore=tests/test_f28_listing_state.py` (88 failed, 132 errors) and without it.

## Coverage

Module-scoped (`tests/test_f28_listing_state.py` alone), `--include=*/src/app/*`: **44.9 %** of
`src/app` — expected, since the module exercises only the four F-28 handlers. Across the app suite
(`tests/`, `--ignore=tests/parity --ignore=tests/statement_ui`, which has duplicate module basenames):
**70.9 %** of `src/app` (1964 stmts, 572 missed; `main.py` 63.3 %, `queries.py` 67.9 %).

The F-28 surface itself is **fully exercised**:

| Target | Uncovered statements |
|---|---|
| `main.py` `agents_page` / `shippers_page` / `consignees_page` / `accounts_page` | none (all handler bodies executed) |
| `queries.py` `get_accounts` (996–1046) | none |
| `queries.py` `get_agents` (1479–1495) + `get_shippers` (1497–1513) | none |
| `queries.py` `get_consignees` (1575–1591) | none |

Coverage is asserted by test outcome, not by line count: every expectation is derived from the
seed data (`_expected()` re-implements the spec contract in Python and the rendered rows are
compared to it), so an unnoticed branch cannot pass.

## Test inventory

| Area | Tests | What is pinned |
|---|---|---|
| Search filters | 25 | each spec search field filters (`CODE`/`NAME`/`AGENT`, `KD_SHIPPER`/`NAME`, `KD_CONSIG`/`NAME`, `KD_ACCOUNT`/`NM_ACCOUNT`); case-insensitive; blank/whitespace = all rows; no match → empty state; markup escaped |
| Accounts `type_filter` | 4 | `detail`/`group` restrict rows; unknown value ignored; combines with search |
| Sort | 25 | default sort per page (`name`,`name`,`name`,`code`); every allow-listed key × asc/desc; `type` treated as a partition (ties); unknown key not reflected into SQL; unknown `dir`; active-column header toggles direction |
| Pagination | 20 | 30 seeded rows → `Page 1 / 2`; page 1 + page 2 = all rows, no overlap/dupes; `page=999` clamps; `page=0/-3` → 422; `per_page` param inert (server owns the page size); paginator hidden at one page |
| URL state persistence | 24 | paginator links carry `page`+`q`+`sort`+`dir`(+`type_filter`) and following one keeps the search/sort; refresh reproduces identical output; filter form is GET, carries `sort`/`dir`, never `page` (search resets to page 1); state is route-scoped; `project_id` is inert (these routes have no project context); no `localStorage`/`sessionStorage` listing state |
| Access control | 1 | all four routes redirect unauthenticated requests (303) |
| BUG-1 regression guard | 3 | `xfail` — see below |

Total 102 collected (99 pass + 3 xfail).

## Bugs

### BUG-1 — unknown `sort` value ignores the page default (spec deviation, low impact)

Spec (`docs/f-28-listing-components-and-state.md` §F-28 behaviour): *"Sort keys must be
allow-listed; invalid values fall back to that page's default."* Agents/Shippers/Consignees default
to `name ASC`, but an unknown `sort` falls back to the **code** column instead.

Reproduce (with a logged-in client, 30 seeded rows):

```
GET /agents               -> name ASC   (correct default)
GET /agents?sort=nope     -> code ASC   (spec says name ASC)
```

Root cause: `src/app/queries.py:1493`, `:1511`, `:1589` —
`_AGENTS_SORT_COLUMNS.get(sort, "a.CODE")` (and `.get(sort, "s.KD_SHIPPER")`,
`.get(sort, "c.KD_CONSIG")`); the fallback is hardcoded to each table's code column. `/accounts`
is unaffected (its default *is* code) and `get_rate_by_destination` is out of scope.

Impact: display order only for a malformed/hand-typed `sort` value; no injection, no data exposure
(the allow-list is enforced before the SQL string is built — verified).
Regression tests: `test_bug_unknown_sort_key_ignores_page_default` (`xfail`, 3 cases) plus
`test_unknown_sort_key_is_not_reflected_into_sql` (passes today, guards the security property).
Fix hint: pass the page default into `get_*` and use it as the fallback, or map unknown keys to
`spec_default` in the handler.

### BUG-2 — test harness could not render `/accounts` at all (fixed)

`tests/conftest.py` created the fixture `ACCOUNT` table with only 4 columns
(`KD_ACCOUNT, NM_ACCOUNT, SLD_NORMAL, TYPE`), but `get_accounts` selects `a."GROUP", a.ACTIVE`, so
**every** `/accounts` request in the test suite raised
`sqlite3.OperationalError: no such column: a.GROUP` → HTTP 500.

Reproduce (before the fix): add a logged-in client test doing `GET /accounts` → 500.

Fixed in `tests/conftest.py`: the fixture table now matches the documented source columns
(`docs/f-28-listing-reference.md` §data source): `KD_ACCOUNT, NM_ACCOUNT, SLD_NORMAL, TYPE,
"GROUP", ENTRY, ACTIVE, NOACTIVE`. This is why the module seeds `GROUP`/`ACTIVE` values — those
columns now exist and are rendered by `/accounts`.

### BUG-3 — spec doc inconsistency for Shipper search fields (docs only, no code change)

`docs/f-28-listing-components-and-state.md` line 16 lists Shippers search fields as
`KD_SHIPPER`, `NAME`, `KD_BU`; `docs/f-28-listing-reference.md` line 21 and the implementation use
`KD_SHIPPER`, `NAME`. The tests follow the reference doc + implementation. One of the two docs
should be corrected.

## Deliberate non-findings

- `tests/test_agents_listing.py::test_pagination_no_duplicates_across_pages` passes `per_page=2`
  expecting 3 pages, but the param is ignored and `per_page` is 25 — the test passes vacuously.
  Superseded here by `test_page_size_is_server_controlled` + `test_pagination_splits_rows_without_overlap`;
  the stale test was left untouched (pre-existing defect-report scope).
- `/accounts` renders `Group` twice (sortable header + a trailing literal `<th>`); cosmetic
  duplication in `accounts.html`, not covered by an assertion here (out of this task's scope).
- Function-level defaults in `queries.py` (50/`code`) still differ from the spec (25/`name`); routes
  pass the spec constants, so the UI is spec-correct — verified by
  `test_page_size_is_server_controlled` and `test_default_sort_matches_spec`.
