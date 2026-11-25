# Decision: P5 parity-diff contract (Laravel vs Python baseline)

Status: decided 2026-09-22 · Owner: engineering · Phase gate: PRD §8 P5

## The gate

The P5 parallel-run exit criterion is mechanical, not manual:

```
# Laravel side implements the capture harness (this spec), emitting:
laravel/storage/app/parity/laravel.json

# One command decides the gate:
python tools/compare_parity.py laravel/storage/app/parity/laravel.json
#   exit 0 = PARITY  (diff tool: tools/compare_parity.py)
#   exit 1 = differences (precise JSON paths printed)
```

The Python baseline is committed at `tests/parity/baseline/python.json`
(regenerated after the KG-5/KG-6 fixes, so the parity target is **fixed
behavior**). The Laravel side must produce a JSON that is structurally
identical — `meta` + `domains`, same domain names, same keys per domain.

## Who builds what on the Laravel side

| Piece | Port from | Location |
|---|---|---|
| Dataset builder (synthetic 2-company data + hand-rolled DBF writer) | `tests/parity/parity_dataset.py` | `laravel/tests/Parity/ParityDataset.php` |
| Capture actions (login/logout, page signatures) | `tests/parity/parity_actions.py` | `laravel/tests/Parity/ParityActions.php` |
| 9 capture domains (route-for-route) | `tests/parity/test_parity_baseline.py` | `laravel/tests/Parity/ParityBaselineTest.php` |
| Runner invocation | `pytest tests/parity` | `php artisan parity:capture` (command writing the JSON) |

The route list in `test_parity_baseline.py` is the port spec: every
`client.get(...)`/`client.post(...)` line must have a Laravel twin, in the
same order, under the same domain key.

## Response normalization (must match Python's capture layer)

Cross-cutting rule: primitives shared with the Python app (hashes,
timestamps, JSON encoding, cookies) are pinned by the anti-circular vector
suite — see `docs/decisions/cross-app-primitive-parity.md`.

From `test_parity_baseline.py` — the Laravel capture must replicate:

- **Page signatures**: `status` + `location` (redirects); for 200 HTML:
  `data_strings` (visible text nodes, whitespace-collapsed, pipes stripped)
  and `rows_sample` (first `<table>`'s first 10 `<tr>`'s, td-cells only).
- **Noise stripped**: `cmt_session`, `token`, `csrf_token`, `request_id`,
  `duration_ms`, `asset_ver`, `timing`, `age_seconds`, `last_sync`.
- **`<SYNC_TIME>` tokenization**: `base.html` renders the DB file mtime as
  `%Y-%m-%d %H:%M` — any string matching `^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$`
  becomes `<SYNC_TIME>`.
- **Floats rounded to 4 dp** (JSON responses).
- **CSRF tokens**: Laravel forms carry `_token`; the Python forms do not.
  Captures must strip `csrf_token`/`_token` values (they are in the noise
  list) — and POST steps must fetch + submit a token per session, like a
  real client.

## Known divergences the diff WILL flag (and how to treat them)

1. **CSRF tokens in forms** — Python has none; Laravel emits `_token`
   inputs. After noise-stripping, `data_strings` contains the *values* of
   text nodes only, so hidden inputs do not appear — no expected diff.
2. **`expected_mismatch_note` steps** — the checklist's KG-1 (shipment
   detail lacks the customer guard) and KG-2/KG-3 are captured as-is on
   both sides: parity means matching the Python behavior, not "fixing" it
   during the rebuild.
3. **Deliberate improvements** (CSRF protection, stricter cookies) are NOT
   captured at the signature level — they only change *how* requests are
   made, not the status/rows/totals compared.

## Acceptance checklist for the Laravel harness

- [ ] `php artisan parity:capture` writes `laravel/storage/app/parity/laravel.json`
      with `meta` (source=laravel, roles, checklist pointer) + 9 domains
- [ ] Dataset is the deterministic port of `parity_dataset.py` (same rows,
      same keys: `REF-TRISCO-001/002`, `REF-OTHER-001`, `INV-0001/0002`,
      `1101 CASH IN BANK`, JE-BADDATE-1 …)
- [ ] Self-compare exit 0 on two consecutive runs (determinism)
- [ ] Doctored-field check exit 1 (sanity that the diff bites)
- [ ] `make parity` (repo root) runs capture + diff in one step (added together with the harness)
