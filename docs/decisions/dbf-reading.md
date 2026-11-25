# Decision Record — DBF Reading in the Laravel Rebuild (PRD Q-01)

**Status**: Decided — 2026-09-22
**Decision**: Native PHP reader via **`hisamu/php-xbase`**, wrapped in an in-repo `DbfReader` facade. The Python sync is retained only as an escape hatch (§7).
**Decides**: PRD open question Q-01
**Related**: [`PRD.md`](../../PRD.md) §3 · [`docs/srs.md`](../srs.md) FR-007 · [`docs/ponytail-debt.md`](../ponytail-debt.md) (keep-dbfread verdict)

## 1. What the sync must preserve (requirements inherited from the Python sync)

The Python sync (`tools/sync_db.py`) is the functional spec. Any PHP replacement must keep:

| # | Behavior in `tools/sync_db.py` | Why it matters |
|---|---|---|
| R1 | Atomic temp-DB → `os.replace()` swap | A failed sync never corrupts the live DB (SRS FR-007; PRD NFR-003) |
| R2 | `users`/`sessions` preserved across the swap (`_copy_app_tables`) | App accounts/sessions survive every sync |
| R3 | `UnifiedShipment` built as a real TABLE with window-function dedup (`ROW_NUMBER() OVER (PARTITION BY COALESCE(NULLIF(REFSHITMEN,''), UNIK))`, GW/NW/MEAST partition sums) | The app's core read model — a plain view was too slow |
| R4 | Corrupt-data tolerance: per-field try/except → NULL (`_RobustParser`), `char_decode_errors='ignore'`, missing-memofile tolerance | Legacy FoxPro data is corrupt in places (pre-2000 journal dates, bad DK flags — documented on the data-quality page) |
| R4b | Encoding fallback chain per file: `latin-1 → cp437 → utf-8` | Files read under different encodings; try in order, move on |
| R4c | Field-name dedup (first field wins) | Legacy files contain duplicate field names |
| R5 | Row-count verification per table (DBF vs SQLite) | Sync fails loudly; temp DB discarded |
| R6 | Per-company directory discovery (flat, DATA/ + INIT/ layouts) | 5 company dirs under `DBF/` |
| R7 | Table whitelist (default set; `SYNC_TABLES` override; `SYNC_ALL_TABLES=1` audit mode) | Full sync pulled hundreds of MB and blew the timeout |
| R8 | 13 performance indexes (incl. partial indexes for data-quality) | Detail-page and data-quality latency |
| R9 | JSON event stream (`sync_start` / `table_synced` / `sync_fail` / `sync_complete`) | Machine-readable ops logs (`SYNC_LOG_JSON=1`; SRS FR-006) |
| R10 | `sqlite_sequence` repair for preserved tables | AUTOINCREMENT collisions after the swap |

## 2. Ground truth about the data (checked 2026-09-22)

- **Memo files are mandatory**: `DBF/CMT/DATA/` contains `CARGO.FPT`, `invoice.FPT`, `drcrfull.FPT`, `SHIPPER.FPT`, `crdrnote.FPT`, and more. Shipment and invoice line descriptions live in FPT memos. A reader without FPT support is useless here.
- **Sizes**: `NA` 3.8MB, `CARGO` 1.3MB, `INVOICE` 5.4MB, `JE_D` **96MB / 36K+ rows** (ledger drill-down), `SALDO_ACCT` 20MB.
- **5 company dirs** (CME, CMT, CSM, MAL, SBY), each with DATA/ + INIT/ layout.
- Field names are uppercase (`REFSHITMEN`, `K_INVOICE`, ...); column order drives the `UnifiedShipment` SQL (`tools/schema.py`).

## 3. Options considered

### Option A — pecl `dbase` extension — REJECTED
The PHP manual is explicit: *"There is no support for indexes or memo fields"* — M-type fields are silently ignored. Since CARGO/INVOICE text lives in FPT memos, this silently loses data — the worst failure mode for a ledger tool. Bonus costs: a C extension in the dashboard image means a rebuild for every PHP version. Rejected.

### Option B — `totalcrm/php-dbf` — REJECTED as primary
Same PHPXbase-port lineage as hisamu/php-xbase, reads DBF + FPT memos. Problem: last release **1.0.5, 2021-12-02** (~4.5 years stale as of 2026-09-22; verified on Packagist). No PHP 8.4/8.5 deprecation testing. Rejected in favor of the actively maintained fork of the same code lineage.

