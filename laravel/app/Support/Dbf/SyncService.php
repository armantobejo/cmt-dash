<?php

namespace App\Support\Dbf;

use Illuminate\Support\Facades\Log;

/**
 * The P3 sync port: tools/sync_db.py, behavior-for-behavior (R1–R10 in
 * docs/decisions/dbf-reading.md), building a fresh temp DB and swapping it
 * over the live one atomically.
 *
 * One sync() call = Python's main(): discover companies, sync each
 * {company}.db into DATA_DIR, emit JSON events, exit non-zero summary.
 */
class SyncService
{
    public const BATCH_SIZE = 1000;

    /** Python _REQUIRED_TABLES default whitelist (R7). */
    public const DEFAULT_TABLES = [
        'CARGO', 'NA',
        'INVOICE', 'INVOICE_R',
        'OPRCOST', 'OPRCOST_R',
        'DRCRFULL', 'DRCRFULL_R',
        'SHIPPER', 'CONSIGNEE', 'AGENT', 'VESSEL', 'SATUAN',
        'USER', 'JE', 'JE_D',
        'ACCOUNT', 'SALDO_ACCT',
        // Second-layer DBF (A/R receipt / A/P payment journalism):
        // ARS = A/R receipts, ARS_R = ARS rows; AR_AGENT/_D = AR agent detail;
        // AP / AP_R = A/P payments; AP_AGENT/_D = AP agent detail;
        // BAYAR/_R = payments; TERIMA/_R = receipts;
        // RC/_D = receipts clearing; RCREQ/_D = receipt requests;
        // CLEARING_D = clearing detail. (Contra Bond / AP_CONTRA excluded - Tier C, empty.)
        'ARS', 'ARS_R', 'AR_AGENT', 'AR_AGENT_D',
        'AP', 'AP_R', 'AP_AGENT', 'AP_AGENT_D',
        'BAYAR', 'BAYAR_R', 'TERIMA', 'TERIMA_R',
        'RC', 'RC_D', 'RCREQ', 'RCREQ_D',
        'CLEARING_D',
        // Quotation / vendor detail (second-layer, A/R receipt / A/P payment journalism):
        'QUOTATION', 'QUOTATION_R', 'VENDOR', 'VENDOR_D',
        // Manifest / aging / sales invoice (second-layer, Q-11 Option A):
        // MANIFEST = shipment manifest; SAGING = aging buckets; SI/SI_D = sales invoices.
        'MANIFEST', 'SAGING', 'SI', 'SI_D',
    ];

    /** Performance indexes added after a successful table build (R8). */
    public const INDEXES = [
        'CREATE INDEX IF NOT EXISTS idx_oprcost_r_uopr ON OPRCOST_R(UOPRCOST)',
        'CREATE INDEX IF NOT EXISTS idx_oprcost_ref ON OPRCOST(REFSHITMEN)',
        'CREATE INDEX IF NOT EXISTS idx_drcrfull_r_unik ON DRCRFULL_R(UNIK)',
        'CREATE INDEX IF NOT EXISTS idx_drcrfull_ref ON DRCRFULL(REFSHITMEN)',
        'CREATE INDEX IF NOT EXISTS idx_invoice_ref ON INVOICE(REFSHITMEN)',
        'CREATE INDEX IF NOT EXISTS idx_invoice_k ON INVOICE(K_INVOICE)',
        'CREATE INDEX IF NOT EXISTS idx_je_d_uje ON JE_D(UJE)',
        'CREATE INDEX IF NOT EXISTS idx_je_d_acct ON JE_D(KD_ACCOUNT)',
        'CREATE INDEX IF NOT EXISTS idx_je_uje ON JE(UJE)',
        'CREATE INDEX IF NOT EXISTS idx_je_modul ON JE(MODUL)',
        'CREATE INDEX IF NOT EXISTS idx_saldo_acct ON SALDO_ACCT(KD_ACCOUNT, TANGGAL)',
        "CREATE INDEX IF NOT EXISTS idx_je_d_bad_dk ON JE_D(DK) WHERE DK IS NOT NULL AND DK NOT IN ('D', 'K')",
        'CREATE INDEX IF NOT EXISTS idx_je_d_uje_head ON JE_D(UJE) WHERE UJE IS NULL',
    ];

    public function __construct(
        protected DbfReader $reader,
    ) {
    }

