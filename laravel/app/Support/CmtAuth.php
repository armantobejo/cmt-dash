<?php

namespace App\Support;

use App\Support\CompanyConnectionFactory;
use Illuminate\Support\Facades\Log;

/**
 * Port of Python auth.py::UserStore — same schema, same PBKDF2 parameters,
 * same error strings — so both apps can run against the SAME shared
 * users/sessions tables during parallel run (PRD §8).
 *
 * Session validation is centralized here: CmtSession middleware reads the
 * cookie, CmtAuth::user() returns the per-request CmtUser (scoped binding).
 */
class CmtAuth
{
    /** Role hierarchy (descending), byte-identical to Python ROLE_LEVEL. */
    public const ROLE_LEVEL = [
        'superadmin' => 5,
        'admin' => 4,
        'management' => 3,
        'staff' => 2,
        'customer' => 1,
    ];

    public function __construct(
        protected CompanyConnectionFactory $connections,
    ) {
    }

    // ── Seeding ──────────────────────────────────────────────────────────

    /**
     * Load users from CMT_USERS (user:pass:role[:company],...). No in-source
     * default users; invalid entries skipped; idempotent (existing usernames
     * untouched). Creates the users/sessions tables first.
     */
    public function seedFromEnv(): void
    {
        $envMap = (string) config('cmt.users_env');
        if ($envMap === '') {
            return;
        }

        $conn = $this->connections->authConnection();
        $this->ensureSchema($conn);

        $existing = $conn->table('users')->pluck('username')->all();

        foreach (explode(',', $envMap) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $parts = explode(':', $entry);
            if (count($parts) !== 3 && count($parts) !== 4) {
                continue;
            }
            [$u, $p, $r] = $parts;
            if (! isset(self::ROLE_LEVEL[$r]) || in_array($u, $existing, true)) {
                continue;
            }
            if (self::isWeakCredential($u, $p)) {
                Log::warning('cmt.users_env: skipped weak/default credential entry', [
                    'username' => $u, 'role' => $r,
                ]);
                continue;
            }
            $company = $parts[3] ?? null;
            $salt = bin2hex(random_bytes(16));
            $conn->table('users')->insert([
                'username' => $u,
                // Python str.capitalize(): first CHAR upper, rest lower.
                'display_name' => self::capitalize($u),
                'role' => $r,
                'pw_hash' => self::hashPw($p, $salt),
                'salt' => $salt,
                'customer_company' => $company,
            ]);
        }
    }

    /**
     * Weak/default passwords denied for a given username (case-insensitive).
     * Shared by seedFromEnv() (via isWeakCredential) and the deploy-time
     * purge command cmt:auth:purge-weak-credentials (which verifies each
     * stored hash against this list, since passwords are only stored hashed).
     */
    public static function weakPasswordsFor(string $username): array
    {
        $weak = [
            'admin'     => ['admin', 'password', '123456', 'root', 'admin123'],
            'superadmin'=> ['superadmin', 'password', '123456', 'root', 'admin123'],
            'root'      => ['root', 'password', '123456', 'toor'],
        ];
        return $weak[strtolower($username)] ?? [];
    }

    /**
     * Reject default/weak credentials at seed time: never let CMT_USERS plant
     * admin:admin, superadmin:superadmin, or other guessable logins.
     */
    private static function isWeakCredential(string $username, string $password): bool
    {
        $p = strtolower($password);
        if ($p === '' || $p === strtolower($username)) {
            return true;
        }
        return in_array($p, self::weakPasswordsFor($username), true);
    }

