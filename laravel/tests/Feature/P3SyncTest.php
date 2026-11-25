<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CmtAuth;
use App\Support\Dbf\DbfReader;
use App\Support\Dbf\DbfTypeMap;
use App\Support\Dbf\SyncService;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P3 gate: DBF sync port (tools/sync_db.py behavior-for-behavior, R1–R10 of
 * docs/decisions/dbf-reading.md) against the committed DBF fixtures
 * (tests/fixtures/dbf — CMT nested DATA/INIT, CME flat layout).
 */
class P3SyncTest extends TestCase
{
    private string $dataDir;

    private string $dbfDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = storage_path('framework/testing/p3-'.Str::random(8));
        $this->dataDir = $base.'/data';
        $this->dbfDir = base_path('tests/fixtures/dbf');
        mkdir($this->dataDir, 0777, true);
        config([
            'cmt.data_dir' => $this->dataDir,
            'cmt.dbf_dir' => $this->dbfDir, // never the real DBF tree in tests
            'cmt.users_env' => '',
        ]);

        // Rebind the data-dir-bound services (same pattern as the P0/P1 tests)
        // so auth/queries hit this test's dir, not the repo data/.
        $factory = new CompanyConnectionFactory(
            resolver: $this->app->make(ConnectionResolverInterface::class),
            dataDir: $this->dataDir,
        );
        $this->app->instance(CompanyConnectionFactory::class, $factory);
        $this->app->instance(CmtAuth::class, new CmtAuth($factory));
    }

    // ── Reader (R4/R4b/R4c) ──────────────────────────────────────────────

    public function test_reader_reads_fixture_with_tolerance(): void
    {
        $reader = new DbfReader;
        $out = $reader->read($this->dbfDir.'/CMT/DATA/NA.DBF');

        $this->assertNotNull($out);
        $this->assertCount(2, $out['rows']);

        // Column names re-uppercased (php-xbase lower-cases; schema requires upper).
        $names = array_map(fn ($f) => $f['name'], $out['fields']);
        $this->assertContains('REFSHITMEN', $names);
        $this->assertContains('DATE', $names);

        // Valid date converts at the TYPE-MAP layer; the reader itself hands
        // back the raw 'YYYYMMDD' string (dbfread equivalence boundary).
        $dates = array_column($out['rows'], 'DATE');
        $this->assertSame('20260315', $dates[0]);
        $this->assertSame('00000000', $dates[1]);
        $this->assertSame('2026-03-15', DbfTypeMap::convert($dates[0], 'date'));
        $this->assertNull(DbfTypeMap::convert($dates[1], 'date'), 'corrupt date → NULL (R4)');

        // Raw numerics come back as strings; zero → NULL happens in convert
        // (Python `if not value` falsy semantics).
        $gws = array_column($out['rows'], 'GW');
        $this->assertSame('1000', $gws[0]); // php-xbase trims N-field zeros
        $this->assertSame(1000.0, DbfTypeMap::convert($gws[0], 'float'));
        $this->assertNull(DbfTypeMap::convert('0', 'float'), 'zero numeric → NULL');
        $this->assertSame(7, DbfTypeMap::convert('7.9', 'int'), 'int() truncation toward zero');
    }

    public function test_reader_missing_file_returns_null(): void
    {
        $this->assertNull((new DbfReader)->read($this->dbfDir.'/NOPE.DBF'));
    }

    // ── Schema details ───────────────────────────────────────────────────

    public function test_schema_uses_rowid_pk_and_upper_names(): void
    {
        $sync = $this->makeSync();
        $this->assertTrue($sync->syncSingle($this->dbfDir.'/CMT', $this->dataDir.'/CMT.db'));

        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $cols = $pdo->query('PRAGMA table_info(NA)')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame('rowid_', $cols[0]['name'], 'first column must be the rowid_ PK');
        $names = array_column($cols, 'name');
        $this->assertContains('REFSHITMEN', $names);
        $this->assertNotContains('refshitmen', $names);
    }

    // ── Sync behavior ────────────────────────────────────────────────────

    public function test_sync_builds_company_dbs_and_unified_shipment(): void
    {
        $sync = $this->makeSync();
        $result = $sync->sync($this->dbfDir, $this->dataDir);

        $this->assertTrue($result['ok'], implode("\n", $result['lines']));

        $cmt = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $this->assertSame('2', (string) $cmt->query('SELECT COUNT(*) FROM NA')->fetchColumn());
        $this->assertSame('2', (string) $cmt->query('SELECT COUNT(*) FROM CARGO')->fetchColumn());
        $this->assertSame('2', (string) $cmt->query('SELECT COUNT(*) FROM ACCOUNT')->fetchColumn());

        // UnifiedShipment dedups NA+CARGO: first UNIK per REFSHITMEN wins.
        $us = $cmt->query('SELECT COUNT(*) FROM UnifiedShipment')->fetchColumn();
        $this->assertSame('4', (string) $us);

        // CME (flat layout, ACCOUNT only) syncs; no UnifiedShipment there.
        $cme = new \PDO('sqlite:'.$this->dataDir.'/CME.db');
        $this->assertSame('2', (string) $cme->query('SELECT COUNT(*) FROM ACCOUNT')->fetchColumn());
        $this->assertSame('0', (string) $cme->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE name='UnifiedShipment'"
        )->fetchColumn());

        // No temp leftovers.
        $this->assertFileDoesNotExist($this->dataDir.'/CMT.db-sync');
    }

    public function test_sync_preserves_users_and_sessions(): void
    {
        // Seed a live DB with users/sessions first.
        $live = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $live->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $live->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, display_name TEXT, role TEXT NOT NULL, pw_hash TEXT NOT NULL, salt TEXT NOT NULL, customer_company TEXT, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')))');
        $live->exec("INSERT INTO users (username, display_name, role, pw_hash, salt) VALUES ('boss1', 'Boss1', 'staff', 'h', 's')");
        $live->exec('CREATE TABLE sessions (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), expires_at TEXT NOT NULL, FOREIGN KEY (user_id) REFERENCES users (id))');
        $live->exec("INSERT INTO sessions (token, user_id, expires_at) VALUES ('tok-1', 1, '2026-12-31T00:00:00')");
        $live = null;

        $sync = $this->makeSync();
        $this->assertTrue($sync->syncSingle($this->dbfDir.'/CMT', $this->dataDir.'/CMT.db'));

        $db = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $this->assertSame('1', (string) $db->query("SELECT COUNT(*) FROM users WHERE username='boss1'")->fetchColumn());
        $this->assertSame('1', (string) $db->query("SELECT COUNT(*) FROM sessions WHERE token='tok-1'")->fetchColumn());

        // sqlite_sequence repair (R10): next AUTOINCREMENT id must not collide.
        $db->exec("INSERT INTO users (username, display_name, role, pw_hash, salt) VALUES ('new1', 'New1', 'staff', 'h', 's')");
        $this->assertSame('2', (string) $db->query("SELECT id FROM users WHERE username='new1'")->fetchColumn());
    }

    public function test_sync_emits_json_events(): void
    {
        $sync = $this->makeSync();
        $result = $sync->sync($this->dbfDir, $this->dataDir);

        $events = array_map(fn (string $e) => json_decode($e, true), $result['events']);
        $names = array_column($events, 'event');

        $this->assertContains('sync_start', $names);
        $this->assertContains('table_synced', $names);
        $this->assertContains('sync_complete', $names);

        $decoded = end($events); // already decoded
        $this->assertTrue($decoded['ok'] ?? false, json_encode($decoded));
    }

    public function test_temp_db_discarded_on_failure(): void
    {
        // A company dir whose DBF is unreadable → no tables → sync_fail, and
        // an existing final DB must not be touched (atomic swap, R1).
        $badRoot = storage_path('framework/testing/p3-bad-'.Str::random(8));
        $badDir = $badRoot.'/BADDBF';
        mkdir($badDir, 0777, true);
        file_put_contents($badDir.'/NA.DBF', 'not a dbf at all');

        $marker = $this->dataDir.'/CMT.db';
        file_put_contents($marker, 'live db marker');

        $sync = $this->makeSync();
        $result = $sync->sync($badRoot, $this->dataDir);

        $this->assertFalse($result['ok']);
        $events = implode("\n", $result['events']);
        $this->assertStringContainsString('sync_fail', $events);
        $this->assertStringEqualsFile($marker, 'live db marker', 'final DB untouched on failure');
        $this->assertFileDoesNotExist($this->dataDir.'/BADDBF.db-sync');
    }

    // ── /sync route contract ─────────────────────────────────────────────

    public function test_sync_route_runs_real_sync_for_management(): void
    {
        $this->seedCompanySchema();
        $token = $this->login('chief1', 'chief-pw');

        $res = $this->withUnencryptedCookie('cmt_session', $token)
            ->post('/sync');
        $res->assertStatus(200);
        $this->assertTrue($res->json('ok'), json_encode($res->json()));
        $this->assertSame('chief1', $res->json('triggered_by'));
        $this->assertNotEmpty($res->json('stdout_tail'));

        // The route runs against cmt.data_dir → real output files exist.
        $this->assertFileExists($this->dataDir.'/CMT.db');
    }

    public function test_sync_route_denied_for_staff(): void
    {
        $this->seedCompanySchema();
        $token = $this->login('staff1', 'staff-pw');
        $this->withUnencryptedCookie('cmt_session', $token)
            ->post('/sync')
            ->assertStatus(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function login(string $username, string $password): string
    {
        return $this->post('/login', ['username' => $username, 'password' => $password])
            ->getCookie('cmt_session', false)->getValue();
    }

    private function makeSync(): SyncService
    {
        return new SyncService(new DbfReader);
    }

    /** users/sessions in the default company DB so /sync's route works. */
    private function seedCompanySchema(): void
    {
        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, display_name TEXT, role TEXT NOT NULL, pw_hash TEXT NOT NULL, salt TEXT NOT NULL, customer_company TEXT, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')))');
        $pdo->exec('CREATE TABLE sessions (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), expires_at TEXT NOT NULL, FOREIGN KEY (user_id) REFERENCES users (id))');
        $pdo = null;

        config(['cmt.users_env' => 'staff1:staff-pw:staff,chief1:chief-pw:management']);
        $this->app->make(CmtAuth::class)->seedFromEnv();
    }
}
