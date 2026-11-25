# CMT Dashboard Laravel Parity Capture Harness

A Laravel-based parity capture harness for CMT Dashboard rebuild. Captures HTTP responses + internal state per 20 domains × 5 roles + guest, emits canonical JSON snapshots diffed against committed baselines. See [docs/decisions/laravel-parity-harness.md](#architecture-decisions) for full design rationale.

## Install

- `composer install` (PHP 8.2+, Laravel 12, dependencies in composer.json; vendor already present)
- Copy `.env.example` → `.env` — set `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` (parity suite uses in-memory SQLite)
- `php artisan key:generate`
- Optionally populate SQLite from DBF source data:

```
DBF_DIR=data DATA_DIR=data DATABASE_URL=data/CMT.db python tools/sync_db.py
```

  (multi-company auto-detect; see Makefile `sync` target)

## Run Tests

```
php vendor/bin/phpunit --testsuite Parity
```

94 tests / 591 assertions, exit 0. Expected runtime ~92s (Laravel 12.70.0 / PHP 8.2.28). Failures surface via `tools/snapshot_diff.php`.

### Run a single capture

```
php artisan parity:capture --domain=<area> --role=<role> [--output=<path>] [--dry-run]
```

Flags: `--domain` selects parity area (one of 20), `--role` selects role (superadmin/admin/management/staff/customer/guest), `--output` writes JSON to file, `--dry-run` validates schema without writing.

## Add a New Test Case (~15 minutes)

1. **Pick area & role**: Choose from 20 domains: auth, rbac, dashboard, shipments, shipment_detail, invoices, ledger_sales, data_quality, apis, projects_jobs_summary, projects_jobs_detail, ar_reports, ap_reports, finance_reports, statement_of_account, listing_reference, performance_tracker, miscellaneous_views, ship_finance_ap_journalism, volume_lifting_gross_profit. Roles: superadmin, admin, management, staff, customer, guest.

2. **Open test file**: Under `tests/Parity/Suite/` (e.g. `ControllersParityTest.php`). For a new area, create a new file following the existing pattern.

3. **Add test method**: use `$this->asRole('staff')` / `$this->guest()` for auth, HTTP call (`$this->get('/path')` or `$this->post('/path', [...])`), then `$this->snapshotCase('area-name', 'case-descriptor', $payload)`.

   The payload (`$response->json()` or `$this->viewSnapshot($response)`) is normalized automatically: volatile keys (timestamps, tokens, request IDs) stripped; floats rounded to 4dp; keys sorted canonical JSON.

4. **Run the full parity suite** (first time for this area):

```
php vendor/bin/phpunit --testsuite Parity
```

   → One test reports: `has no baseline; regenerate with UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --testsuite Parity`

5. **Regenerate baselines**:

```
UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --testsuite Parity
```

   → exit 0, new baseline entry appears in `tests/snapshots/<area>.json`

6. **Verify with snapshot diff**:

```
php tools/snapshot_diff.php
```

   → `PARITY SNAPSHOTS: zero variance across 10 file(s) in tests/snapshots`

7. **Commit**: add the new (or updated) baseline JSON, remove `.current/<area>.json` if present.

8. **Done** — a new contributor has added a test case from scratch.

## Commands Quick Reference

| Command | Description |
|---|---|
| `php vendor/bin/phpunit --testsuite Parity` | Full parity test suite |
| `UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --testsuite Parity` | Run suite and rewrite baselines |
| `php tools/snapshot_diff.php` | Diff current vs committed, exit 0=zero variance |
| `php tools/snapshot_diff.php --file=<area>` | Diff one area only |
| `php tools/snapshot_diff.php --record` | Promote current→baseline (bulk) |
| `php artisan parity:capture --domain=<area> --role=<role>` | Capture snapshot for area+role |

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---|---|---|
| Test fails "has no baseline; regenerate with UPDATE_SNAPSHOTS=1" | First test for this area+role combo | Follow steps 5–7 above |
| `snapshot_diff` reports encoding drift | Baseline manually edited with non-sorted-keys JSON | Run `UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --testsuite Parity` |
| Unexpected values in payload | Payload contains noise keys (`csrf_token`, `cmt_session`, `request_id`, `duration_ms`) — these are stripped by VOLATILE_KEYS on save; ensure test uses `$this->asRole()` / `$this->guest()` so tokens are present as real client would send |
| 500 on `/ledger/detail` or `/shipments/detail` | Known DBF data-edge cases (StdClass cast as array, float+string coercion) | As-designed; pinned AS-IS; parity means matching Python behavior |
| "no current capture" exit 2 | Suite not yet run, or `.current/` cleared | Run `php vendor/bin/phpunit --testsuite Parity` first |
| Test suite slow (>2 min) | Missing SQLite indexes or DBF not synced | Ensure DB populated; run `python tools/sync_db.py` if cross-company data needed |

## Architecture Decisions (from Task t_c120abc4)

- **Scope**: 20 domains × 5 roles + guest = 120+ parity test cases; each domain+role combo is a test path
- **Capture Format**: JSON meta block — fields: `schema_version`, `source`, `generator`, `checklist`, `gate`, `roles`, `domains`, `captured_at`, `content_hash`, `normalization`, `known_gaps`, `fixed_bugs`, `baseline_ref`, plus per-domain: `status`, `headers`, `body`, `checksum` (SHA256 of body), `fixture`
- **Normalization**: volatile keys stripped (timestamps, tokens, request IDs per SnapshotStore::VOLATILE_KEYS); floats rounded to 4dp; keys sorted canonical JSON
- **Exit Codes** (parity_gate): 0=PARITY, 1=schema/checksum mismatch (review needed), 2=schema violation or I/O failure, 3=baseline diff (intentional change)
- **Baseline Format**: canonical JSON per `SnapshotStore::canonical()` — recursively sorted keys, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, no whitespace; `pretty()` adds `JSON_PRETTY_PRINT` + trailing newline for human readability
- **Known As-Is**: two 500s on detail pages (`/ledger/detail` StdClass-as-array, `/shipments/detail` DRCRFEX EXRATE TEXT) — not fixed for parity; documented divergences
- **Integration**: Python baseline at `tests/parity/baseline/python.json`; cross-app gate `python tools/compare_parity.py laravel/storage/app/parity/laravel.json` produces exit 0/1/2/3 for PARITY/drift/fatal/baseline_diff
- **Open Questions** (from architecture): domain count mismatch (test expects 9, baseline has 20); report domains 404 ('missing') in Laravel; cross-customer fixtures INVOICE_FOREIGN/REF3; performance target <30s for 200+ cases

## License

MIT (per root composer.json)
