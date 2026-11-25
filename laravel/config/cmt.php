<?php

// CMT Dashboard integration settings.
//
// Env-name mapping from the Python app (the Laravel app deliberately does NOT
// reuse DATABASE_URL — Laravel itself interprets it for its own connection):
//   Python DATABASE_URL (path to default sqlite)  ->  CMT_DATA_DIR + CMT_DEFAULT_DB
//   Python DATA_DIR                               ->  CMT_DATA_DIR
//   Python CMT_USERS                              ->  CMT_USERS
//   Python CMT_COOKIE_SECURE                      ->  CMT_COOKIE_SECURE (both cookies)

$dataDir = env('CMT_DATA_DIR', '../data');

// Relative paths resolve against the laravel/ base path (so ../data points at
// the shared repo data/ directory used during parallel run).
if (! preg_match('/^([A-Za-z]:)?[\/\\\\]/', $dataDir)) {
    $dataDir = base_path($dataDir);
}

return [
    // Directory holding the per-company SQLite files ({name}.db).
    'data_dir' => $dataDir,

    // Company DB selected when no (or an invalid) cookie is present. The
    // Python app derives this from the DB_PATH stem — the repo data dir uses
    // CMT.db (uppercase), so the default matches byte-for-byte.
    'default_db' => env('CMT_DEFAULT_DB', 'CMT'),

    // Auth is centralized in the DEFAULT company DB (Python db.py
    // get_auth_db): users + sessions tables, one login grants all companies.

    // Cookie names/values — must match the Python app byte-for-byte.
    'db_cookie' => 'cmt_db',
    'session_cookie' => 'cmt_session',

    // One year, matching Python's max_age=31536000 on the cmt_db cookie.
    'db_cookie_minutes' => 60 * 24 * 365,

    // Session TTL — Python auth.py SESSION_TTL = 8 hours.
    'session_ttl_hours' => 8,

    // Python session_cookie_secure(): Set-Cookie Secure flag toggles via
    // CMT_COOKIE_SECURE=1 — applies to BOTH the session and db cookies.
    'cookie_secure' => env('CMT_COOKIE_SECURE', '') === '1',

    // User seeding source (Python auth.py format):
    // user:pass:role[:company],...  Empty -> no seeding (parity).
    'users_env' => env('CMT_USERS', ''),

    // Default language (Python i18n.py: CMT_LANG, default 'id').
    'lang_default' => env('CMT_LANG', 'id'),

    // Rows per page — Python main.py constants.
    'unpaid_per_page' => 5,
    'shipments_per_page' => 25,
    'invoices_per_page' => 25,

    // F-28 Listing Reference pages.
    'agents_per_page' => 25,
    'shippers_per_page' => 25,
    'consignees_per_page' => 25,
    'accounts_per_page' => 25,

    // F-24/F-25 A/R Receipt & A/P Payment report pages.
    'receipts_per_page' => 50,
    'payments_per_page' => 50,

    // F-29 Performance Tracker pages.
    'performance_per_page' => 25,

    // ── Sync (PRD P3, port of tools/sync_db.py + src/app/sync.py) ──

    // DBF source directory (Python DBF_DIR, default /data/dbf in container).
    'dbf_dir' => env('DBF_DIR', '../DBF'),

    // Sync backend: 'php' (native port) or 'python' (escape hatch, shells out
    // to tools/sync_db.py — docs/decisions/dbf-reading.md §7).
    'sync_backend' => env('DBF_SYNC_BACKEND', 'php'),

    // Table whitelist (Python _REQUIRED_TABLES); '' = default set.
    'sync_tables' => env('SYNC_TABLES', ''),
    'sync_all_tables' => env('SYNC_ALL_TABLES', '') === '1',

    // JSON event stream (Python SYNC_LOG_JSON=1 → emit()).
    'sync_log_json' => env('SYNC_LOG_JSON', '') === '1',

    // Python STALE_AFTER_SECONDS (12 h) — api/sync-status staleness threshold.
    'stale_after_seconds' => (int) env('CMT_STALE_AFTER_SECONDS', 12 * 60 * 60),
];
