<?php

namespace Tests\Parity\Suite;

use App\Support\CmtAuth;
use App\Support\CompanyConnectionFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixture for the parity test suite.
 *
 * Every suite class runs against an isolated company DB (temp dir + fresh
 * SQLite) with a fixed, deterministic row set — no timestamps, no random ids —
 * so a snapshot of app state is byte-identical across runs.
 */
abstract class ParitySuiteTestCase extends TestCase
{
    /** All five roles, seeded in one go (matches docs/parity-checklist.yaml role matrix). */
    protected const ROLE_USERS = [
        'superadmin' => ['root', 'root-pw'],
        'admin'      => ['admin1', 'admin-pw'],
        'management' => ['manager1', 'mgmt-pw'],
        'staff'      => ['staff1', 'staff-pw'],
        // Customer users carry a company filter (Python: customer_company).
        'customer'   => ['cust1', 'cust-pw', 'TRISCO'],
    ];

    protected string $dataDir;

    /** Fixed salt + digest cache: PBKDF2 is the whole per-test cost otherwise. */
    private const FIXTURE_SALT = 'parity-suite-fixture-salt';

    /** @var array<string, string> */
    private static array $pwHashCache = [];

    /** @var array<string, string> per-test session tokens, by role */
    private array $tokenCache = [];

    /** @var array<string, string> process-wide session tokens, by role */
    private static array $sessionTokens = [];

    /** Cleared once per PHPUnit process so `.current` holds exactly this run. */
    private static bool $currentCleared = false;

    /** Fixture dir built once per PHPUnit process, reused by every test. */
    private static ?string $fixtureDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompanyFixture();