    /**
     * Run the sync. Returns [ok, lines, events] where *lines* feeds the
     * stdout_tail contract and *events* the JSON stream (R9).
     *
     * @return array{ok: bool, lines: list<string>, events: list<string>}
     */
    public function sync(string $dbfDir, string $dataDir): array
    {
        $this->lines = [];
        $this->events = [];

        $companies = $this->discoverCompanies($dbfDir);

        if ($companies === []) {
            $this->emit('sync_fail', ['phase' => 'scan', 'error' => 'No DBF tables found']);

            return ['ok' => false, 'lines' => $this->lines, 'events' => $this->events];
        }

        $this->log("Detected ".count($companies).' company databases: '.implode(', ', array_column($companies, 0)));

        if (! is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        $allOk = true;
        foreach ($companies as [$company, $dir]) {
            $this->log(str_repeat('=', 60));
            $this->log("Syncing company: {$company}");
            $this->log(str_repeat('=', 60));
            $ok = $this->syncSingle($dir, $dataDir.DIRECTORY_SEPARATOR."{$company}.db");
            $allOk = $allOk && $ok;
        }

        $this->emit('sync_complete', ['companies' => count($companies), 'ok' => $allOk]);

        return ['ok' => $allOk, 'lines' => $this->lines, 'events' => $this->events];
    }

    /** rename() over the live DB, with the Windows locked-target fallback. */
    protected function atomicSwap(string $tempPath, string $dbPath): bool
    {
        try {
            if (@rename($tempPath, $dbPath)) {
                return true;
            }
        } catch (\Throwable) {
            // Laravel converts the PHP warning into an exception in tests.
        }

        if (is_file($dbPath)) {
            try {
                if (@unlink($dbPath) && @rename($tempPath, $dbPath)) {
                    $this->log('  NOTE: target was locked; used unlink+rename fallback (non-atomic, Windows).');

                    return true;
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }

    /** Python _sync_single: one DBF dir → one SQLite file, atomically. */
    public function syncSingle(string $dbfDir, string $dbPath): bool
    {
        $startMs = (int) (microtime(true) * 1000);
        $this->emit('sync_start', ['dbf_dir' => $dbfDir, 'db_path' => $dbPath]);

        $dbTempPath = preg_replace('/\.db$/', '.db-sync', $dbPath);

        $tables = $this->collectTables($dbfDir);
        if ($tables === []) {
            $this->log("  No DBF tables found in {$dbfDir}. Skipping.");
            $this->emit('sync_fail', ['phase' => 'scan', 'error' => 'No DBF tables found']);

            return false;
        }

        if (! is_dir(dirname($dbPath))) {
            mkdir(dirname($dbPath), 0777, true);
        }

        $pdo = new \PDO('sqlite:'.$dbTempPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $totalRows = 0;
        $allOk = true;
        $rowCounts = [];

        foreach ($tables as $name => $table) {
            $t0 = (int) (microtime(true) * 1000);
            try {
                $this->createSchema($pdo, $name, $table['fields']);
                $rows = $this->insertData($pdo, $name, $table);
                $rowCounts[$name] = [$rows, count($table['rows'])];
                $ms = (int) (microtime(true) * 1000) - $t0;
                $this->log("    ...synced {$rows} rows.");
                $this->emit('table_synced', ['table' => $name, 'rows' => $rows, 'ms' => $ms]);
                $totalRows += $rows;
            } catch (\Throwable $e) {
                $this->log("    ...ERROR syncing {$name}: ".$e->getMessage());
                $this->emit('sync_fail', ['table' => $name, 'error' => $e->getMessage()]);
                $allOk = false;
            }
        }

        // Verification (R5): DBF row counts vs SQLite.
        $this->log('  --- Verification ---');
        $mismatches = [];
        if ($allOk) {
            foreach ($rowCounts as $name => [$actual, $expected]) {
                if ($actual !== $expected) {
                    $mismatches[] = "    - {$name}: DBF={$expected}, SQLite={$actual}";
                }
            }
        }

        if (! $allOk || $mismatches !== []) {
            foreach ($mismatches as $m) {
                $this->log($m);
            }
            $this->log("  Sync failed for {$dbfDir}. Temporary database is at: {$dbTempPath}");
            $this->emit('sync_fail', ['phase' => 'verify', 'dbf_dir' => $dbfDir]);

            return false;
        }

        // Post-build steps on the temp DB, then the atomic swap (R1).
        UnifiedShipmentSql::createTable($pdo);
        $this->copyAppTables($dbPath, $pdo);
        foreach (self::INDEXES as $idx) {
            try {
                $pdo->exec($idx);
            } catch (\Throwable) {
                // table may not exist in this company's DB
            }
        }
        $pdo = null; // close before rename

        // R1: atomic swap (POSIX rename semantics — the contract Python
        // relies on). Windows can't rename over an open file (code 5), so a
        // locked target falls back to unlink+rename — a tiny non-atomic
        // window that only exists on NTFS dev hosts.
        if (! $this->atomicSwap($dbTempPath, $dbPath)) {
            $this->log("  FATAL: Could not rename temp DB {$dbTempPath} -> {$dbPath}");
            $this->emit('sync_fail', ['phase' => 'rename', 'db_path' => $dbPath]);

            return false;
        }

        $ms = (int) (microtime(true) * 1000) - $startMs;
        $this->log("  Successfully synced to {$dbPath}");
        $this->log('  --- Sync Complete ---');
        $this->log("  Total rows synced: {$totalRows}");
        $this->emit('sync_complete', ['total_rows' => $totalRows, 'tables' => count($tables), 'ms' => $ms, 'ok' => true]);

        return true;
    }

    /**
     * Python _discover_companies: subdirectories holding .dbf files (DATA/
     * INIT/ nested or legacy flat); empty when none (single-DB mode).
     *
     * @return list<array{0:string,1:string}>
     */
    public function discoverCompanies(string $dbfDir): array
    {
        if (! is_dir($dbfDir)) {
            return [];
        }
        $base = new \DirectoryIterator($dbfDir);
        $companies = [];
        foreach ($base as $child) {
            if (! $child->isDir() || $child->isDot()) {
                continue;
            }
            $path = $child->getPathname();
            // scandir + suffix check, NOT glob(): PHP glob is case-sensitive
            // on Windows ('*.dbf' misses 'NA.DBF') while Python's glob follows
            // filesystem case-insensitivity there.
            if ($this->hasDbfFiles($path.DIRECTORY_SEPARATOR.'DATA') && is_dir($path.DIRECTORY_SEPARATOR.'DATA')) {
                $companies[] = [$child->getFilename(), $path];
            } elseif ($this->hasDbfFiles($path)) {
                $companies[] = [$child->getFilename(), $path];
            }
        }
        sort($companies);

        return $companies;
    }

    // ── internals ────────────────────────────────────────────────────────

    /** Python: name.replace("ID", "_id") when name.upper() == "ID" — a column
     * named "id" would shadow SQLite's rowid alias. */
    protected static function colName(string $name): string
    {
        return strtoupper($name) === 'ID' ? '_id' : $name;
    }

    /** Collect whitelisted tables from a company dir (flat / DATA / INIT). */
    protected function collectTables(string $dir): array
    {
        $tables = [];
        foreach ($this->dbfFiles($dir) as [$filename, $filepath]) {
            $name = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
            if (! $this->isWhitelisted($name)) {
                $this->log("  - Skipping {$name} (not in required table set; set SYNC_ALL_TABLES=1 to sync all)");
                continue;
            }
            $read = $this->reader->read($filepath);
            if ($read === null) {
                $this->log("  - FAILED to load {$filename} with any encoding. (path: {$filepath})");
                continue;
            }
            $this->log("  - Found {$name} (".count($read['rows'])." records, ".count($read['fields']).' fields)');
            $tables[$name] = $read;
        }

        return $tables;
    }

    /** Whether the directory contains any *.dbf file (case-insensitive). */
    protected function hasDbfFiles(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if (str_ends_with(strtolower($f), '.dbf')) {
                return true;
            }
        }

        return false;
    }

    /** (filename, filepath) pairs, DATA/ then INIT/ then flat — sorted (R6). */
    protected function dbfFiles(string $baseDir): array
    {
        $dataDir = $baseDir.DIRECTORY_SEPARATOR.'DATA';
        $initDir = $baseDir.DIRECTORY_SEPARATOR.'INIT';
        $files = [];

        if (is_dir($dataDir) || is_dir($initDir)) {
            foreach ([$dataDir, $initDir] as $sub) {
                if (! is_dir($sub)) {
                    continue;
                }
                $names = [];
                foreach (scandir($sub) ?: [] as $f) {
                    if (str_ends_with(strtolower($f), '.dbf')) {
                        $names[] = $f;
                    }
                }
                sort($names);
                foreach ($names as $n) {
                    $files[] = [$n, $sub.DIRECTORY_SEPARATOR.$n];
                }
            }
        } else {
            foreach (scandir($baseDir) ?: [] as $f) {
                if (str_ends_with(strtolower($f), '.dbf')) {
                    $files[] = [$f, $baseDir.DIRECTORY_SEPARATOR.$f];
                }
            }
        }

        return $files;
    }

    protected function isWhitelisted(string $name): bool
    {
        $override = trim((string) config('cmt.sync_tables'));
        if ($override !== '') {
            $set = array_map('trim', explode(',', strtoupper($override)));

            return in_array($name, $set, true);
        }
        if (config('cmt.sync_all_tables')) {
            return true;
        }

        return in_array($name, self::DEFAULT_TABLES, true);
    }

    /** Python create_schema: "rowid_" PK + one column per DBF field. */
    protected function createSchema(\PDO $pdo, string $table, array $fields): void
    {
        $cols = ['"rowid_" INTEGER PRIMARY KEY'];
        foreach ($fields as $f) {
            $sqlType = DbfTypeMap::sqliteType($f['type'], $f['dec'])['sql'];
            $cols[] = '"'.self::colName($f['name']).'" '.$sqlType;
        }
        $pdo->exec('DROP TABLE IF EXISTS "'.$table.'"');
        $pdo->exec('CREATE TABLE "'.$table.'" ('.implode(', ', $cols).')');
    }

    /** Python insert_data: converted rows in batches of 1000. */
    protected function insertData(\PDO $pdo, string $table, array $tableInfo): int
    {
        $fields = $tableInfo['fields'];
        $rows = $tableInfo['rows'];
        if ($rows === []) {
            return 0;
        }

        $colNames = implode(', ', array_map(
            fn ($f) => '"'.self::colName($f['name']).'"',
            $fields
        ));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $sql = 'INSERT INTO "'.$table.'" ('.$colNames.') VALUES ('.$placeholders.')';

        $convs = array_map(fn ($f) => DbfTypeMap::sqliteType($f['type'], $f['dec'])['conv'], $fields);
        $count = 0;
        $stmt = $pdo->prepare($sql);
        $batch = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($fields as $i => $f) {
                $values[] = DbfTypeMap::convert($row[$f['name']] ?? null, $convs[$i]);
            }
            $batch[] = $values;
            if (count($batch) >= self::BATCH_SIZE) {
                $this->flush($stmt, $batch);
                $count += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->flush($stmt, $batch);
            $count += count($batch);
        }

        return $count;
    }

    /** @param list<list<mixed>> $batch */
    protected function flush(\PDOStatement $stmt, array $batch): void
    {
        foreach ($batch as $values) {
            $stmt->execute($values);
        }
    }

    /**
     * Python _copy_app_tables: preserve users/sessions (schema + rows) from
     * the live DB into the fresh one, with the AUTOINCREMENT sqlite_sequence
     * repair (R2/R10).
     */
    protected function copyAppTables(string $livePath, \PDO $dest): void
    {
        if (! file_exists($livePath)) {
            return;
        }
        $src = new \PDO('sqlite:'.$livePath);
        $src->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        try {
            foreach (['users', 'sessions'] as $table) {
                $ddl = $src->query(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name='".$table."'"
                )->fetchColumn();
                if ($ddl === false || $ddl === null || $ddl === '') {
                    continue; // not present in the old DB; nothing to preserve
                }
                $rows = $src->query('SELECT * FROM "'.$table.'"')->fetchAll(\PDO::FETCH_ASSOC);
                $dest->exec('DROP TABLE IF EXISTS "'.$table.'"');
                $dest->exec($ddl);
                if ($rows !== []) {
                    $cols = array_keys($rows[0]);
                    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                    $stmt = $dest->prepare('INSERT INTO "'.$table.'" ('.implode(', ', $cols).") VALUES ({$placeholders})");
                    foreach ($rows as $row) {
                        $stmt->execute(array_values($row));
                    }
                }
                // Keep AUTOINCREMENT counters in sync (tables with an `id` col).
                $colNames = $dest->query('PRAGMA table_info("'.$table.'")')->fetchAll(\PDO::FETCH_COLUMN, 1);
                if (in_array('id', $colNames, true)) {
                    $max = $dest->query('SELECT COALESCE(MAX(id), 0) FROM "'.$table.'"')->fetchColumn();
                    $dest->exec('INSERT OR REPLACE INTO sqlite_sequence(name, seq) VALUES (\''.$table.'\', '.$max.')');
                }
            }
        } finally {
            $src = null;
        }
    }

    protected function log(string $line): void
    {
        $this->lines[] = $line;
        Log::channel(config('logging.default'))->debug('[sync] '.$line);
    }

    /** Python emit(): JSON event stream (R9). */
    protected function emit(string $event, array $fields): void
    {
        $fields['event'] = $event;
        $json = json_encode($fields, JSON_UNESCAPED_SLASHES);
        $this->events[] = $json === false ? '{"event":"'.$event.'"}' : $json;
        Log::channel(config('logging.default'))->debug('[sync-event] '.$this->events[count($this->events) - 1]);
    }

    /** @var list<string> */
    protected array $lines = [];

    /** @var list<string> */
    protected array $events = [];
}
