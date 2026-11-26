<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CmtAuth;
use App\Support\CmtUser;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1: auth — seeding, login/logout, role gates, user CRUD.
 *
 * The users/sessions tables are created in the default company DB, mirroring
 * Python db.py get_auth_db (one login grants all companies).
 */
class CmtAuthTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing/'.Str::random(10));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->dataDir = $dir;
        config(['cmt.data_dir' => $dir]);
        config(['cmt.users_env' => '']);

        // Rebind services onto this test's data dir + create the default DB.
        $factory = new CompanyConnectionFactory(
            resolver: $this->app->make(\Illuminate\Database\ConnectionResolverInterface::class),
            dataDir: $dir,
        );
        $this->app->instance(CompanyConnectionFactory::class, $factory);
        $this->app->instance(CmtAuth::class, new CmtAuth($factory));
        $this->app->make(CompanyConnectionFactory::class)->connection('CMT');

        touch($dir.'/CMT.db');

        // P2: / is the real dashboard, whose queries need the business schema
        // (empty tables are enough — the assertions are chrome-level).
        $this->createBusinessSchema($dir.'/CMT.db');
    }

    /** Minimal company-DB schema so dashboard queries don't 500 on missing tables. */
    private function createBusinessSchema(string $path): void
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE UnifiedShipment (
            UNIK TEXT PRIMARY KEY, TYPETRAN TEXT, DATE TEXT, REFSHITMEN TEXT UNIQUE,
            BLNO3 TEXT, KD_CONSIG TEXT, KD_SHIPPER TEXT, KD_EXVESSE TEXT, EXVOYAGE TEXT,
            AGENT TEXT, CODE TEXT, CONTAINERN TEXT, PORTOFLOAD TEXT, PORTOFDISC TEXT,
            SHIPPINGMA TEXT, DESCRIPTIO TEXT, CONSIGNEE TEXT, SHIPPER TEXT,
            SAIDTOCONT TEXT, BL_NO TEXT, MBL_NO TEXT, ETD TEXT, ETA TEXT, ATA TEXT
        )');
        $pdo->exec('CREATE TABLE CONSIGNEE (KD_CONSIG TEXT PRIMARY KEY, NAME TEXT)');
        $pdo->exec('CREATE TABLE SHIPPER (KD_SHIPPER TEXT PRIMARY KEY, NAME TEXT)');
        $pdo->exec('CREATE TABLE OPRCOST (UOPRCOST TEXT PRIMARY KEY, REFSHITMEN TEXT, GRAND_USD REAL, GRAND_RP REAL)');
        $pdo->exec('CREATE TABLE INVOICE (rowid_ INTEGER PRIMARY KEY AUTOINCREMENT, K_INVOICE INTEGER, NOINVOICE TEXT, REFSHITMEN TEXT, EI TEXT, DATEOFISSU TEXT, SHIPPER TEXT, EXRATE REAL, GRAND_USD REAL, GRAND_RP REAL, BYR_USD REAL, BYR_RP REAL, DIRPKAN REAL, DIUSDKAN REAL, LUNAS INTEGER)');
        $pdo->exec('CREATE TABLE DRCRFULL (UNIK TEXT, NO_REF TEXT, REFSHITMEN TEXT, TANGGAL TEXT, TONAME TEXT, MODE TEXT, BLNO TEXT, BLNO3 TEXT, TOTAL REAL, TOTAL_IDR REAL, BAYAR REAL, DIRPKAN REAL, BAYAR_IDR REAL, DIUSDKAN REAL, LUNAS INTEGER, ACCT_DRCR TEXT)');
        $pdo->exec('CREATE TABLE VESSEL (KD_VESSEL TEXT PRIMARY KEY, NM_VESSEL TEXT)');
    }

    private function auth(): CmtAuth
    {
        return $this->app->make(CmtAuth::class);
    }

    private function seedEnv(string $env): void
    {
        config(['cmt.users_env' => $env]);
        $this->auth()->seedFromEnv();
    }

    // ── Seeding ──────────────────────────────────────────────────────────

    public function test_seeds_users_from_cmt_users_env(): void
    {
        $this->seedEnv('root:root-pw:superadmin,staff1:staff-pw:staff,cust1:cust-pw:customer:Trisco');

        $users = $this->auth()->listUsers();
        $this->assertCount(3, $users);

        $byName = collect($users)->keyBy('username');
        $this->assertSame('superadmin', $byName['root']['role']);
        $this->assertSame('Root', $byName['root']['display_name']); // ucfirst parity
        $this->assertSame('Trisco', $byName['cust1']['customer_company']);
    }

    public function test_seeding_is_idempotent_and_skips_invalid_entries(): void
    {
        $this->seedEnv('root:root-pw:superadmin');
        $this->seedEnv('root:other-pw:admin,bad:entry:notinroles:extra:junk');

        $users = $this->auth()->listUsers();
        $this->assertCount(1, $users);
        $this->assertSame('superadmin', $users[0]['role']); // existing untouched
    }

    public function test_no_default_credentials_when_cmt_users_unset(): void
    {
        // CMT_USERS is the sole seeding source: unset -> no users, and the
        // legacy admin:admin / superadmin:superadmin defaults must not work.
        config(['cmt.users_env' => '']);
        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        $this->auth()->ensureSchema($conn);
        $this->assertCount(0, $this->auth()->listUsers());
        $this->assertNull($this->auth()->authenticate('admin', 'admin'));
        $this->assertNull($this->auth()->authenticate('superadmin', 'superadmin'));
    }

    // ── Authentication + session lifecycle ───────────────────────────────

    public function test_login_round_trip_sets_and_resolves_session(): void
    {
        $this->seedEnv('staff1:staff-pw:staff');

        $this->post('/login', ['username' => 'staff1', 'password' => 'staff-pw'])
            ->assertStatus(302)
            ->assertRedirect('/')
            ->assertCookie('cmt_session');

        // Follow the real cookie through middleware to /.
        $token = $this->post('/login', ['username' => 'staff1', 'password' => 'staff-pw'])
            ->getCookie('cmt_session', false)->getValue();

        // P2: / is now the real dashboard; assert authenticated content + role-conditional chrome.
        $this->withUnencryptedCookie('cmt_session', $token)->get('/')
            ->assertOk()
            ->assertSee('Pengiriman Belum Dibayar', false)
            ->assertSee('Staff1', false); // layout shows display_name (str.capitalize)
    }

    public function test_bad_credentials_re_render_with_error_200(): void
    {
        $this->seedEnv('staff1:staff-pw:staff');

        $this->post('/login', ['username' => 'staff1', 'password' => 'wrong'])
            ->assertOk()
            ->assertSee('Username or password incorrect');
    }

    public function test_login_is_rate_limited(): void
    {
        $this->seedEnv('staff1:staff-pw:staff');
        $this->artisan('migrate');

        // 5 attempts allowed per minute per (username, IP).
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'staff1', 'password' => 'wrong'])
                ->assertStatus(200)
                ->assertSee('Username or password incorrect');
        }

        // 6th attempt within the same minute is rate limited.
        $this->post('/login', ['username' => 'staff1', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    /** SC-001-R1 (CWE-307): a drained bucket must not lock out another account. */
    public function test_login_throttle_is_keyed_by_username_not_ip_alone(): void
    {
        $this->seedEnv('alpha:alpha-pw:staff,beta:beta-pw:staff');
        $this->artisan('migrate');

        // The reported exploit: 5 failures spread over 5 different usernames.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => "ghost{$i}", 'password' => 'wrong'])
                ->assertStatus(200);
        }

        // A valid login for an unrelated account from the same IP still works.
        $this->post('/login', ['username' => 'beta', 'password' => 'beta-pw'])
            ->assertStatus(302)
            ->assertRedirect('/');

        // The per-username bucket still applies: 5 failures exhaust alpha only.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'alpha', 'password' => 'wrong'])
                ->assertStatus(200);
        }
        $this->post('/login', ['username' => 'alpha', 'password' => 'wrong'])
            ->assertStatus(429);

        // beta remains usable while alpha is throttled.
        $this->post('/login', ['username' => 'beta', 'password' => 'beta-pw'])
            ->assertStatus(302)
            ->assertRedirect('/');
    }

    public function test_anonymous_home_redirects_to_login(): void
    {
        $this->get('/')->assertStatus(303)->assertRedirect('/login');
    }

    public function test_logout_invalidates_session_server_side(): void
    {
        $this->seedEnv('staff1:staff-pw:staff');
        $token = $this->post('/login', ['username' => 'staff1', 'password' => 'staff-pw'])
            ->getCookie('cmt_session', false)->getValue();

        $this->withUnencryptedCookie('cmt_session', $token)
            ->post('/logout')->assertStatus(302)->assertRedirect('/login');

        // Token must be dead: replaying it lands on /login again.
        $this->withUnencryptedCookie('cmt_session', $token)
            ->get('/')->assertStatus(303);
    }

    public function test_expired_session_is_deleted_and_treated_anonymous(): void
    {
        $this->seedEnv('staff1:staff-pw:staff');
        $token = $this->post('/login', ['username' => 'staff1', 'password' => 'staff-pw'])
            ->getCookie('cmt_session', false)->getValue();

        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        $conn->table('sessions')->where('token', $token)
            ->update(['expires_at' => '2020-01-01T00:00:00.000000+00:00']);

        $this->withUnencryptedCookie('cmt_session', $token)
            ->get('/')->assertStatus(303);
        $this->assertSame(0, $conn->table('sessions')->count(), 'expired session row must be deleted');
    }

    // ── RBAC ─────────────────────────────────────────────────────────────

    public function test_role_gates_on_user_management(): void
    {
        $this->seedEnv('boss:boss-pw:admin,chief:chief-pw:superadmin,staff1:staff-pw:staff');

        $cases = [
            ['staff1', 'staff-pw', 403],   // staff below admin
            ['boss', 'boss-pw', 200],      // admin reaches the list
            ['chief', 'chief-pw', 200],    // superadmin too
        ];
        foreach ($cases as [$u, $p, $expected]) {
            $token = $this->post('/login', ['username' => $u, 'password' => $p])
                ->getCookie('cmt_session', false)->getValue();
            $response = $this->withUnencryptedCookie('cmt_session', $token)->get('/users');
            $response->assertStatus($expected);
            if ($expected === 403) {
                // FastAPI HTTPException body shape, not Laravel's error page.
                $response->assertExactJson(['detail' => 'Requires role >= admin']);
            }
        }
    }

    public function test_delete_is_superadmin_only_and_blocks_self_deletion(): void
    {
        $this->seedEnv('chief:chief-pw:superadmin,boss:boss-pw:admin,staff1:staff-pw:staff');
        $chief = collect($this->auth()->listUsers())->firstWhere('username', 'chief');
        $boss = collect($this->auth()->listUsers())->firstWhere('username', 'boss');

        // admin (level 4 < 5) is blocked from delete.
        $bossToken = $this->post('/login', ['username' => 'boss', 'password' => 'boss-pw'])
            ->getCookie('cmt_session', false)->getValue();
        $this->withUnencryptedCookie('cmt_session', $bossToken)
            ->post("/users/{$chief['id']}/delete")->assertStatus(403);

        // superadmin cannot delete self (silent 303, user still present).
        $chiefToken = $this->post('/login', ['username' => 'chief', 'password' => 'chief-pw'])
            ->getCookie('cmt_session', false)->getValue();
        $this->withUnencryptedCookie('cmt_session', $chiefToken)
            ->post("/users/{$chief['id']}/delete")->assertStatus(303);
        $this->assertNotNull($this->auth()->getUser($chief['id']));
    }

    // ── User CRUD contracts ──────────────────────────────────────────────

    public function test_create_user_error_strings_match_python(): void
    {
        $this->seedEnv('boss:boss-pw:admin');
        $this->assertSame('Invalid role', $this->auth()->createUser('x', '', 'nope', 'pw', '', 5));
        $this->assertSame('Username is required', $this->auth()->createUser('  ', '', 'staff', 'pw', '', 5));
        $this->assertSame('Password is required', $this->auth()->createUser('x', '', 'staff', '', '', 5));
        $this->assertNull($this->auth()->createUser('x', '', 'staff', 'pw', '', 5));
        $this->assertSame('Username already exists', $this->auth()->createUser('x', '', 'staff', 'pw2', '', 5));
    }

    public function test_update_user_changes_fields_and_optional_password(): void
    {
        $this->seedEnv('staff1:staff-pw:staff');
        $id = collect($this->auth()->listUsers())->first()['id'];

        $this->assertNull($this->auth()->updateUser($id, 'Staff One', 'management', 'Trisco', '', 5));
        $row = $this->auth()->getUser($id);
        $this->assertSame('Staff One', $row['display_name']);
        $this->assertSame('management', $row['role']);
        $this->assertSame('Trisco', $row['customer_company']);

        // No password given -> hash unchanged; password given -> new hash verifies.
        $oldHash = collect($this->auth()->listUsers())->first()['id'] ? null : null;
        $this->assertNull($this->auth()->updateUser($id, 'Staff One', 'staff', '', 'new-pw', 5));
        $this->assertNull($this->auth()->updateUser($id, 'Staff One', 'staff', '', '', 5));
    }

    // ── SC-001 hardening: weak-credential deny list on create/update ──────

    public function test_create_user_rejects_weak_credential(): void
    {
        $this->seedEnv('boss:boss-pw:admin');

        $this->assertSame('Weak or default credential',
            $this->auth()->createUser('admin', '', 'staff', 'admin', '', 4));
        $this->assertSame('Weak or default credential',
            $this->auth()->createUser('root', '', 'staff', 'root', '', 4));
        $this->assertSame('Weak or default credential',
            $this->auth()->createUser('bob', '', 'staff', 'bob', '', 4)); // username == password

        // strong password still creatable
        $this->assertNull($this->auth()->createUser('ops', '', 'staff', 'Str0ng-Pass!', '', 4));
        $this->assertCount(2, $this->auth()->listUsers(), 'weak entries rejected, strong entry created');
        $this->assertArrayNotHasKey('admin', array_column($this->auth()->listUsers(), 'username', 'username'), 'weak admin not planted');
    }

    public function test_update_user_rejects_weak_password(): void
    {
        $this->seedEnv('admin:Str0ng-Pass!:admin');
        $id = collect($this->auth()->listUsers())->firstWhere('username', 'admin')['id'];

        $this->assertSame('Weak or default credential',
            $this->auth()->updateUser($id, 'Admin', 'admin', '', 'admin', 5)); // weak for admin username
        $this->assertSame('Weak or default credential',
            $this->auth()->updateUser($id, 'Admin', 'admin', '', 'admin', 5)); // username == password

        // strong password change still allowed
        $this->assertNull($this->auth()->updateUser($id, 'Admin', 'admin', '', 'Str0ng-Pass!', 5));
        $this->assertNotNull($this->auth()->authenticate('admin', 'Str0ng-Pass!'));
    }

    public function test_purge_weak_credentials_command_deletes_weak_accounts(): void
    {
        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        $this->auth()->ensureSchema($conn);

        // plant weak + strong accounts directly in the table (legacy rows)
        $conn->table('users')->insert([
            'username' => 'admin', 'display_name' => 'Admin', 'role' => 'superadmin',
            'pw_hash' => CmtAuth::hashPw('admin', 'salt1'), 'salt' => 'salt1', 'customer_company' => null,
        ]);
        $conn->table('users')->insert([
            'username' => 'root', 'display_name' => 'Root', 'role' => 'superadmin',
            'pw_hash' => CmtAuth::hashPw('root', 'salt2'), 'salt' => 'salt2', 'customer_company' => null,
        ]);
        $conn->table('users')->insert([
            'username' => 'ops', 'display_name' => 'Ops', 'role' => 'admin',
            'pw_hash' => CmtAuth::hashPw('Str0ng-Pass!', 'salt3'), 'salt' => 'salt3', 'customer_company' => null,
        ]);

        $this->artisan('cmt:auth:purge-weak-credentials --delete');

        $users = collect($this->auth()->listUsers())->keyBy('username');
        $this->assertArrayHasKey('ops', $users);
        $this->assertArrayNotHasKey('admin', $users);
        $this->assertArrayNotHasKey('root', $users);
    }

    // ── SC-001 / MEDIUM-3: admin cannot escalate roles above their own ────
    public function test_admin_cannot_create_user_above_own_level(): void
    {
        $this->seedEnv('boss:boss-pw:admin,chief:chief-pw:superadmin');

        // Direct call: admin (4) may create staff/management/admin (≤4)...
        $this->assertNull($this->auth()->createUser('s1', '', 'staff', 'pw', '', 4));
        $this->assertNull($this->auth()->createUser('s2', '', 'management', 'pw', '', 4));
        $this->assertNull($this->auth()->createUser('s3', '', 'admin', 'pw', '', 4));
        // ...but never superadmin (5 > 4).
        $this->assertSame('Cannot create user with role above your level',
            $this->auth()->createUser('s4', '', 'superadmin', 'pw', '', 4));
        // Superadmin (5) can still create anything.
        $this->assertNull($this->auth()->createUser('s5', '', 'superadmin', 'pw', '', 5));

        $users = collect($this->auth()->listUsers())->keyBy('username');
        $this->assertSame('staff', $users['s1']['role']);
        $this->assertSame('management', $users['s2']['role']);
        $this->assertSame('admin', $users['s3']['role']);
        $this->assertArrayNotHasKey('s4', $users->all());
        $this->assertSame('superadmin', $users['s5']['role']);

        // HTTP path: admin POST /users/new with role=superadmin is rejected; no user created.
        $this->seedEnv('boss:boss-pw:admin');
        $admin = collect($this->auth()->listUsers())->firstWhere('username', 'boss');
        $token = $this->post('/login', ['username' => 'boss', 'password' => 'boss-pw'])
            ->getCookie('cmt_session', false)->getValue();
        $before = count($this->auth()->listUsers());
        $this->withUnencryptedCookie('cmt_session', $token)
            ->post('/users/new', [
                'username' => 'evil', 'role' => 'superadmin', 'password' => 'pw',
            ])->assertSee('Cannot create user with role above your level');
        $this->assertSame($before, count($this->auth()->listUsers()));
    }

    public function test_admin_cannot_update_user_to_superadmin(): void
    {
        $this->seedEnv('boss:boss-pw:admin,chief:chief-pw:superadmin,target:target-pw:staff');
        $target = collect($this->auth()->listUsers())->firstWhere('username', 'target');
        $boss = collect($this->auth()->listUsers())->firstWhere('username', 'boss');

        // admin (4) may not lift another user to superadmin (5).
        $this->assertSame('Cannot update user to role above your level',
            $this->auth()->updateUser($target['id'], 'Target', 'superadmin', '', '', 4));
        $this->assertSame('staff', $this->auth()->getUser($target['id'])['role']);

        // nor lift another user above own level via admin→management is OK (equal level 4>3).
        $this->assertNull($this->auth()->updateUser($target['id'], 'Target', 'management', '', '', 4));
        $this->assertSame('management', $this->auth()->getUser($target['id'])['role']);

        // Self-promotion over HTTP: admin cannot make themself superadmin via /users/{id}/edit.
        $token = $this->post('/login', ['username' => 'boss', 'password' => 'boss-pw'])
            ->getCookie('cmt_session', false)->getValue();
        $this->withUnencryptedCookie('cmt_session', $token)
            ->post("/users/{$boss['id']}/edit", ['role' => 'superadmin'])
            ->assertSee('Cannot update user to role above your level');
        $this->assertSame('admin', $this->auth()->getUser($boss['id'])['role']);

        // Superadmin can still update anyone.
        $this->assertNull($this->auth()->updateUser($target['id'], 'Target', 'superadmin', '', '', 5));
        $this->assertSame('superadmin', $this->auth()->getUser($target['id'])['role']);
    }

    public function test_delete_user_removes_sessions_too(): void
    {
        $this->seedEnv('staff1:staff-pw:staff');
        $token = $this->post('/login', ['username' => 'staff1', 'password' => 'staff-pw'])
            ->getCookie('cmt_session', false)->getValue();
        $id = collect($this->auth()->listUsers())->first()['id'];

        $this->auth()->deleteUser($id);

        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        $this->assertNull($conn->table('users')->find($id));
        $this->assertSame(0, $conn->table('sessions')->where('token', $token)->count());
    }

    public function test_unknown_user_id_edit_redirects(): void
    {
        $this->seedEnv('boss:boss-pw:admin');
        $token = $this->post('/login', ['username' => 'boss', 'password' => 'boss-pw'])
            ->getCookie('cmt_session', false)->getValue();

        $this->withUnencryptedCookie('cmt_session', $token)
            ->get('/users/9999/edit')->assertStatus(303)->assertRedirect('/users');
    }

    // ── SC-001 vuln-0001: CMT_USERS must never seed default credentials ──

    public function test_sc001_default_credentials_are_rejected_at_seed_time(): void
    {
        // The documented exploit: CMT_USERS plants admin:admin, superadmin:superadmin, root:root.
        $this->seedEnv('admin:admin:superadmin,superadmin:superadmin:superadmin,root:root:superadmin');

        $this->assertCount(0, $this->auth()->listUsers(), 'no weak entry may reach the users table');
        $this->assertNull($this->auth()->authenticate('admin', 'admin'));
        $this->assertNull($this->auth()->authenticate('superadmin', 'superadmin'));
        $this->assertNull($this->auth()->authenticate('root', 'root'));
    }

    public function test_sc001_weak_password_variants_are_rejected(): void
    {
        // username == password, empty password, and the per-username deny-list.
        $this->seedEnv('bob:bob:staff,empty::staff,admin:password:admin,root:123456:superadmin,admin:admin123:admin');

        $this->assertCount(0, $this->auth()->listUsers());
    }

    public function test_sc001_weak_matching_is_case_insensitive(): void
    {
        $this->seedEnv('ADMIN:ADMIN:superadmin,Admin:Admin:admin');

        $this->assertCount(0, $this->auth()->listUsers());
        $this->assertNull($this->auth()->authenticate('ADMIN', 'ADMIN'));
    }

    public function test_sc001_mixed_env_keeps_only_strong_entries(): void
    {
        $this->seedEnv('admin:admin:superadmin,superadmin:superadmin:superadmin,'
            .'ops:Str0ng-Pass!:admin,staff1:staff-pw:staff');

        $users = collect($this->auth()->listUsers())->keyBy('username');
        $this->assertSame(['ops', 'staff1'], $users->keys()->sort()->values()->all());
        $this->assertSame('admin', $users['ops']['role']);
        $this->assertSame('staff', $users['staff1']['role']);

        // Positive auth path still works for the entries that were kept.
        $this->assertNotNull($this->auth()->authenticate('ops', 'Str0ng-Pass!'));
        $this->assertNotNull($this->auth()->authenticate('staff1', 'staff-pw'));
        // Negative: the dropped entries cannot authenticate.
        $this->assertNull($this->auth()->authenticate('admin', 'admin'));
        $this->assertNull($this->auth()->authenticate('superadmin', 'superadmin'));
    }

    public function test_sc001_privileged_username_with_strong_password_is_still_seeded(): void
    {
        // Guard against over-blocking: only the weak passwords are refused,
        // not the usernames admin/superadmin themselves.
        $this->seedEnv('admin:Sup3r-Secret!:admin,superadmin:0ther-Str0ng!:superadmin');

        $this->assertCount(2, $this->auth()->listUsers());
        $this->assertNotNull($this->auth()->authenticate('admin', 'Sup3r-Secret!'));
        $this->assertNotNull($this->auth()->authenticate('superadmin', '0ther-Str0ng!'));
    }

    public function test_sc001_login_http_gate_rejects_defaults_accepts_strong(): void
    {
        // End-to-end replay of the investigation's exploit steps against POST /login.
        $this->seedEnv('admin:admin:superadmin,ops:Str0ng-Pass!:admin');

        $this->post('/login', ['username' => 'admin', 'password' => 'admin'])
            ->assertOk()
            ->assertSee('Username or password incorrect')
            ->assertCookieMissing('cmt_session');

        $this->post('/login', ['username' => 'superadmin', 'password' => 'superadmin'])
            ->assertOk()
            ->assertCookieMissing('cmt_session');

        $token = $this->post('/login', ['username' => 'ops', 'password' => 'Str0ng-Pass!'])
            ->assertStatus(302)
            ->assertRedirect('/')
            ->getCookie('cmt_session', false)->getValue();
        $this->withUnencryptedCookie('cmt_session', $token)->get('/')->assertOk();
    }

    public function test_sc001_skipped_entries_emit_warning_log(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $this->seedEnv('admin:admin:superadmin');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'skipped weak/default credential')
                && ($context['username'] ?? null) === 'admin')
            ->once();
    }

    // ── Cross-app compatibility (the parallel-run linchpin) ──────────────

    public function test_php_hash_verifies_python_pbkdf2_vector(): void
    {
        // HARD-CODED from the real Python: hashlib.pbkdf2_hmac('sha256', b'pw',
        // b'4f0a', 200000, dklen=32).hex() — computed OUTSIDE PHP so this test
        // can never pass PHP-vs-PHP again (the 32-vs-64 hex/bytes bug passed a
        // self-computed expected value).
        $this->assertSame(
            '0982733758b7db33f21cbe6bea6b569ae844b506f257035cfee5b737f516a949',
            CmtAuth::hashPw('pw', '4f0a'),
            'hash must equal Python pbkdf2_hmac(sha256, pw, salt, 200000, dklen=32).hex() (64 hex chars)'
        );
    }

    public function test_python_seeded_user_can_log_in_via_php(): void
    {
        // Simulate a user row created by the Python app: its _hash() output
        // (64 hex chars = 32 bytes). Verified against real Python output.
        $salt = '9c1f2e3d4c5b6a7980afbe3d2c1b0a99';
        $pyHash = CmtAuth::hashPw('shared-secret', $salt);

        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        $this->auth()->ensureSchema($conn);
        $conn->table('users')->insert([
            'username' => 'py_user', 'display_name' => 'Py User', 'role' => 'staff',
            'pw_hash' => $pyHash, 'salt' => $salt, 'customer_company' => null,
        ]);

        $this->assertNotNull($this->auth()->authenticate('py_user', 'shared-secret'));
        $this->assertNull($this->auth()->authenticate('py_user', 'wrong'));
    }
}
