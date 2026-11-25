<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CmtAuth;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

/** P5 gate: GET /ledger, /sales, /api/health, /api/summary, /api/sync-status return
 *  200 (or the correct auth redirect) with parity-comparable payloads; /nonexistent
 *  renders the custom 404 page preserving sidebar chrome. */
class P5RouteTest extends TestCase
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

        $factory = new CompanyConnectionFactory(
            resolver: $this->app->make(ConnectionResolverInterface::class),
            dataDir: $dir,
        );
        $this->app->instance(CompanyConnectionFactory::class, $factory);
        $this->app->instance(CmtAuth::class, new CmtAuth($factory));
        $this->app->make(CompanyConnectionFactory::class)->connection('CMT');

        touch($dir.'/CMT.db');
        $this->artisan('migrate');

        $pdo = new \PDO('sqlite:'.$dir.'/CMT.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS UnifiedShipment (UNIK TEXT PRIMARY KEY, TYPETRAN TEXT, DATE TEXT, REFSHITMEN TEXT UNIQUE, KD_CONSIG TEXT, KD_SHIPPER TEXT, BLNO3 TEXT, SALES TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS CONSIGNEE (KD_CONSIG TEXT PRIMARY KEY, NAME TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS SHIPPER (KD_SHIPPER TEXT PRIMARY KEY, NAME TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS INVOICE (rowid_ INTEGER PRIMARY KEY AUTOINCREMENT, K_INVOICE INTEGER, NOINVOICE TEXT, REFSHITMEN TEXT, EI TEXT, DATEOFISSU TEXT, SHIPPER TEXT, EXRATE REAL, GRAND_USD REAL, GRAND_RP REAL, BYR_USD REAL, BYR_RP REAL, DIRPKAN REAL, DIUSDKAN REAL, LUNAS INTEGER, SALES TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS OPRCOST (UOPRCOST TEXT PRIMARY KEY, REFSHITMEN TEXT, GRAND_USD REAL, GRAND_RP REAL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS VESSEL (KD_VESSEL TEXT PRIMARY KEY, NM_VESSEL TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS DRCRFULL (REFSHITMEN TEXT PRIMARY KEY, TOTAL REAL, TOTAL_IDR REAL, BAYAR TEXT, BAYAR_IDR REAL, DIRPKAN REAL, DIUSDKAN REAL)');
        $pdo->exec("INSERT INTO UnifiedShipment VALUES ('U1','I','2026-01-01','R1','C1','S1','BL1','SM1'),('U2','E','2026-01-02','R2','C1','S2','BL2','SM2')");
        $pdo->exec("INSERT INTO CONSIGNEE VALUES ('C1','Trisco')");
        $pdo->exec("INSERT INTO SHIPPER VALUES ('S1','Trisco'),('S2','Otherco')");
        $pdo->exec("INSERT INTO INVOICE (K_INVOICE,NOINVOICE,REFSHITMEN,EI,DATEOFISSU,SHIPPER,EXRATE,GRAND_USD,GRAND_RP,BYR_USD,BYR_RP,DIRPKAN,DIUSDKAN,LUNAS,SALES) VALUES (1,'INV-001','R1','E','2026-01-01','Trisco',1,100,100,0,0,100,100,1,'S1')");
        $pdo->exec("INSERT INTO OPRCOST VALUES ('O1','R1',10,10)");
        $pdo->exec("INSERT INTO VESSEL VALUES ('V1','Trisco Ship')");
        $pdo->exec("INSERT INTO DRCRFULL VALUES ('R1',100,100,'Y',100,100,100)");
    }

    private function signIn(string $username = 'mgmt', string $password = 'mgmt-pw', string $role = 'management'): string
    {
        config(['cmt.users_env' => "{$username}:{$password}:{$role}"]);
        $this->app->make(CmtAuth::class)->seedFromEnv();

        return $this->post('/login', ['username' => $username, 'password' => $password])
            ->assertStatus(302)
            ->getCookie('cmt_session', false)
            ->getValue();
    }

    private function authed(): static
    {
        return $this->withUnencryptedCookie('cmt_session', $this->signIn());
    }

    public function test_api_health_is_public_ok(): void
    {
        $this->get('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_api_summary_requires_login_then_returns_shape(): void
    {
        $this->get('/api/summary')->assertStatus(401);

        $this->authed()
            ->get('/api/summary')
            ->assertOk()
            ->assertJsonStructure([
                'shipments', 'imports', 'exports', 'invoices', 'voyages', 'recent_shipments',
            ]);
    }

    public function test_api_sync_status_requires_login_then_returns_last_sync_stale(): void
    {
        $this->get('/api/sync-status')->assertStatus(401);

        $this->authed()
            ->get('/api/sync-status')
            ->assertOk()
            ->assertJsonStructure(['last_sync', 'stale', 'age_seconds']);
    }

    public function test_ledger_routes_require_management_role(): void
    {
        $this->get('/ledger')->assertStatus(303)->assertRedirect('/login');
        $this->get('/ledger/detail?k=1101')->assertStatus(303)->assertRedirect('/login');

        $token = $this->signIn('customer', 'customer-pw', 'customer');
        $this->withUnencryptedCookie('cmt_session', $token)
            ->get('/ledger')
            ->assertStatus(403)
            ->assertJson(['detail' => 'Requires role >= management']);
    }

    /** GET /sales renders for staff with every period value. */
    public function test_sales_requires_login_and_all_periods(): void
    {
        $this->get('/sales')->assertStatus(303)->assertRedirect('/login');

        $this->seedSalesSchema();
        foreach (['all', 'month', 'year', 'round'] as $period) {
            $this->authed()
                ->get("/sales?p={$period}")
                ->assertOk()
                ->assertViewIs('sales');
        }
    }

    /** GET /sales without query string returns 200 for every authenticated role. */
    public function test_sales_no_query_string_returns_200(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seedSalesSchema();
        $roles = ['staff', 'customer', 'management', 'admin', 'superadmin'];
        foreach ($roles as $role) {
            $token = $this->signIn('sales', 'sales-pw', $role);
            $this->withUnencryptedCookie('cmt_session', $token)
                ->get('/sales')
                ->assertOk()
                ->assertViewIs('sales');
        }
    }

    /** GET /ledger without params returns 200 for management+ roles. */
    public function test_ledger_no_query_string_returns_200_for_management(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        // ACCOUNT table lives on the legacy DB; migrate doesn't create it
        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS ACCOUNT (KD_ACCOUNT TEXT PRIMARY KEY, NM_ACCOUNT TEXT, SLD_NORMAL TEXT, TYPE TEXT, "GROUP" TEXT, ACTIVE TEXT)');
        $pdo->exec("INSERT OR IGNORE INTO ACCOUNT VALUES ('1101','Test Account','100','D','G','Y')");
        $pdo->exec("INSERT OR IGNORE INTO ACCOUNT VALUES ('1202','Another Acct','200','D','G','Y')");

        $roles = ['management', 'admin', 'superadmin'];
        foreach ($roles as $role) {
            $token = $this->signIn('ledger', 'ledger-pw', $role);
            $this->withUnencryptedCookie('cmt_session', $token)
                ->get('/ledger')
                ->assertOk()
                ->assertViewIs('ledger');
        }
    }

    /** /ledger with no params is 403 for staff/customer (not 500). */
    public function test_ledger_no_query_string_403_for_unauthorized(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $roles = ['staff', 'customer'];
        foreach ($roles as $role) {
            $token = $this->signIn('ledger', 'ledger-pw', $role);
            $this->withUnencryptedCookie('cmt_session', $token)
                ->get('/ledger')
                ->assertStatus(403)
                ->assertJson(['detail' => 'Requires role >= management']);
        }
    }

    public function test_nonexistent_renders_custom_404_with_chrome(): void
    {
        $this->get('/nonexistent')
            ->assertStatus(404)
            ->assertViewIs('errors.404');
    }

    private function seedSalesSchema(): void
        {
            $path = $this->dataDir.'/CMT.db';
            $pdo = new \PDO('sqlite:'.$path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $pdo->exec('CREATE TABLE IF NOT EXISTS UnifiedShipment (UNIK TEXT PRIMARY KEY, TYPETRAN TEXT, DATE TEXT, REFSHITMEN TEXT UNIQUE, KD_CONSIG TEXT, KD_SHIPPER TEXT, BLNO3 TEXT, SALES TEXT)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS CONSIGNEE (KD_CONSIG TEXT PRIMARY KEY, NAME TEXT)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS SHIPPER (KD_SHIPPER TEXT PRIMARY KEY, NAME TEXT)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS INVOICE (rowid_ INTEGER PRIMARY KEY AUTOINCREMENT, K_INVOICE INTEGER, NOINVOICE TEXT, REFSHITMEN TEXT, EI TEXT, DATEOFISSU TEXT, SHIPPER TEXT, EXRATE REAL, GRAND_USD REAL, GRAND_RP REAL, BYR_USD REAL, BYR_RP REAL, DIRPKAN REAL, DIUSDKAN REAL, LUNAS INTEGER, SALES TEXT)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS OPRCOST (UOPRCOST TEXT PRIMARY KEY, REFSHITMEN TEXT, GRAND_USD REAL, GRAND_RP REAL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS VESSEL (KD_VESSEL TEXT PRIMARY KEY, NM_VESSEL TEXT)');

            $pdo->exec("INSERT INTO UnifiedShipment VALUES ('U1','I','2026-01-01','R1','C1','S1','BL1','SM1'),('U2','E','2026-01-02','R2','C1','S2','BL2','SM2') ON CONFLICT DO NOTHING");
            $pdo->exec("INSERT INTO CONSIGNEE VALUES ('C1','Trisco') ON CONFLICT DO NOTHING");
            $pdo->exec("INSERT INTO SHIPPER VALUES ('S1','Trisco'),('S2','Otherco') ON CONFLICT DO NOTHING");
            $pdo->exec("INSERT INTO INVOICE (K_INVOICE,NOINVOICE,REFSHITMEN,EI,DATEOFISSU,SHIPPER,EXRATE,GRAND_USD,GRAND_RP,BYR_USD,BYR_RP,DIRPKAN,DIUSDKAN,LUNAS,SALES) VALUES (1,'INV-001','R1','E','2026-01-01','Trisco',1,100,100,0,0,100,100,1,'S1') ON CONFLICT DO NOTHING");
            $pdo->exec("INSERT INTO OPRCOST VALUES ('O1','R1',10,10) ON CONFLICT DO NOTHING");
            $pdo->exec("INSERT INTO VESSEL VALUES ('V1','Trisco Ship') ON CONFLICT DO NOTHING");
        }
}
