# Ponytail Debt — CMT Dashboard

**Status**: Open (recorded, not yet applied)
**Provenance**: `ponytail-audit` run 2026-09-12 on branch `feat/system-hardening`, whole-repo scan.
**Convention**: every entry carries a revisit trigger; an entry with no trigger is cut on next touch of its file, not re-litigated.
**History**: prior sweep and its applied cuts live in [yagni-cuts.md](yagni-cuts.md). Entries below move there as they land.

## Open findings (ranked, biggest first)

1. `delete:` Whole `.specify/extensions/` script tree (1286 lines: `create_new_feature_branch.py`, `update_agent_context.py`, `auto_commit.py`, `initialize_repo.py`, `git_common.py`). Zero references from app, tests, compose, or docs — scaffolding from a template kit. Replacement: nothing. *Trigger: a reference appears in docs, scripts, or CI → promote out of debt and wire it up.*
2. `delete:` `memory/` (brain.py 420 lines + shortcuts + context.json + history.jsonl). No import in src/tools/tests; already gitignored as "personal, not shared". Replacement: nothing. *Trigger: the memory CLI gets used again → install it outside the repo, not in it.*
3. `native:` Deps `aiofiles` and `httpx` in `src/requirements.txt` are imported nowhere in app or tool code (only listed in gen_lock's TOP_LEVEL). Replacement: drop both lines; TestClient pulls httpx transitively. *Trigger: real async file IO or an HTTP client lands in app code → re-add with a caller.*
4. ~~`native:` Company-filter SQL block copy-pasted 8× across `queries.py`, with drift (missing paren) in the sales copies~~ — **APPLIED 2026-09-12** (as `_company_filter()`).
~~5. `delete:` Dead function `template_context`~~ — **APPLIED 2026-09-12**.
6. ~~`delete:` Dead function `_has_gl_tables` in `queries.py`~~ — **APPLIED 2026-09-12**.
~~7. `delete:` Dead env knob `_PYTHON_EXE`~~ — **APPLIED 2026-09-12**.
8. `yagni:` `_UNPAID_SORT_COLUMNS` and `_SHIPMENTS_SORT_COLUMNS` are identical dicts 73 lines apart in `queries.py`. Replacement: one `_SORT_COLUMNS`. *Trigger: a third sort-columns dict appears → merge all three then.*
9. `yagni:` Alias wrappers for one function — `create_unified_view` → `create_unified_table` (3 callers) and `build_unified_view_sql` → `_unified_select_sql` (test-only). Replacement: call the real names. *Trigger: next schema.py change → rename callers in the same commit.*
10. `shrink:` `sync.py` container-vs-local path dance — 5 lines picking between two `tools/` locations; every observed layout has it at `<repo>/tools`. Replacement: one path. *Trigger: an actual container layout that differs → reintroduce the branch with evidence.*
~~11. `yagni:` Repo-root litter dirs `DBF;C/` and `data;C/`~~ — **APPLIED 2026-09-12**.

## Keepers (verified not debt)

`db.query`/`query_one` (10+ callers each), the i18n JSON loader (live feature, 3 langs), `JsonFormatter` (right layer — a stdlib `logging.Formatter` subclass), the `money`/`num` filters (both modes in active use), `gen_lock.py` (documented lockfile process), `dbfread` (tool-critical). Don't re-litigate these on the next audit.

## Net

`net: -2210 lines, -2 deps possible.` (~124 lines from app code, the rest scaffold/litter.)

## Applied

- **2026-09-12** — item 4: `_company_filter()` helper replaced all 10 SQL sites (11 call shapes incl. get_summary's two columns pairs); the two sales `REFSHITMEN IN` subqueries are now paren-balanced by construction; first regression tests for the sales filters (74/74).
- **2026-09-12** — items 5, 6, 7, 11: dead `template_context` and `_has_gl_tables` removed, dead `_PYTHON_EXE` env knob removed (plus its error-message mention), empty `DBF;C/` + `data;C/` dirs deleted. Zero-risk batch; suite green.

Entries graduate to [yagni-cuts.md](yagni-cuts.md) as they land.
