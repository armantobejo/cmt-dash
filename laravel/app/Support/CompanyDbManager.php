<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Owns the multi-company DB policy: which company databases exist, which is
 * active for the current request, and what a valid DB name is.
 *
 * One instance per request (scoped in AppServiceProvider), so the active
 * selection needs no reset/lifecycle tricks — P1+ code should inject this
 * class, never call it statically.
 *
 * Mirrors src/app/middleware.py + src/app/db.py semantics:
 *   - unknown cookie value  -> silently fall back to the default DB
 *   - connections are registered lazily, on first use
 */
class CompanyDbManager
{
    /** Active DB name, or null until resolve() is called. */
    protected ?string $active = null;

    /** Names discovered in the data dir (scanned lazily). */
    protected ?array $availableCache = null;

    public function __construct(
        /** Directory holding the per-company SQLite files ({name}.db). */
        protected string $dataDir,
        /** Company DB selected when no (or an invalid) cookie is present. */
        protected string $default,
    ) {
    }

    /** Resolve the active DB from a cookie value; returns the chosen name. */
    public function resolve(?string $cookieValue): string
    {
        $candidate = is_string($cookieValue) && $cookieValue !== '' ? $cookieValue : $this->default;

        if (! $this->isValidName($candidate)) {
            $candidate = $this->default;
        }

        $this->active = $candidate;

        return $candidate;
    }

    /** Active DB name; falls back to the default outside HTTP (commands). */
    public function active(): string
    {
        return $this->active ?? $this->default;
    }

    /** Absolute path of a company DB file (default: the active one). */
    public function path(?string $name = null): string
    {
        return $this->dataDir.DIRECTORY_SEPARATOR.($name ?? $this->active()).'.db';
    }

    /** Company DB names present in the data dir (e.g. ['CME', 'CMT']). */
    public function available(): array
    {
        if ($this->availableCache !== null) {
            return $this->availableCache;
        }

        $files = glob($this->dataDir.DIRECTORY_SEPARATOR.'*.db') ?: [];
        $this->availableCache = collect($files)
            ->map(fn (string $f) => pathinfo($f, PATHINFO_FILENAME))
            ->sort()
            ->values()
            ->all();

        return $this->availableCache;
    }

    /** Default DB name (the switcher's silent-fallback target). */
    /** Cookie name carrying the active company DB (Python main.py set_db). */
    public function dbCookie(): string
    {
        return (string) config('cmt.db_cookie', 'cmt_db');
    }

    public function defaultName(): string
    {
        return $this->default;
    }

    /** A well-formed name for a DB that actually exists in the data dir. */
    public function isValidName(string $name): bool
    {
        // The regex doubles as path-traversal protection: cookie values never
        // reach the filesystem unless they match it.
        return Str::isMatch('/^[A-Za-z0-9_-]+$/', $name)
            && in_array($name, $this->available(), true);
    }
}
