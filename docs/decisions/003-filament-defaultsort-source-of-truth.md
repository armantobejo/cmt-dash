# Decision record — defaultSort is the single source of truth for initial table order

**For:** engineering · **From:** engineering · **Date:** 2026-11-21 · **Status:** decided

**Where this sits:** PRD §3.2 (converted Filament pages) and the Filament view-layer adoption (§1.2 / Q-09). This record governs every §3.2 page converted to a Filament table; it is inherited by all downstream conversion todos.

## 1. The decision in one line

For every converted §3.2 page, the `defaultSort()` declaration on the Filament `Table` is the **single source of truth** for the initial render order. The `records()` callback **must** wire `$table->getDefaultSortColumn()` and `$table->getDefaultSortDirection()` from the `CanSortRecords` trait. Hardcoded callback fallbacks (e.g., `'desc'` or `'asc'` literals) are prohibited.

## 2. Why this matters

The first converted page (`Invoices` / F-07) had a latent bug: its `records()` callback defaulted the sort direction to hardcoded `'desc'` regardless of what `defaultSort()` declared. Flipping `defaultSort('DATEOFISSU', 'asc')` had zero effect on the rendered order because the callback ignored the declaration. The parity test `test_declared_default_sort_is_authoritative` in `FilamentInvoicesParityTest` was written to catch this exact regression — it fails until the declaration is authoritative.

If this pattern is not codified, every subsequent §3.2 conversion will repeat the same defect: the declared sort becomes decorative and the parity gate passes by accident (because the fixture happens to match the hardcoded fallback).

## 3. Required implementation pattern

In the page's `table(Table $table)` method, the `records()` data-source callback **must** resolve the sort column and direction from the table instance:

```php
->records(function (
    int|string $page,
    int|string $recordsPerPage,
    ?string $search = null,
    ?string $sortColumn = null,
    ?string $sortDirection = null,
): LengthAwarePaginator {
    $table = $this->getTable();

    // Wire the declaration — no hardcoded fallback.
    $sortColumn    = $sortColumn    ?: $this->getTableSortColumn()    ?? $table->getDefaultSortColumn();
    $sortDirection = $sortDirection ?: $this->getTableSortDirection() ?? $table->getDefaultSortDirection();

    // … build query using $sortColumn and $sortDirection …
})
```

- `$this->getTableSortColumn()` / `$this->getTableSortDirection()` are the user-overridden values (via column header click).
- `$table->getDefaultSortColumn()` / `$table->getDefaultSortDirection()` are the values declared by `defaultSort()` on the table builder.
- The null-coalescing chain ensures: user click > declaration > nothing. **No literal `'desc'` or `'asc'` in the callback.**

## 4. Prohibited patterns

```php
// ❌ WRONG — hardcoded fallback ignores defaultSort()
$sortDirection = $sortDirection ?: 'desc';

// ❌ WRONG — hardcoded column fallback ignores defaultSort()
$sortColumn = $sortColumn ?: 'DATEOFISSU';
```

These are the exact patterns the parity test `test_declared_default_sort_is_authoritative` was written to fail on.

## 5. Precedent — the fix and the test

**Fix commit / change:** `App\Filament\Pages\Invoices::table()` lines 90–91 (PRD §3.2 row F-07).  
Before (broken):
```php
$sortDirection = $sortDirection ?: 'desc';  // hardcoded, ignores defaultSort()
```
After (correct):
```php
$sortDirection = $sortDirection ?: $this->getTableSortDirection() ?? $table->getDefaultSortDirection();
```

**Parity test:** `tests/Feature/FilamentInvoicesParityTest::test_declared_default_sort_is_authoritative()`  
- Creates two inline subclasses of `Invoices` with `defaultSort('DATEOFISSU', 'desc')` vs `defaultSort('DATEOFISSU', 'asc')`.
- Asserts the rendered row order differs (i.e., the declaration is authoritative).
- This test **must pass** for every converted §3.2 page. It is the gate that enforces this decision.

## 6. Scope & inheritance

| Scope | Applies? |
|-------|----------|
| All §3.2 pages converted to Filament tables | ✅ Yes — mandatory |
| Pages staying Blade (per §4 of `filament-view-layer.md`) | ❌ No — not a Filament table |
| Report pages using `<x-filament-panels::page>` without `InteractsWithTable` | ❌ No — no `records()` callback |
| Future Filament widgets / non-table components | ❌ No — different data-source pattern |

Every §3.2 conversion todo that produces a Filament table page inherits this rule. The conversion checklist must include: "Parity test `test_declared_default_sort_is_authoritative` (adapted for the page's sort column) passes."

## 7. Verification

- Run the adapted parity test for the converted page (same pattern as `FilamentInvoicesParityTest::test_declared_default_sort_is_authoritative`).
- Assert the test fails on a fresh conversion before the `records()` callback is wired, then passes after.
- `composer audit` and the existing role-gate / translation / DESIGN.md token tests remain green.

## 8. Open / downstream

- Each §3.2 conversion todo should copy/adapt the `test_declared_default_sort_is_authoritative` pattern for its own declared sort column(s).
- If a page declares multiple sortable columns but only one `defaultSort()`, the test should flip that one declaration and assert order change.
- No separate decision needed per page — this record is the binding rule.