<?php

namespace App\Console\Commands;

use App\Support\Dbf\SyncService;
use Illuminate\Console\Command;

/**
 * Port of the Python sync entrypoint: `php artisan dbf:sync` mirrors
 * `python tools/sync_db.py` (PRD P3). Supports the python escape hatch
 * (DBF_SYNC_BACKEND=python, docs/decisions/dbf-reading.md §7).
 */
class DbfSyncCommand extends Command
{
    protected $signature = 'dbf:sync';

    protected $description = 'Sync DBF files into the per-company SQLite databases (PRD P3)';

    public function handle(SyncService $sync): int
    {
        $dbfDir = base_path((string) config('cmt.dbf_dir'));
        $dataDir = base_path((string) config('cmt.data_dir'));

        if ((string) config('cmt.sync_backend') === 'python') {
            return $this->syncViaPython($dbfDir, $dataDir);
        }

        $result = $sync->sync($dbfDir, $dataDir);

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }
        foreach ($result['events'] as $event) {
            $this->line($event); // JSON event stream (R9) on stdout
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Escape hatch (§7): shell out to tools/sync_db.py. Used when
     * DBF_SYNC_BACKEND=python or when explicitly requested via --backend.
     */
    protected function syncViaPython(string $dbfDir, string $dataDir): int
    {
        $script = realpath(__DIR__.'/../../../..').'/tools/sync_db.py';
        // putenv: the child process inherits these on both Windows and Unix.
        putenv('DBF_DIR='.$dbfDir);
        putenv('DATA_DIR='.$dataDir);

        $this->info('[sync] backend=python');
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script), $code);

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}
