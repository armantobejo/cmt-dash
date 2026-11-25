# Statement-of-account test defect report

Source: collected failures from `tests/statement/` (statement-of-account). Collected 2026-11-23.
Scope: Python/FastAPI app under `src/app/`; Laravel app has no statement tests.
Interpreter: `python`/`python3` on PATH = 3.14.9 **without pytest**; use `py -3.13` (3.13.7, pytest 9.1.1).
Status: **collect only — no fixes made.** Test-side defects (test bugs) outnumber app-side defects.

Run baseline:
```powershell
cd /d/PROJECTS/cmt-dash
py -3.13 -m pytest tests/statement/test_statement_api.py -v --tb=long -p no:cacheprovider   # 20 passed, exit 0
py -3.13 -m pytest tests/statement -q --tb=line -p no:cacheprovider                          # 18 failed, 30 passed, 13 errors, exit 1
```

| ID | Title | Severity | Where |
|----|-------|----------|-------|
| DEF-01 | `app_with_mocked_service` fixture: `NameError: name 'patch' is not defined` | major | `tests/statement/test_statement_pagination.py:16` (missing `from unittest.mock import patch`) |
| DEF-02 | Filtering tests call HTML page route `/statement-of-account` but assert API/JSON semantics | major | `tests/statement/test_statement_filtering.py` (17 tests) |
| DEF-03 | `logged_in` fixture: POST /login returns 429 instead of 302 (rate limiter blocks login) | blocker | `tests/statement/conftest.py:logged_in` (~25 setup errors) |
| DEF-04 | Rendering skeleton test asserts server-rendered table markup the page does not emit | minor | `tests/statement/test_statement_rendering.py:87` |
| DEF-05 | API returns 422 for invalid query params where spec requires 400 | minor | `src/app/main.py:986-995`; spec `kanban/todo/statement-of-account-page.md:97-99,:184` |
| DEF-06 | API test names claim `_returns_400` but assert `== 422` | minor | `tests/statement/test_statement_api.py:269,282,295,308,321` |

---

## DEF-01 — Missing `patch` import breaks all pagination tests (major)

**Location:** `tests/statement/test_statement_pagination.py:16` — fixture `app_with_mocked_service` body uses `with patch("app.statement_of_account_service.get_statement_of_account") as mock_get:` but the module only imports `pytest` (line 9); no `from unittest.mock import patch`.

**Repro:**
1. `cd /d/PROJECTS/cmt-dash`
2. `py -3.13 -m pytest tests/statement/test_statement_pagination.py -q`
3. Observe 13 ERRORs at fixture setup: `NameError: name 'patch' is not defined`

**Expected:** fixture `app_with_mocked_service` yields a client whose `statement_of_account_service.get_statement_of_account` is mocked; all 13 `TestStatementPagination` tests run.

**Actual:** fixture setup dies; all 13 tests ERROR: `test_pagination_next_control`, `test_page_zero_rejected`, `test_page_negative_rejected`, `test_per_page_zero_rejected`, `test_per_page_above_max_rejected`, `test_per_page_one_accepted`, `test_page_clamping_to_total`, `test_page_zero_rejected_explicit`, `test_page_negative_rejected_explicit`, `test_page_above_max_rejected_explicit`, `test_page_default_resets_to_one`, `test_page_clamp_high_page_number`, `test_page_no_data_returns_empty_transactions`.

**Suspected fix:** add `from unittest.mock import patch` to `tests/statement/test_statement_pagination.py` imports.

---

## DEF-02 — Filtering tests target HTML page route instead of API (major)

**Location:** `tests/statement/test_statement_filtering.py` (all 17 tests). Tests `GET /statement-of-account` (`src/app/main.py:847-867`) — the HTML renderer — but assert service-call capture and 400 validation semantics that only `GET /api/reports/statement-of-account` has. The page route never calls `statement_of_account_service.get_statement_of_account`; it clamps bad values instead of rejecting them.

**Repro:**
1. `cd /d/PROJECTS/cmt-dash`
2. `py -3.13 -m pytest tests/statement/test_statement_filtering.py -q`
3. Observe 17 failures:

   - `test_date_from_filter_updates_results` (`:68`): `AssertionError: assert 0 == 2` — `mock_get.call_args_list == []`, service never called.
   - `test_date_to_filter_updates_results` (`:97`): `IndexError: list index out of range` on `calls[0].kwargs["date_to"]`.
   - `test_company_filter_changes_data_source` (`:121`): `AssertionError: assert captured == ["CMT","CME"]` — got `[]`.
   - `test_account_filter_changes_query` (`:144`): `AssertionError: assert captured == ["SCA","SCB"]` — got `[]`.
   - `test_currency_filter_changes_display`, `test_per_page_filter_changes_pagination`, `test_sort_dir_filter_changes_order`, `test_filter_form_submission_resets_page_to_1`, `test_invalid_company_rejected`, `test_missing_account_rejected`, `test_missing_date_from_rejected`, `test_missing_date_to_rejected`, `test_date_from_after_date_to_rejected`, `test_date_span_gt_366_rejected`, `test_invalid_currency_rejected`, `test_invalid_per_page_rejected`, `test_invalid_sort_dir_rejected` (`:255,:263,:271,:279,:288,:297,:306,:315,:329`): `AssertionError: assert 200 == 400` ×9 — page route clamps (default dates, `currency` fallback to "USD", `per_page=max(1,min(per_page,500))`) instead of returning 400.