        if (! self::$currentCleared) {
            $this->clearDirectory(SnapshotStore::currentDir());
            self::$currentCleared = true;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ── Fixture ──────────────────────────────────────────────────────────

    /**
     * Build the company DB once per process (schema + deterministic dataset +
     * users cost ~9s), then reuse it: each test only resets the tables it may
     * have written to.
     */
    protected function bootCompanyFixture(): void
    {
        if (self::$fixtureDir !== null) {
            $this->attachFixture(self::$fixtureDir);
            $this->resetMutableState();

            return;
        }

        $dir = storage_path('framework/testing/parity-suite-' . Str::random(8));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        register_shutdown_function(fn () => $this->clearDirectory($dir));

        $this->dataDir = $dir;
        $dbPath = $dir . '/CMT.db';
        touch($dbPath);

        config(['cmt.data_dir' => $dir]);
        config(['cmt.users_env' => '']);

        // One SQLite file for both views of the data: the app queries the
        // company connection (cmt_CMT) while migrations and the parity dataset
        // use the framework's default connection. Point both at the same file
        // so `php artisan migrate` + ParityDataset::build() populate exactly
        // what the HTTP layer reads.
        config(['database.connections.sqlite.database' => $dbPath]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->bindServices($dir);

        $this->artisan('migrate');

        // Laravel's default migrations also create users/sessions; the app's
        // own (Python-parity) schema wins — drop and recreate them below.
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');

        // Real production schema (dumped from the reference app:
        // tests/parity/fixtures/real_schema.json) + a deterministic row set, so
        // every page query runs against the columns the app actually reads.
        $this->seedReferenceSchema($dbPath);

        $this->seedUsers();

        self::$fixtureDir = $dir;
    }

    private function attachFixture(string $dir): void
    {
        $this->dataDir = $dir;
        config(['cmt.data_dir' => $dir]);
        config(['cmt.users_env' => '']);
        config(['database.connections.sqlite.database' => $dir . '/CMT.db']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->bindServices($dir);
    }

    private function bindServices(string $dir): void
    {
        $factory = new CompanyConnectionFactory(
            resolver: $this->app->make(ConnectionResolverInterface::class),
            dataDir: $dir,
        );
        $this->app->instance(CompanyConnectionFactory::class, $factory);
        $this->app->instance(CmtAuth::class, new CmtAuth($factory));
        $factory->connection('CMT');
    }

    /** Rows a test may write: ledger entries and sessions. */
    private function resetMutableState(): void
    {
        $this->app->make(CompanyConnectionFactory::class)
            ->authConnection()
            ->table('gl_ledger_entries')
            ->delete();

        $this->app->make(CompanyConnectionFactory::class)
            ->authConnection()
            ->table('sessions')
            ->delete();

        self::$sessionTokens = [];
        $this->tokenCache = [];
    }

    /**
     * Production schema + deterministic rows.
     *
     * Columns come from the reference app's schema dump
     * (tests/parity/fixtures/real_schema.json → CMT) rather than a hand-rolled
     * fixture, so a query against a real column can never silently 500 here.
     * Rows are fixed values (no timestamps, no random ids) to keep snapshots
     * byte-stable across runs.
     */
    protected function seedReferenceSchema(string $path): void
    {
        $schemaFile = base_path('../tests/parity/fixtures/real_schema.json');
        $schema = json_decode((string) file_get_contents($schemaFile), true);
        $tables = $schema['CMT'] ?? [];
        $this->assertNotEmpty($tables, 'reference schema dump is missing CMT tables');

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        foreach ($tables as $table => $columns) {
            // Auth tables are owned by CmtAuth::ensureSchema (id INTEGER PRIMARY
            // KEY AUTOINCREMENT); the schema dump cannot express that, and a
            // plain INTEGER id column would insert NULL ids.
            if (in_array($table, ["users", "sessions"], true)) {
                continue;
            }

            $defs = [];
            foreach ($columns as $name => $type) {
                $defs[] = '"' . $name . '" ' . $type;
            }
            $pdo->exec('CREATE TABLE IF NOT EXISTS "' . $table . '" (' . implode(', ', $defs) . ')');
        }

        foreach ($this->fixtureRows() as $table => $rows) {
            foreach ($rows as $row) {
                $columns = implode(', ', array_map(fn ($c) => '"' . $c . '"', array_keys($row)));
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $pdo->prepare('INSERT INTO "' . $table . '" (' . $columns . ') VALUES (' . $placeholders . ')')
                    ->execute(array_values($row));
            }
        }
    }

    /**
     * Deterministic rows for the parity surfaces. The customer filter is the
     * substring TRISCO (Python `customer_company`), so rows carry TRISCO or
     * MAERSK LINE as the counterparty.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function fixtureRows(): array
    {
        $customer = 'TRISCO';
        $other = 'MAERSK LINE';

        return [
            'UnifiedShipment' => [
                ['UNIK' => 'U1', 'SALES' => 'S001', 'THN' => '2026', 'BLNO3' => 'BL-001', 'DATE' => '2026-03-15',
                    'REFSHITMEN' => 'REF-TRISCO-001', 'AGENT' => 'AG1', 'CODE' => 'C01', 'KD_CONSIG' => 'C1',
                    'CONSIGNEE' => $customer, 'KD_SHIPPER' => 'S1', 'SHIPPER' => $customer,
                    'SHIPPINGMA' => 'EVERGREEN', 'TYPETRAN' => 'I', 'ETD' => '2026-03-01', 'ETA' => '2026-03-20',
                    'GW' => 10.0, 'NW' => 8.0, 'MEAST' => 12.0, 'MEAST_SAT' => 'CBM', 'PORTOFLOAD' => 'SIN',
                    'PORTOFDISC' => 'JKT', 'DESCRIPTIO' => 'Gen cargo', 'SAIDTOCONT' => 'SAID',
                    'PRINT' => 'Y', 'PRINTDATE' => '2026-03-01'],
                ['UNIK' => 'U2', 'SALES' => 'S002', 'THN' => '2026', 'BLNO3' => 'BL-002', 'DATE' => '2026-03-16',
                    'REFSHITMEN' => 'REF-TRISCO-002', 'AGENT' => 'AG1', 'CODE' => 'C02', 'KD_CONSIG' => 'C1',
                    'CONSIGNEE' => $customer, 'KD_SHIPPER' => 'S2', 'SHIPPER' => $other,
                    'SHIPPINGMA' => 'EVERGREEN', 'TYPETRAN' => 'E', 'ETD' => '2026-03-02', 'ETA' => '2026-03-21',
                    'GW' => 20.0, 'NW' => 18.0, 'MEAST' => 24.0, 'MEAST_SAT' => 'CBM', 'PORTOFLOAD' => 'SIN',
                    'PORTOFDISC' => 'JKT', 'DESCRIPTIO' => 'Gen cargo', 'SAIDTOCONT' => 'SAID',
                    'PRINT' => 'Y', 'PRINTDATE' => '2026-03-02'],
                ['UNIK' => 'U3', 'SALES' => 'S003', 'THN' => '2026', 'BLNO3' => 'BL-003', 'DATE' => '2026-03-17',
                    'REFSHITMEN' => 'REF-OTHER-001', 'AGENT' => 'AG2', 'CODE' => 'C03', 'KD_CONSIG' => 'C2',
                    'CONSIGNEE' => $other, 'KD_SHIPPER' => 'S2', 'SHIPPER' => $other,
                    'SHIPPINGMA' => 'MAERSK', 'TYPETRAN' => 'I', 'ETD' => '2026-03-03', 'ETA' => '2026-03-22',
                    'GW' => 30.0, 'NW' => 28.0, 'MEAST' => 36.0, 'MEAST_SAT' => 'CBM', 'PORTOFLOAD' => 'SIN',
                    'PORTOFDISC' => 'JKT', 'DESCRIPTIO' => 'Gen cargo', 'SAIDTOCONT' => 'SAID',
                    'PRINT' => 'Y', 'PRINTDATE' => '2026-03-03'],
            ],
            'CONSIGNEE' => [
                ['KD_CONSIG' => 'C1', 'NAME' => $customer],
                ['KD_CONSIG' => 'C2', 'NAME' => $other],
            ],
            'SHIPPER' => [
                ['KD_SHIPPER' => 'S1', 'NAME' => $customer],
                ['KD_SHIPPER' => 'S2', 'NAME' => $other],
            ],
            'AGENT' => [
                ['AGENT' => 'AG1', 'CODE' => 'C01', 'NAME' => 'EVERGREEN'],
                ['AGENT' => 'AG2', 'CODE' => 'C03', 'NAME' => 'MAERSK'],
            ],
            'VESSEL' => [
                ['KD_VESSEL' => 'V1', 'NM_VESSEL' => 'TRISCO SHIP'],
            ],
            'INVOICE' => [
                ['K_INVOICE' => '1', 'UNIK' => 'I1', 'TYPE' => 'N', 'EI' => 'E', 'REFSHITMEN' => 'REF-TRISCO-001',
                    'NOINVOICE' => 'INV-0001', 'DATEOFISSU' => '2026-03-18', 'SHIPPER' => $customer,
                    'THN' => '2026', 'EXRATE' => 1.0, 'GRAND_USD' => 100.0, 'GRAND_RP' => 1500000.0,
                    'BYR_USD' => 0.0, 'BYR_RP' => 0.0, 'DIRPKAN' => 100.0, 'DIUSDKAN' => 100.0,
                    'SELISIH_US' => 0.0, 'SELISIH_ID' => 0.0, 'LUNAS' => 1, 'POSTING' => 1, 'PRINT' => 1,
                    'PRINTDATE' => '2026-03-18', 'ENTRY' => 'E'],
                ['K_INVOICE' => '2', 'UNIK' => 'I2', 'TYPE' => 'N', 'EI' => 'I', 'REFSHITMEN' => 'REF-OTHER-001',
                    'NOINVOICE' => 'INV-0002', 'DATEOFISSU' => '2026-03-19', 'SHIPPER' => $other,
                    'THN' => '2026', 'EXRATE' => 1.0, 'GRAND_USD' => 200.0, 'GRAND_RP' => 3000000.0,
                    'BYR_USD' => 0.0, 'BYR_RP' => 0.0, 'DIRPKAN' => 0.0, 'DIUSDKAN' => 0.0,
                    'SELISIH_US' => 0.0, 'SELISIH_ID' => 0.0, 'LUNAS' => 0, 'POSTING' => 1, 'PRINT' => 1,
                    'PRINTDATE' => '2026-03-19', 'ENTRY' => 'E'],
            ],
            'OPRCOST' => [
                ['UOPRCOST' => 'O1', 'NOMOR' => 'N1', 'TANGGAL' => '2026-03-18', 'EI' => 'E',
                    'REFSHITMEN' => 'REF-TRISCO-001', 'KD_SERVICE' => 'FREIGHT', 'EXRATE' => 1.0,
                    'GRAND_USD' => 10.5, 'GRAND_RP' => 157500.0, 'PRINT' => 1, 'PRINTDATE' => '2026-03-18'],
            ],
            'ACCOUNT' => [
                ['KD_ACCOUNT' => '1101', 'NM_ACCOUNT' => 'CASH IN BANK', 'SLD_NORMAL' => 'D',
                    'TYPE' => 'detail', 'GROUP' => 1100, 'ACTIVE' => 'Y'],
                ['KD_ACCOUNT' => '2101', 'NM_ACCOUNT' => 'ACCOUNT PAYABLE', 'SLD_NORMAL' => 'C',
                    'TYPE' => 'detail', 'GROUP' => 2100, 'ACTIVE' => 'Y'],
            ],
            'JE' => [
                ['UJE' => 'JE-1', 'REF_NO' => 'NO-1', 'TANGGAL' => '2026-03-18', 'KETERANGAN' => 'Opening',
                    'MODUL' => 'GL', 'THN' => '2026', 'TOT_DR' => 100.0, 'TOT_CR' => 100.0, 'PRINT' => 1],
            ],
            'JE_D' => [
                ['UJE' => 'JE-1', 'U_JE_D' => 'JED-1', 'KD_ACCOUNT' => '1101', 'URAIAN' => 'Opening',
                    'JUMLAH' => 100.0, 'TANGGAL' => '2026-03-18', 'CURRENCY' => 'IDR',
                    'DK' => 'D', 'BARIS' => 1],
            ],
            'DRCRFULL' => [
                ['UNIK' => 'D1', 'NO_REF' => 'NO-1', 'TANGGAL' => '2026-03-18', 'EI' => 'E',
                    'REFSHITMEN' => 'REF-TRISCO-001', 'THN' => '2026', 'TONAME' => $customer, 'MODE' => 'SEA',
                    'EXRATE' => 1.0, 'TOTAL' => 100.0, 'TOTAL_IDR' => 1500000.0, 'BAYAR' => 'Y',
                    'DIRPKAN' => 100.0, 'BAYAR_IDR' => 1500000.0, 'SELISIH' => 0.0, 'SELISIH_ID' => 0.0,
                    'LUNAS' => 1, 'ACCT_DRCR' => 'D', 'PRINT' => 1],
            ],
        ];
    }

    protected function seedUsers(): void
    {
        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        $this->app->make(CmtAuth::class)->ensureSchema($conn);

        foreach (self::ROLE_USERS as $role => $creds) {
            [$username, $password] = $creds;
            $company = $creds[2] ?? null;

            // PBKDF2 at 200k iterations costs ~0.5s on this host; hash each
            // password once per process and reuse the digest across tests.
            $key = $username . ':' . $password;
            if (! isset(self::$pwHashCache[$key])) {
                self::$pwHashCache[$key] = CmtAuth::hashPw($password, self::FIXTURE_SALT);
            }

            $conn->table('users')->insert([
                'username' => $username,
                'display_name' => CmtAuth::capitalize($username),
                'role' => $role,
                'pw_hash' => self::$pwHashCache[$key],
                'salt' => self::FIXTURE_SALT,
                'customer_company' => $company,
            ]);
        }
    }

    // ── Auth helpers ─────────────────────────────────────────────────────

    protected function auth(): CmtAuth
    {
        return $this->app->make(CmtAuth::class);
    }

    /**
     * Session token for a role, minted straight into the sessions table.
     * The real login path is exercised by ControllersParityTest/ValidationParityTest;
     * here it would only add ~0.5s of PBKDF2 per call.
     */
    protected function tokenFor(string $role): string
    {
        if (isset($this->tokenCache[$role])) {
            return $this->tokenCache[$role];
        }

        $username = self::ROLE_USERS[$role][0];
        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        $userId = $conn->table('users')->where('username', $username)->value('id');
        $this->assertNotNull($userId, "fixture user {$username} missing for role {$role}");

        // Single shared token per role: the auth connection is a singleton, so
        // `sessions.token` must stay unique across the whole PHPUnit process.
        $token = 'parity-' . $role;
        if (! isset(self::$sessionTokens[$role])) {
            $conn->table('sessions')->insert([
                'token' => $token,
                'user_id' => $userId,
                'expires_at' => CmtAuth::expiryFromNow(),
            ]);
            self::$sessionTokens[$role] = $token;
        }

        $this->tokenCache[$role] = self::$sessionTokens[$role];

        return $this->tokenCache[$role];
    }

    /**
     * A session row no other test shares. Use for tests that mutate the session
     * itself (logout, expiry) — the shared tokenFor() row must survive the run.
     */
    protected function throwawayToken(string $role): string
    {
        $username = self::ROLE_USERS[$role][0];
        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        $userId = $conn->table('users')->where('username', $username)->value('id');
        $this->assertNotNull($userId, "fixture user {$username} missing for role {$role}");

        $token = 'parity-throwaway-' . bin2hex(random_bytes(8));
        $conn->table('sessions')->insert([
            'token' => $token,
            'user_id' => $userId,
            'expires_at' => CmtAuth::expiryFromNow(),
        ]);

        return $token;
    }
    protected function asRole(string $role): static
    {
        return $this->withoutMiddleware(ThrottleRequests::class)
            ->withCredentials()
            ->withUnencryptedCookie('cmt_session', $this->tokenFor($role));
    }

    /**
     * Guest context. `withCredentials()` matters: Laravel drops cookies on
     * getJson()/postJson() unless credentials are enabled, which would make
     * every authenticated JSON request look anonymous.
     */
    protected function guest(): static
    {
        return $this->withoutMiddleware(ThrottleRequests::class)->withCredentials();
    }

    // ── Snapshot helpers ─────────────────────────────────────────────────

    /**
     * Compare one snapshot case with the committed baseline; record the current
     * capture under tests/snapshots/.current/ for `tools/snapshot_diff.php`.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function snapshotCase(string $file, string $case, array $payload): void
    {
        // Snapshot comparison is an assertion even in record mode (where the
        // baseline is simply rewritten), so a case never counts as "risky".
        $this->addToAssertionCount(1);

        $result = SnapshotStore::put($file, $case, $payload);

        if ($result['updated']) {
            return;
        }

        if ($result['baseline'] === null) {
            $this->fail(sprintf(
                "snapshot %s/%s has no baseline; regenerate with UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --testsuite Parity",
                $file,
                $case
            ));
        }

        $diffs = SnapshotStore::diff($result['baseline'], $result['current'], $file . '/' . $case);
        if ($diffs !== []) {
            $lines = array_map(
                fn (array $d) => sprintf('  [%s] %s | baseline=%s | current=%s', $d['kind'], $d['path'], $d['baseline'], $d['current']),
                array_slice($diffs, 0, 12)
            );
            $this->fail("snapshot variance in {$file}/{$case}:\n" . implode("\n", $lines));
        }

        $this->assertSame(
            SnapshotStore::canonical($result['baseline']),
            SnapshotStore::canonical($result['current']),
            "snapshot bytes differ for {$file}/{$case}"
        );
    }

    /** Ordered, whitespace-normalized SQL shapes (timings dropped). */
    protected function sqlShapes(array $queryLog): array
    {
        return array_map(
            fn (array $entry) => preg_replace('/\s+/', ' ', trim((string) ($entry['query'] ?? ''))),
            $queryLog
        );
    }

    /** JSON-safe shape of view data: keys + scalars; collections summarized. */
    protected function viewShape(\Illuminate\View\View $view): array
    {
        $out = [];
        foreach ($view->getData() as $key => $value) {
            $out[$key] = $this->scalarShape($value);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    private function scalarShape(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            return round($value, 4);
        }
        if ($value instanceof \Closure) {
            return 'closure';
        }
        if (is_array($value)) {
            return ['array', count($value), array_slice(array_keys($value), 0, 12)];
        }
        if (is_object($value)) {
            return 'object:' . get_class($value);
        }

        return gettype($value);
    }

    protected function clearDirectory(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
            is_dir($entry) ? $this->clearDirectory($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }
}
