<?php

namespace App\Support;

use Illuminate\Database\ConnectionResolverInterface;

/**
 * Lazily registers per-company connections into the DatabaseManager and hands
 * out query builders for them. Owns everything connection- and path-shaped;
 * name/policy questions belong to CompanyDbManager.
 */
class CompanyConnectionFactory
{
    public function __construct(
        protected ConnectionResolverInterface $resolver,
        /** Directory holding the per-company SQLite files ({name}.db). */
        protected string $dataDir,
    ) {
    }

    /** Query builder for a table on the ACTIVE company DB. */
    public function query(CompanyDbManager $manager, string $table)
    {
        return $this->connection($manager->active())->table($table);
    }

    /** Run a callback against an arbitrary company DB (P5 comparisons). */
    public function on(CompanyDbManager $manager, string $name, callable $callback)
    {
        return $callback($this->connection($name));
    }

    /** Per-company connection, registered on first use (cmt_<name>). */
    public function connection(string $name)
    {
        return $this->resolver->connection($this->connectionName($name));
    }

    /** Connection name for a company DB (cmt_<name>). */
    public function connectionName(string $name): string
    {
        $conn = 'cmt_'.$name;

        if (! config()->has('database.connections.'.$conn)) {
            config()->set('database.connections.'.$conn, [
                'driver' => 'sqlite',
                'database' => $this->path($name),
                'prefix' => '',
                'foreign_key_constraints' => false,
                'busy_timeout' => 5000,
                'journal_mode' => 'wal',
                'synchronous' => 'normal',
            ]);
        }

        return $conn;
    }

    /**
     * Auth connection: users/sessions always live in the DEFAULT company DB
     * (Python db.py get_auth_db) so one login grants all companies.
     */
    public function authConnection()
    {
        return $this->connection((string) config('cmt.default_db'));
    }

    /** Absolute path for a company DB file. */
    protected function path(string $name): string
    {
        return $this->dataDir.DIRECTORY_SEPARATOR.$name.'.db';
    }
}
