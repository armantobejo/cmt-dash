<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CmtAuth;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SC-001 remediation: /api/summary must be scoped by customerCompany,
 * mirroring the web dashboard (DashboardController passes customerCompany,
 * the API called getSummary() unfiltered).
 *
 * Coverage:
 *   - customer user sees only their company's totals (shipments / imports /
 *     exports / invoices / recent_shipments).
 *   - non-customer user gets the same unscoped totals as the web dashboard.
 *   - unauthenticated callers get 401 (existing gate preserved).
 *
 * voyagers is intentionally NOT company-scoped here — it counts the vessel
 * reference table, which matches web dashboard behavior exactly (parity,
 * not a leak): the web dashboard renders the identical getSummary() call.
 */
class ApiSummaryScopeTest extends TestCase
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
        $this->createBusinessSchema($dir.'/CMT.db');
    }

    /** Minimal company-DB schema used by CmtQueries::getSummary. */
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

    /** Two companies of shipments/invoices; voyages table is company-agnostic. */
    private function seedBusinessData(): void
    {
        $conn = $this->app->make(CompanyConnectionFactory::class)
            ->connection('CMT');

        $conn->table('CONSIGNEE')->insert([
            ['KD_CONSIG' => 'C1', 'NAME' => 'Trisco'],
            ['KD_CONSIG' => 'C2', 'NAME' => 'Otherco'],
        ]);
        $conn->table('SHIPPER')->insert([
            ['KD_SHIPPER' => 'S1', 'NAME' => 'Trisco'],
            ['KD_SHIPPER' => 'S2', 'NAME' => 'Otherco'],
        ]);
        $shipments = [
            ['UNIK' => 'U1', 'TYPETRAN' => 'I', 'DATE' => '2026-01-01', 'REFSHITMEN' => 'INV-R1', 'KD_CONSIG' => 'C1', 'KD_SHIPPER' => 'S1'],
            ['UNIK' => 'U2', 'TYPETRAN' => 'I', 'DATE' => '2026-01-02', 'REFSHITMEN' => 'INV-R2', 'KD_CONSIG' => 'C2', 'KD_SHIPPER' => 'S2'],
            ['UNIK' => 'U3', 'TYPETRAN' => 'E', 'DATE' => '2026-01-03', 'REFSHITMEN' => 'INV-R3', 'KD_CONSIG' => 'C1', 'KD_SHIPPER' => 'S1'],
            ['UNIK' => 'U4', 'TYPETRAN' => 'E', 'DATE' => '2026-01-04', 'REFSHITMEN' => 'INV-R4', 'KD_CONSIG' => 'C2', 'KD_SHIPPER' => 'S2'],
        ];
        foreach ($shipments as $row) {
            $row = array_merge($row, array_fill_keys([
                'BLNO3','KD_EXVESSE','EXVOYAGE','AGENT','CODE','CONTAINERN','PORTOFLOAD','PORTOFDISC',
                'SHIPPINGMA','DESCRIPTIO','CONSIGNEE','SHIPPER','SAIDTOCONT','BL_NO','MBL_NO','ETD','ETA','ATA',
            ], ''));
            $conn->table('UnifiedShipment')->insert($row);
        }
        foreach ($shipments as $i => $row) {
            $conn->table('INVOICE')->insert([
                'K_INVOICE' => $i + 1,
                'NOINVOICE' => 'INV-'.sprintf('%03d', $i + 1),
                'REFSHITMEN' => $row['REFSHITMEN'],
                'EI' => 'E',
                'DATEOFISSU' => '2026-01-'.($i + 1),
                'SHIPPER' => $row['KD_CONSIG'] === 'C1' ? 'Trisco' : 'Otherco',
                'EXRATE' => 1,
                'GRAND_USD' => 100,
                'GRAND_RP' => 100,
                'BYR_USD' => 100,
                'BYR_RP' => 100,
                'DIRPKAN' => 100,
                'DIUSDKAN' => 100,
                'LUNAS' => 1,
            ]);
        }
        $conn->table('VESSEL')->insert([
            ['KD_VESSEL' => 'V1', 'NM_VESSEL' => 'Trisco Ship'],
            ['KD_VESSEL' => 'V2', 'NM_VESSEL' => 'Other Ship'],
        ]);
    }

    /** POST /login, return raw cmt_session token value. */
    private function loginToken(string $user, string $pw): string
    {
        return $this->post('/login', ['username' => $user, 'password' => $pw])
            ->assertStatus(302)
            ->getCookie('cmt_session', false)
            ->getValue();
    }

    private function getSummary(?string $token): ?array
    {
        if ($token !== null) {
            // Api middleware group carries no EncryptCookies middleware, so the
            // raw token must be sent as-is (withUnencryptedCookie).
            $this->withUnencryptedCookie('cmt_session', $token);
        }
        return $this->get('/api/summary')->json();
    }

    public function test_customer_sees_only_company_totals(): void
    {
        $this->seedEnv('boss:boss-pw:admin,cust:cust-pw:customer:Trisco');
        $this->seedBusinessData();

        $customerToken = $this->loginToken('cust', 'cust-pw');

        $summary = $this->getSummary($customerToken);

        $this->assertSame(2, $summary['shipments'], 'customer shipments scoped to Trisco');
        $this->assertSame(1, $summary['imports']);
        $this->assertSame(1, $summary['exports']);
        $this->assertSame(2, $summary['invoices']);
        $this->assertSame(2, $summary['voyages'], 'voyages is reference data, identical to web dashboard');
        $this->assertCount(2, $summary['recent_shipments']);
    }

    public function test_non_customer_matches_web_dashboard_unscoped_totals(): void
    {
        $this->seedEnv('boss:boss-pw:admin,cust:cust-pw:customer:Trisco');
        $this->seedBusinessData();

        $bossToken = $this->loginToken('boss', 'boss-pw');

        $summary = $this->getSummary($bossToken);

        // Non-customer (admin, customerCompany == '') gets the full totals —
        // the same numbers the web dashboard renders for a non-scoped user.
        $this->assertSame(4, $summary['shipments']);
        $this->assertSame(2, $summary['imports']);
        $this->assertSame(2, $summary['exports']);
        $this->assertSame(4, $summary['invoices']);
        $this->assertSame(2, $summary['voyages']);
        $this->assertCount(4, $summary['recent_shipments']);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->seedEnv('cust:cust-pw:customer:Trisco');
        $this->seedBusinessData();

        $this->get('/api/summary')->assertStatus(401);
    }
}
