# Architecture Decision Record: Laravel Parity Capture Harness

Status: decided · Owner: engineering · Parent task: t_c120abc4 · Implemented: t_c39375bd

## Context

The CMT Dashboard rebuild needs provable parity between the legacy Python/FastAPI service and the new Laravel 12 application: same HTTP statuses, same location headers, same visible body content, per role, per feature domain. The harness captures real HTML/JSON responses through a one-SQLite-DB-per-process fixture setup and diffs them against committed canonical JSON baselines.

## Decision

## Scope

- **20 parity domains**: auth, rbac, dashboard, shipments, shipment_detail, invoices, ledger_sales, data_quality, apis, projects_jobs_summary, projects_jobs_detail, ar_reports, ap_reports, finance_reports, statement_of_account, listing_reference, performance_tracker, miscellaneous_views, ship_finance_ap_journalism, volume_lifting_gross_profit.
- **5 roles + guest**: superadmin, admin, management, staff, customer, guest (company TRISCO fixture; passwords redacted in fixtures).
- **~200-case benchmark**, target <30s runtime, ≤1% baseline variance vs Python baseline.

## Capture Format

JSON capture artifact per `laravel/resources/schemas/parity-capture.schema.json`:

```
meta: {
  schema_version, source ("laravel"), generator ("php artisan parity:capture"),
  checklist ("docs/parity-checklist.yaml"), gate ("PRD section 8, phase P5"),
  roles [strings], domains [strings], captured_at (ISO8601),
  content_hash (SHA256 of sorted domains dict), normalization, features_covered,
  known_gaps, fixed_bugs, baseline_ref
}
domains: { <area>: { status, headers, body, checksum (SHA256 of body), fixture } }
```

Domain → fixture → signature mapping documented per domain.

## Normalization (run-to-run determinism)

- Volatile keys stripped recursively: `captured_at`, `created_at`, `updated_at`, `request_id`, `duration_ms`, `token`, `csrf_token`, `last_sync`, `age_seconds`, `expires_at`, `time_ms`, `started_at`, `sync_time` (SnapshotStore::VOLATILE_KEYS).
- Floats rounded to 4dp.
- Keys sorted canonical JSON (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, no whitespace); committed baselines use `pretty()` (JSON_PRETTY_PRINT + trailing newline).
- CSRF tokens and session values treated as noise; POST steps fetch + submit a token per session like a real client.

## Baseline Layout

- `tests/snapshots/<area>.json` — committed baseline (case map, canonical pretty JSON).
- `tests/snapshots/.current/<area>.json` — latest suite run capture (never committed).
- Diff via `tools/snapshot_diff.php`: exit 0 = zero variance, 1 = drift, 2 = no current capture.
- Record mode: `UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --testsuite Parity` rewrites baselines inline during the suite run.

## Exit Codes (parity_gate)

0 = PARITY (no differences), 1 = schema/checksum mismatch (manual review), 2 = schema violation or I/O failure, 3 = baseline diff (intentional change). Cross-app gate: `python tools/compare_parity.py laravel/storage/app/parity/laravel.json` vs `tests/parity/baseline/python.json`.

## Integration Points

- Artisan command: `php artisan parity:capture --domain=<area> --role=<role> [--output=<path>] [--dry-run]` (exit codes 0/1/2/3).
- `phpunit.xml`: `tests/Parity` suite, sqlite `:memory:`, `BCRYPT_ROUNDS=4`.
- `tools/sync_db.py` populates SQLite from DBF source (read-only source of truth; no writes back).

## Known As-Is

- `GET /ledger/detail` 500 (StdClass used as array).
- `GET /shipments/detail` 500 (string + float, DRCRFEX EXRATE TEXT).
Both pinned as-is; parity means matching Python behavior, not fixing during rebuild. Handled by follow-up card.

## Open Questions (unresolved)

- Domain count mismatch: test expects 9 domains, baseline has 20.
- Report domains return 404 ('missing') in Laravel.
- Cross-customer fixtures INVOICE_FOREIGN / REF3 handling.
- Performance target <30s for 200+ cases — current suite ~92s for 94 tests / 591 assertions.

## References

- `docs/decisions/p5-parity-diff-contract.md` — p5.js pixel-frame harness (different harness; do not conflate).
- `docs/decisions/p5-parity-gate.md` — cross-app meta-block gate ADR.
- `LARAVEL_PARITY_HARNESS_ARCHITECTURE.md` (kanban attachment t_c120abc4) — full capture architecture, 20-domain scope, open questions.
- `laravel/README.md` — install/run/add-test-case/snapshot-update workflow for this harness.