### Option C — native PHP: `hisamu/php-xbase` — **RECOMMENDED**
- **Reads DBF + FPT memos** (read-only `TableReader`; the library's editor/writer classes are simply not used, preserving the no-write product law).
- **Actively maintained**: 2.4.0 released 2026-02-04 (verified on Packagist); MIT; `php >= 7.1` + `ext-iconv` — no conflicts with Laravel 12 / PHP 8.3+.
- **Pure PHP**: standard PHP images work unmodified; no pecl, no per-PHP-version image rebuild.
- **Column projection** (`columns => [...]`) skips unused columns and memo blobs where not needed.
- Record iteration + per-field type handlers map 1:1 onto `dbfread`'s model, so the Python sync's tolerance layer (R4/R4b/R4c) ports like-for-like.
- Caution: **bus factor 1** (single maintainer) → mitigation in §6.

### Option D — subprocess call to the existing Python sync — VIABLE FALLBACK
The battle-tested script keeps every guarantee (R1–R10 proven in production). Costs: **two runtimes forever**, two dependency audits (pip-audit + composer audit) forever, two images, and the dashboard team must maintain Python ops knowledge indefinitely. As a permanent architecture this pays a recurring tax to avoid a one-time, well-gated port. Retained as the escape hatch and as the parallel-run sync-of-record (§5), not as the end state.

### Option E — external ETL (conversion APIs, ODBC/VFPOLEDB) — REJECTED
Conversion APIs send freight/finance data to a third party (data-residency problem). ODBC/VFPOLEDB is Windows-oriented and does not fit the Docker/Linux stack.

## 4. Verdict

| Criterion | A pecl dbase | B totalcrm/php-dbf | C hisamu/php-xbase | D Python subprocess |
|---|---|---|---|---|
| FPT memo read (§2) | ✗ fatal | ✓ | ✓ | ✓ |
| Corrupt-data tolerance (R4/R4b/R4c) | n/a | wrapper needed | wrapper needed | ✓ proven |
| Maintenance | PECL, low churn | stale (2021) | **active (2026-02)** | n/a |
| One runtime / one audit | ✗ | ✓ | **✓** | ✗ two forever |
| Docker simplicity | ✗ C-ext rebuilds | ✓ | ✓ | ✗ two images |
| Parallel-run byte-comparison | ✗ | △ | △ | ✓ run both |
| Long-term cost | medium | medium | **low** | high |

## 5. Recommendation

**Native PHP via `hisamu/php-xbase` 2.4.0, wrapped in an in-repo `App\Support\Dbf\DbfReader` facade.**

Why C over D: the Python subprocess feels safest but costs two runtimes, two audits, and two images forever. `hisamu/php-xbase` reads FPT memos, is actively maintained, is pure PHP, MIT, and its reader model maps 1:1 onto `dbfread` — making the tolerance layer a like-for-like port.

Bridge plan (what makes this decision safe):

1. **P3 (sync phase) runs both syncs in parallel.** The Python sync stays the *sync-of-record* while the PHP sync is built. The P3 exit gate is **byte-comparison of the resulting `*.db` files (row counts + per-table content hashes) across all 5 company dirs**.
2. After the PHP sync passes byte-comparison, the Python sync container is **retired** → one runtime, one audit, one image.
3. **Escape hatch**: `DBF_SYNC_BACKEND=python` env var can keep the old script for N releases as a documented fallback (see §7).
4. Column projection + the table whitelist (R7) keeps the 96MB `JE_D` manageable; batched inserts (`BATCH_SIZE=1000` port) and `DBF_SYNC_TIMEOUT` carry over.

## 6. Risks & mitigations

| Risk | Mitigation |
|---|---|
| `hisamu/php-xbase` is bus-factor-1 | MIT → fork on abandonment; `DbfReader` facade isolates the dependency to one file; escape hatch (§7) |
| Corrupt rows crash a table mid-sync (R4) | Port `_RobustParser` as per-field try/catch → NULL; validate against the real 5-company dataset in P3 |
| FPT offsets/encodings vary per company dir | P3 byte-comparison gate catches per-company mismatch before Python retirement |
| 96MB JE_D throughput | Column projection + batched inserts + `DBF_SYNC_TIMEOUT` |
| Library can't read some company's files | Flip `DBF_SYNC_BACKEND=python`, keep shipping, investigate offline |

## 7. Escape hatch

`DBF_SYNC_BACKEND=python` (default: `php`). When set to `python`, the sync command shells out to the existing `tools/sync_db.py` script (via the compose `sync` service or a local Python) instead of running the native PHP path. The `tools/Dockerfile` container stays buildable until retirement. If the PHP reader proves unable to read any company's files, flip the env and ship; investigate offline.

## 8. Implementation notes (port map)

| Python | PHP target |
|---|---|
| `dbfread.DBF` + `_RobustParser` | `XBase\TableReader` + per-field try/catch inside `DbfReader` |
| `dbf_to_sqlite_type` + `convert_val` | `DbfTypeMap` — same mapping table, same NULL-on-failure semantics |
| `tools/schema.py` (`_unified_select_sql`, window-function dedup) | `App\Support\Dbf\UnifiedShipmentSql` — single source of truth, SQL ported verbatim (PRD §3) |
| `_copy_app_tables` | port as-is; also preserves Laravel's `cache`/`jobs` tables if adopted |
| `_discover_companies` (flat / DATA / INIT layouts) | same discovery logic in PHP |
| `emit()` JSON events | Laravel `Log` JSON channel, same event names |
| Batched inserts, row-count verification, 13 indexes, atomic `rename()` | same behavior, same order of operations |

## 9. Verification plan

- P3 gate: run both syncs over all 5 company dirs; assert equal row counts per table and equal per-table content hashes in the resulting SQLite files.
- Add fixtures: one small DBF+FPT pair per edge case (missing memo, corrupt date, duplicate field name, bad encoding) committed to `tests/` for the PHP reader's unit tests.
- Regression: the existing Python-side tests (`tests/test_sync_preserves_users.py`, `tests/test_sync_logging.py`, `tests/test_schema.py`) define the behaviors the PHP port must mirror.