**Expected:** tests call `GET /api/reports/statement-of-account`; service mock is called with forwarded params; invalid params return 400.

**Actual:** tests call HTML page route; `call_args_list` always empty; invalid params yield 200 with clamped values.

**Suspected fix:** change test endpoint from `/statement-of-account` to `/api/reports/statement-of-account`; assert against the API route.

---

## DEF-03 — `logged_in` fixture rate-limited by POST /login 429 (blocker)

**Location:** `tests/statement/conftest.py:logged_in`. Fixture logs the test client in via `POST /login`; the rate limiter returns `429 Too Many Requests` instead of `302`, so fixture setup asserts fail and ~25 tests never run.

**Repro:**
1. `cd /d/PROJECTS/cmt-dash`
2. `py -3.13 -m pytest tests/statement -q --tb=line -p no:cacheprovider`
3. Observe fixture-setup `AssertionError`s affecting `TestStatementRendering` (5), `TestStatementRenderingWithData` (1), `TestStatementFilteringValidation` (9), `TestStatementPagination` — all tests requiring an authenticated client (~25 setup errors).

**Expected:** `POST /login` returns `302` (or 200) in tests; authenticated client available; affected tests execute.

**Actual:** `POST /login` returns `429`; fixture setup errors; ~25 tests do not run.

**Suspected cause:** test-client rate limiter not disabled/bypassed in test config.

---

## DEF-04 — Rendering skeleton test asserts server-rendered table markup (minor)

**Location:** `tests/statement/test_statement_rendering.py:87` — `test_page_renders_table_skeleton`.

**Repro:**
1. `cd /d/PROJECTS/cmt-dash`
2. `py -3.13 -m pytest tests/statement/test_statement_rendering.py -q`
3. Observe failure: `AssertionError: assert ('<th>Date</th>' in html) or ("{{ _t('Date') }}" in html)` — table header missing.

**Expected:** `GET /statement-of-account` returns 200 HTML containing `id="soa-table"`, `<th>Date</th>`, `<th>Debit</th>`, `id="rows"`, "Loading".

**Actual:** GET returns 200 with different markup; server-rendered table elements not present (may be built by browser JS).

**Suspected cause:** `statement_of_account.html` moved table construction to client-side JS; test asserts stale server-rendered markup. Not investigated further.

---

## DEF-05 — API returns 422 where spec requires 400 (minor)

**Location:** `src/app/main.py:986-995` — typed `Query(...)` constraints in the handler signature (`account: str = Query(...)`, `page: int = Query(1, ge=1)`, `per_page: int = Query(100, ge=1, le=500)`). FastAPI/Pydantic validation fires before the handler and returns **422 Unprocessable Entity**.

**Spec:** `kanban/todo/statement-of-account-page.md:97-99` and `:184` require **400** for invalid query params.

**Repro:**
1. `cd /d/PROJECTS/cmt-dash`
2. `py -3.13 <ws>/probe_api.py` (or curl the TestClient):
   - `GET /api/reports/statement-of-account` → `422 {"type":"missing","loc":["query","account"],"msg":"Field required"}`
   - `GET ...?account=1101&page=abc` → `422 {"type":"int_parsing",...}`
   - `GET ...?account=1101&per_page=xyz` → `422 {"type":"int_parsing",...}`
   - `GET ...?account=1101&page=-1` → `422 {"type":"greater_than_equal","ctx":{"ge":1}}`
   - `GET ...?account=1101&per_page=-5` → `422 {"type":"greater_than_equal","ctx":{"ge":1}}`
   - `GET ...?account=1101&per_page=501` → `422 {"type":"less_than_equal","ctx":{"le":500}}`
3. Contrast handler-level checks that do return 400 as specced:
   - `?date_from=2024-13-45` → `400 {"detail":"month must be in 1..12"}`
   - `?date_from=2024-05-01&date_to=2024-01-01` → `400 {"detail":"date_from after date_to"}`
   - `?date_from=2020-01-01&date_to=2024-01-01` → `400 {"detail":"date span > 366 days"}`
   - `?currency=EUR` → `400 {"detail":"invalid currency"}`
   - `?sort_dir=sideways` → `400 {"detail":"invalid sort_dir"}`