    /** Create users/sessions if missing — Python db.py init_schema DDL. */
    public function ensureSchema($conn): void
    {
        $conn->statement(
            'CREATE TABLE IF NOT EXISTS users ('.
            'id INTEGER PRIMARY KEY AUTOINCREMENT,'.
            'username TEXT UNIQUE NOT NULL,'.
            'display_name TEXT,'.
            'role TEXT NOT NULL,'.
            'pw_hash TEXT NOT NULL,'.
            'salt TEXT NOT NULL,'.
            'customer_company TEXT,'.
            "created_at TEXT NOT NULL DEFAULT (datetime('now')))"
        );
        $conn->statement(
            'CREATE TABLE IF NOT EXISTS sessions ('.
            'token TEXT PRIMARY KEY,'.
            'user_id INTEGER NOT NULL,'.
            "created_at TEXT NOT NULL DEFAULT (datetime('now')),".
            'expires_at TEXT NOT NULL,'.
            'FOREIGN KEY (user_id) REFERENCES users (id))'
        );
    }

    // ── Authentication ───────────────────────────────────────────────────

    /** Verify credentials; on success create a session row and return its token. */
    public function authenticate(string $username, string $password): ?string
    {
        $conn = $this->connections->authConnection();
        $this->ensureSchema($conn);
        $row = $conn->table('users')->where('username', $username)->first();

        if ($row === null || ! hash_equals($row->pw_hash, self::hashPw($password, $row->salt))) {
            Log::info('user_login', ['username' => $username, 'outcome' => 'fail']);

            return null;
        }

        $token = $this->token();

        $conn->table('sessions')->insert([
            'token' => $token,
            'user_id' => $row->id,
            'expires_at' => self::expiryFromNow(),
        ]);
        Log::info('user_login', ['username' => $username, 'outcome' => 'success']);

        return $token;
    }

    /** Invalidate a session (logout). */
    public function logout(string $token): void
    {
        $conn = $this->connections->authConnection();
        $this->ensureSchema($conn);
        $row = $conn->table('sessions')->where('token', $token)->first();
        $conn->table('sessions')->where('token', $token)->delete();
        if ($row) {
            $username = $conn->table('users')->where('id', $row->user_id)->value('username');
            Log::info('user_logout', ['username' => $username]);
        }
    }

