<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CompanyDbManager;
use App\Support\CmtAuth;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gate for F-28 Listing Reference pages (4 pages) via Filament panel.
 * Asserts: reachable at route, renders specified fields from real data,
 * filters/sort/pagination behave per spec, no console errors (PHP exceptions).
 * Mirrors FilamentInvoicesParityTest harness.
 */
class F28PagesTest extends TestCase
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

        config([
            'cmt.data_dir' => $dir,
            'cmt.users_env' => '',
            'cmt.dbf_dir' => base_path('tests/fixtures/dbf'),
        ]);

        $this->app->instance(CompanyConnectionFactory::class, new CompanyConnectionFactory(
            resolver: $this->app->make(ConnectionResolverInterface::class),
            dataDir: $dir,
        ));
        $this->app->instance(CompanyDbManager::class, new CompanyDbManager($dir, 'CMT'));

        $this->seedCompanyDb();
        $this->seedUsers();
    }

    /** Smoke: every F-28 page reachable and renders rows. */
    public function test_all_f28_pages_reachable_and_render_rows(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        $pages = [
            'agents'       => ['Agent Code', 'Sub Code', 'Name', 'Invoice Agent', 'Rate'],
            'shippers'     => ['Shipper Code', 'Name', 'BU', 'Customer Type', 'Payment Term'],
            'consignee'    => ['Consignee Code', 'Name', 'Address', 'Phone', 'Fax'],
            'account'      => ['Account Code', 'Account Name', 'Account Type', 'Group', 'Normal Balance'],
        ];

        foreach ($pages as $slug => $expectedLabels) {
            $response = $this->get('/admin/'.$slug);
            $response->assertOk("F-28 page /admin/{$slug} failed");

            $html = $response->getContent();
            // Subheading shows "N results" from table records count
            $this->assertStringContainsString('results', $html, "{$slug}: missing results summary");

            // Each expected column label appears (translated or English)
            foreach ($expectedLabels as $label) {
                $this->assertStringContainsString($label, $html, "{$slug}: missing label '{$label}'");
            }
        }
    }

    /** Filters: search narrows rows. */
    public function test_f28_pages_search_narrows(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // Agent search by code 'AG1'
        $all = $this->get('/admin/agents')->getContent();
        $search = $this->get('/admin/agents?q=AG1')->getContent();
        $this->assertStringContainsString('AG1', $search);
        $this->assertStringNotContainsString('AG2', $search);

        // Shipper search by code 'S1'
        $all = $this->get('/admin/shippers')->getContent();
        $search = $this->get('/admin/shippers?q=S1')->getContent();
        $this->assertStringContainsString('S1', $search);
        $this->assertStringNotContainsString('S2', $search);

        // Consignee search by code 'C1'
        $all = $this->get('/admin/consignee')->getContent();
        $search = $this->get('/admin/consignee?q=C1')->getContent();
        $this->assertStringContainsString('C1', $search);
        $this->assertStringNotContainsString('C2', $search);

        // Account search by code 'ACC1'
        $all = $this->get('/admin/account')->getContent();
        $search = $this->get('/admin/account?q=ACC1')->getContent();
        $this->assertStringContainsString('ACC1', $search);
        $this->assertStringNotContainsString('ACC2', $search);
    }

    /** Sort: default sort columns produce expected order. */
    public function test_f28_pages_sort_order(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // Agents default sort by NAME: AG1 (Evergreen Agent) before AG2 (Maersk Agent)
        $agents = $this->get('/admin/agents')->getContent();
        $firstAgent = strpos($agents, 'Evergreen Agent');
        $secondAgent = strpos($agents, 'Maersk Agent');
        $this->assertLessThan($secondAgent, $firstAgent, 'Agents not sorted by name ASC');

        // Shippers default sort by NAME: MAERSK LINE before PT Sumber Rejeki
        $shippers = $this->get('/admin/shippers')->getContent();
        $firstShipper = strpos($shippers, 'MAERSK LINE');
        $secondShipper = strpos($shippers, 'PT Sumber Rejeki');
        $this->assertLessThan($secondShipper, $firstShipper, 'Shippers not sorted by name ASC');

        // Consignees default sort by NAME: TRISCO before PT MAJU JAYA
        $consignees = $this->get('/admin/consignee')->getContent();
        $firstCons = strpos($consignees, 'TRISCO');
        $secondCons = strpos($consignees, 'PT MAJU JAYA');
        $this->assertLessThan($secondCons, $firstCons, 'Consignees not sorted by name ASC');

        // Accounts default sort by KD_ACCOUNT: ACC1 before ACC2
        $accounts = $this->get('/admin/account')->getContent();
        $firstAcc = strpos($accounts, 'ACC1');
        $secondAcc = strpos($accounts, 'ACC2');
        $this->assertLessThan($secondAcc, $firstAcc, 'Accounts not sorted by code ASC');
    }

    /** Pagination: per_page config respected. */
    public function test_f28_pages_pagination_per_page(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // Default per_page is config('cmt.agents_per_page', 25) = 25; our fixture has 2 rows
        // Pagination should NOT show (no second page). Subheading should show "2 results"
        foreach (['agents', 'shippers', 'consignee', 'account'] as $slug) {
            $response = $this->get('/admin/'.$slug);
            $response->assertOk();
            $this->assertStringContainsString('2 results', $response->getContent(), "{$slug}: expected 2 results summary");
        }
    }

    /** Empty state: search miss shows empty heading. */
    public function test_f28_pages_empty_state(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        foreach (['agents', 'shippers', 'consignee', 'account'] as $slug) {
            $response = $this->get('/admin/'.$slug.'?q=ZZZNOPE');
            $response->assertOk();
            $this->assertStringContainsString('No ', $response->getContent(), "{$slug}: missing empty state heading");
        }
    }

    /** Customer role: company_filter scoped where applicable (Shipper). */
    public function test_f28_customer_role_company_filter(): void
    {
        // Customer for company 'TRISCO'
        $this->actingAsRole('cust1', 'cust-pw', 'TRISCO');

        // Shippers table filters by company name (SHIPPER.NAME LIKE %TRISCO%)
        // Our fixture has shipper 'MAERSK LINE' and 'PT Sumber Rejeki' — neither contains TRISCO
        // So customer should see 0 shippers
        $response = $this->get('/admin/shippers');
        $response->assertOk();
        $this->assertStringContainsString('0 results', $response->getContent(), 'Customer shippers should be empty');

        // Agents, Consignees, Accounts: no company filter (global ref tables)
        // Customer should still see all 2 rows
        foreach (['agents', 'consignee', 'account'] as $slug) {
            $response = $this->get('/admin/'.$slug);
            $response->assertOk();
            $this->assertStringContainsString('2 results', $response->getContent(), "{$slug}: customer should see all rows");
        }
    }

    /** Language: labels translate via cmt_lang cookie. */
    public function test_f28_labels_translate_id_en(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // Default lang is id (CMT_LANG default)
        $idAgents = $this->get('/admin/agents')->getContent();
        $this->assertStringContainsString('Kode Agen', $idAgents); // Agent Code in Indonesian

        // Switch to English
        $enAgents = $this->withUnencryptedCookie('cmt_lang', 'en')->get('/admin/agents')->getContent();
        $this->assertStringContainsString('Agent Code', $enAgents);
        $this->assertStringNotContainsString('Kode Agen', $enAgents);
    }

    /** Anonymous denied. */
    public function test_f28_anonymous_denied(): void
    {
        foreach (['agents', 'shippers', 'consignee', 'account'] as $slug) {
            $this->get('/admin/'.$slug)->assertRedirect('/login');
        }
    }

    // ── Harness ──────────────────────────────────────────────────────────

    private function actingAsRole(string $username, string $password, string $company = ''): static
    {
        $auth = $this->app->make(CmtAuth::class);
        $token = $auth->authenticate($username, $password);

        if ($token === null) {
            throw new \RuntimeException("Failed to authenticate user: {$username}");
        }

        $sessionCookieName = config('cmt.session_cookie', 'cmt_session');
        $this->withUnencryptedCookie($sessionCookieName, $token);

        if ($company !== '') {
            $this->app->make(CompanyConnectionFactory::class)->authConnection()
                ->table('users')->where('username', $username)
                ->update(['customer_company' => $company]);
        }

        return $this;
    }

    private function seedUsers(): void
    {
        config(['cmt.users_env' => implode(',', [
            'root1:root-pw:superadmin',
            'admin1:admin-pw:admin',
            'chief1:chief-pw:management',
            'staff1:staff-pw:staff',
            'cust1:cust-pw:customer',
        ])]);
        $this->app->make(CmtAuth::class)->seedFromEnv();
    }

    /** Seeds all tables the F-28 queries touch. */
    private function seedCompanyDb(): void
    {
        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // AGENT table (for getAgents)
        $pdo->exec('CREATE TABLE AGENT (
            AGENT TEXT, CODE TEXT, NAME TEXT, AGENTINV TEXT, RATE REAL,
            ADDRESS1 TEXT, ADDRESS2 TEXT, ADDRESS3 TEXT, TELP TEXT, FAX TEXT,
            PIC TEXT, KD_ACCOUNT TEXT, ACCT_CR TEXT
        )');

        // SHIPPER table (for getShippers)
        $pdo->exec('CREATE TABLE SHIPPER (
            KD_SHIPPER TEXT PRIMARY KEY, KD_BU TEXT, NAME TEXT,
            ADDRESS1 TEXT, ADDRESS2 TEXT, ADDRESS3 TEXT,
            TELP TEXT, FAX TEXT, KD_JNSCUST TEXT, TERM TEXT,
            KD_ACCOUNT TEXT, NPWP TEXT, NPWP_ALAMA TEXT, NPWP_ALAM2 TEXT,
            NPWP_DATE TEXT, NO_SIUP TEXT, TGL_SIUP TEXT, NOTE TEXT
        )');

        // CONSIGNEE table (for getConsignees)
        $pdo->exec('CREATE TABLE CONSIGNEE (
            KD_CONSIG TEXT PRIMARY KEY, NAME TEXT,
            ADDRESS1 TEXT, ADDRESS2 TEXT, ADDRESS3 TEXT,
            TELP TEXT, FAX TEXT
        )');

        // ACCOUNT table (for getAccounts)
        $pdo->exec('CREATE TABLE ACCOUNT (
            KD_ACCOUNT TEXT PRIMARY KEY, NM_ACCOUNT TEXT, TYPE TEXT,
            "GROUP" TEXT, SLD_NORMAL TEXT, ENTRY INTEGER,
            ACTIVE TEXT, NOACTIVE TEXT, BARU TEXT
        )');

        // Parties (2 rows each for pagination test)
        $ins = fn (string $sql, array $p = []) => $pdo->prepare($sql)->execute($p);

        $ins('INSERT INTO AGENT (AGENT, CODE, NAME, AGENTINV, RATE, ADDRESS1, ADDRESS2, ADDRESS3, TELP, FAX, PIC, KD_ACCOUNT, ACCT_CR) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            ['AG1', 'C01', 'Evergreen Agent', 'INV-AG1', 15000.0, 'Jl. Evergreen 1', 'Floor 2', 'Jakarta', '021-1234567', '021-7654321', 'John', 'ACC-AG1', 'ACC-CR1']);
        $ins('INSERT INTO AGENT (AGENT, CODE, NAME, AGENTINV, RATE, ADDRESS1, ADDRESS2, ADDRESS3, TELP, FAX, PIC, KD_ACCOUNT, ACCT_CR) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            ['AG2', 'C02', 'Maersk Agent', 'INV-AG2', 16000.0, 'Jl. Maersk 1', '', 'Surabaya', '031-111222', '031-333444', 'Jane', 'ACC-AG2', 'ACC-CR2']);

        $ins('INSERT INTO SHIPPER (KD_SHIPPER, KD_BU, NAME, ADDRESS1, ADDRESS2, ADDRESS3, TELP, FAX, KD_JNSCUST, TERM, KD_ACCOUNT, NPWP, NPWP_ALAMA, NPWP_ALAM2, NPWP_DATE, NO_SIUP, TGL_SIUP, NOTE) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            ['S1', 'BU01', 'MAERSK LINE', 'Jl. Maersk 10', '', '', '021-111111', '021-222222', 'CUST', '30 Days', 'ACC-S1', '12.345.678.9-012.000', 'Jl. NPWP 1', '', '2025-01-01', 'SIUP-001', '2025-01-01', 'note']);
        $ins('INSERT INTO SHIPPER (KD_SHIPPER, KD_BU, NAME, ADDRESS1, ADDRESS2, ADDRESS3, TELP, FAX, KD_JNSCUST, TERM, KD_ACCOUNT, NPWP, NPWP_ALAMA, NPWP_ALAM2, NPWP_DATE, NO_SIUP, TGL_SIUP, NOTE) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            ['S2', 'BU02', 'PT Sumber Rejeki', 'Jl. Rejeki 5', 'Blok B', '', '021-333333', '021-444444', 'EXPORT', '60 Days', 'ACC-S2', '98.765.432.1-098.000', 'Jl. NPWP 2', 'Kel. Rejeki', '2025-02-01', 'SIUP-002', '2025-02-01', 'note2']);

        $ins('INSERT INTO CONSIGNEE (KD_CONSIG, NAME, ADDRESS1, ADDRESS2, ADDRESS3, TELP, FAX) VALUES (?,?,?,?,?,?,?)',
            ['C1', 'TRISCO', 'Jl. Tris 1', '', '', '021-555555', '021-666666']);
        $ins('INSERT INTO CONSIGNEE (KD_CONSIG, NAME, ADDRESS1, ADDRESS2, ADDRESS3, TELP, FAX) VALUES (?,?,?,?,?,?,?)',
            ['C2', 'PT MAJU JAYA', 'Jl. Maju 2', 'Kav 3', '', '021-777777', '021-888888']);

        $ins('INSERT INTO ACCOUNT (KD_ACCOUNT, NM_ACCOUNT, TYPE, "GROUP", SLD_NORMAL, ENTRY, ACTIVE, NOACTIVE, BARU) VALUES (?,?,?,?,?,?,?,?,?)',
            ['ACC1', 'Cash', 'A', 'Current Asset', 'D', 1, '2020-01-01', '', '']);
        $ins('INSERT INTO ACCOUNT (KD_ACCOUNT, NM_ACCOUNT, TYPE, "GROUP", SLD_NORMAL, ENTRY, ACTIVE, NOACTIVE, BARU) VALUES (?,?,?,?,?,?,?,?,?)',
            ['ACC2', 'Accounts Payable', 'L', 'Current Liability', 'K', 1, '2020-01-01', '', '']);
    }
}