**Expected:** invalid query params return 400 per spec.

**Actual:** missing account / non-numeric / out-of-range `page`/`per_page` return 422 (FastAPI validation); only handler-level checks return 400.

**Suspected fix:** replace typed `Query(...)` constraints with optional params + manual 400 raises in `src/app/main.py:986-995`, or add an exception handler mapping 422 → 400 for this route. Product decision: spec vs implementation side.

---

## DEF-06 — API test names claim 400 but assert 422 (minor)

**Location:** `tests/statement/test_statement_api.py:269,282,295,308,321`.

**Repro:**
1. `cd /d/PROJECTS/cmt-dash`
2. `py -3.13 -m pytest tests/statement/test_statement_api.py -v --tb=long -p no:cacheprovider`
3. Observe `test_missing_account_returns_400`, `test_pagination_with_non_numeric_page_returns_400`, `test_pagination_with_non_numeric_per_page_returns_400`, `test_pagination_with_negative_page_returns_400`, `test_pagination_with_negative_per_page_returns_400` all PASS while asserting `== 422`.

**Expected:** test names match behavior (400) or assertions match names; tests green under one consistent contract.

**Actual:** five tests named `..._returns_400` assert `response.status_code == 422`; green tests encode 422 while names and spec say 400.

**Suspected fix:** either change assertions to `== 400` (after DEF-05 app fix) or rename tests to `..._returns_422`. Product decision — not made here.

---

## DEF-07 — UI task: all 17 statement_ui tests fail (untracked; event-loop conflict)

**Severity:** blocker — whole UI suite red, no report entry existed before this verification.

**Location:** `tests/statement_ui/` — test_statement_ui.py (6), test_statement_rendering.py (2), test_statement_pagination.py (2), test_statement_filtering.py (1), test_statement_loading.py (1), test_statement_empty.py (1), test_statement_error.py (2); all parametrized `[chromium]`.

**Repro:** `py -3.13 -m pytest tests/statement_ui -q --tb=line -p no:cacheprovider` → 17 failed. Every test raises `RuntimeError: Runner.run() cannot be called from a running event loop` inside pytest-playwright/pytest-asyncio under asyncio strict mode; no test body assertion is reached.

**Coverage before this verification:** NONE — all 17 UI-task failures lacked a report entry and an explicit fixed note. This entry closes that gap.

**Suspected cause:** environment/plugin conflict, not per-test logic: pytest-playwright drives its own browser event loop while `@pytest.mark.asyncio` tests run under pytest-asyncio strict mode; `Runner.run()` cannot nest. Confirm plugin versions/config before per-test triage.

**Tests affected:**
- test_statement_ui.py: test_statement_table_loads_with_correct_columns, test_statement_filtering_updates_results, test_statement_pagination_next_prev, test_statement_pagination_page_jump, test_statement_loading_spinner_shown_while_fetching, test_statement_empty_state_message, test_statement_error_message_on_api_failure, test_statement_error_message_no_transactions
- test_statement_rendering.py: test_statement_table_loads_with_correct_columns, test_statement_table_shows_data_columns
- test_statement_pagination.py: test_pagination_next_page_loads, test_pagination_prev_page_loads
- test_statement_filtering.py: test_date_range_filter_updates_results
- test_statement_loading.py: test_loading_spinner_shown_while_fetching
- test_statement_empty.py: test_empty_state_message_shown
- test_statement_error.py: test_server_error_shows_message, test_retry_reload_resubmits

---

## Investigated and withdrawn (NOT defects)

- `unknown-account` 500 `no such table: SHIPPER` — probe-DB artifact only; under `tests/conftest.py` seeded fixture, `test_unknown_account_returns_404` passes and the endpoint returns 404. Do not file.
- `IndexError: list index out of range` and `assert 0 == 2` at `test_statement_filtering.py:68,:97` — downstream of DEF-02, not separate defects.

## Summary

- App-side defects: **1** (DEF-05, minor contract mismatch).
- Test-side defects: **5** (DEF-01 major, DEF-02 major, DEF-03 blocker, DEF-04 minor, DEF-06 minor).
- UI-task failures untracked before this verification: **17** (DEF-07 blocker — event-loop conflict, all `tests/statement_ui/`).
- DEF-03 (rate-limited 429) has an entry but did **not** reproduce in this re-run (`POST /login` → 302); keep entry, treat as flaky/environment-dependent.
- No repo files modified. No fixes attempted.