    /** Load a user by session token; expired sessions are deleted. */
    public function userForToken(string $token): ?CmtUser
    {
        $conn = $this->connections->authConnection();
        $this->ensureSchema($conn);
        $row = $conn->table('sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('sessions.token', $token)
            ->first(['users.username', 'users.display_name', 'users.role', 'users.customer_company', 'sessions.expires_at']);

        if ($row === null) {
            return null;
        }

        // String comparison on the TEXT column, mirroring the Python compare
        // (expires_at is stored as Python datetime.isoformat() by both apps).
        if ($row->expires_at <= self::isoNow()) {
            $conn->table('sessions')->where('token', $token)->delete();
            Log::info('session_expired', ['username' => $row->username]);

            return null;
        }

        return new CmtUser(
            username: $row->username,
            displayName: $row->display_name ?? '',
            role: $row->role,
            authenticated: true,
            customerCompany: $row->customer_company ?? '',
        );
    }

    // ── User management (admin+) — error strings match Python exactly ────

    /** All users (excluding password data), ordered role asc then username. */
    public function listUsers(): array
    {
        $conn = $this->connections->authConnection();
        $this->ensureSchema($conn);
        return $conn
            ->table('users')
            ->orderBy('role')->orderBy('username')
            ->get(['id', 'username', 'display_name', 'role', 'customer_company', 'created_at'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** Single user by ID (excluding password data), or null. */
    public function getUser(int $userId): ?array
    {
        $conn = $this->connections->authConnection();
        $this->ensureSchema($conn);
        $row = $conn
            ->table('users')->where('id', $userId)
            ->first(['id', 'username', 'display_name', 'role', 'customer_company', 'created_at']);

        return $row !== null ? (array) $row : null;
    }

    /** Create a user. Returns an error message on failure, null on success.
     *  If $actorLevel is provided (non-zero), reject target roles above actor's level. */
    public function createUser(string $username, string $displayName, string $role,
        string $password, string $customerCompany = '', int $actorLevel = 0): ?string
    {
        if (! isset(self::ROLE_LEVEL[$role])) {
            return 'Invalid role';
        }
        if ($actorLevel > 0 && self::ROLE_LEVEL[$role] > $actorLevel) {
            return 'Cannot create user with role above your level';
        }
        if (trim($username) === '') {
            return 'Username is required';
        }
        if ($password === '') {
            return 'Password is required';
        }
        if (self::isWeakCredential($username, $password)) {
            return 'Weak or default credential';
        }

        $salt = bin2hex(random_bytes(16));
        try {
            $conn = $this->connections->authConnection();
            $this->ensureSchema($conn);
            $conn->table('users')->insert([
                'username' => trim($username),
                'display_name' => trim($displayName) !== '' ? trim($displayName) : trim($username),
                'role' => $role,
                'pw_hash' => self::hashPw($password, $salt),
                'salt' => $salt,
                'customer_company' => trim($customerCompany) !== '' ? trim($customerCompany) : null,
            ]);
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), 'UNIQUE')
                ? 'Username already exists'
                : $e->getMessage();
        }

        return null;
    }

    /** Update a user. Returns an error message on failure, null on success.
     *  If $actorLevel is provided (non-zero), reject target roles above actor's level. */
    public function updateUser(int $userId, string $displayName, string $role,
        string $customerCompany = '', string $password = '', int $actorLevel = 0): ?string
    {
        if (! isset(self::ROLE_LEVEL[$role])) {
            return 'Invalid role';
        }
        if ($actorLevel > 0 && self::ROLE_LEVEL[$role] > $actorLevel) {
            return 'Cannot update user to role above your level';
        }

        $conn = $this->connections->authConnection();
        $this->ensureSchema($conn);

        // SC-001 hardening: when a password is being set/changed, reject
        // weak/default credentials (admin:admin, root:root, username==password)
        // just like the seed path — so an admin cannot re-plant a weak login.
        if ($password !== '') {
            $existingUser = $conn->table('users')->where('id', $userId)->value('username');
            if ($existingUser !== null && self::isWeakCredential($existingUser, $password)) {
                return 'Weak or default credential';
            }
        }

        $values = [
            'display_name' => trim($displayName),
            'role' => $role,
            'customer_company' => trim($customerCompany) !== '' ? trim($customerCompany) : null,
        ];
        if ($password !== '') {
            $salt = bin2hex(random_bytes(16));
            $values['pw_hash'] = self::hashPw($password, $salt);
            $values['salt'] = $salt;
        }

        $conn->table('users')->where('id', $userId)->update($values);

        return null;
    }

    /** Delete a user and their sessions. Returns an error message, or null. */
    public function deleteUser(int $userId): ?string
    {
        $conn = $this->connections->authConnection();
        $conn->table('sessions')->where('user_id', $userId)->delete();
        $conn->table('users')->where('id', $userId)->delete();

        return null;
    }

    // ── Crypto (Python-exact) ────────────────────────────────────────────

    /**
     * PBKDF2-HMAC-SHA256, 200_000 iterations, 32-BYTE key, hex digest —
     * byte-compatible with Python auth.py::_hash so the same shared users
     * table verifies under both apps. NOTE: hash_pbkdf2's $length counts
     * HEX CHARACTERS (Python's dklen counts BYTES), so 32 bytes = 64 here.
     */
    public static function hashPw(string $pw, string $salt): string
    {
        return hash_pbkdf2('sha256', $pw, $salt, 200000, 64, false);
    }

    /** Session token — equivalent entropy to Python secrets.token_urlsafe(32). */
    protected function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Python str.capitalize(): first character upper-cased, rest lower. */
    public static function capitalize(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_strtolower(mb_substr($s, 1));
    }

    /** Python datetime.isoformat() in UTC: 2026-09-22T13:45:12.123456+00:00. */
    public static function isoNow(): string
    {
        return self::iso(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** Expiry timestamp for a fresh session (SESSION_TTL = 8h in Python). */
    public static function expiryFromNow(): string
    {
        $ttl = (int) config('cmt.session_ttl_hours', 8);

        return self::iso(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("+{$ttl} hours")
        );
    }

    protected static function iso(\DateTimeImmutable $t): string
    {
        return $t->format('Y-m-d\TH:i:s.uP');
    }
}
