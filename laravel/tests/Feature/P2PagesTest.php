<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CompanyDbManager;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P2 gate: dashboard, shipments, invoices — pages AND page signatures must
 * match the Python parity baseline (tests/parity/baseline/python.json).
 *
 * The dataset mirrors tests/parity/parity_dataset.py: 3 shipments (import,
 * export-no-invoice, export), one invoice with a FREIGHT line, one OPRCOST
 * with a THC line, so the rendered cells can be asserted against the same
 * strings the Python baseline captured.
 */
class P2PagesTest extends TestCase
{
    /** Public so ProductionSchemaTest can point the reusable seed elsewhere. */
    public string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing/'.Str::random(10));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->dataDir = $dir;
        // dbf_dir must never point at the real (large) DBF tree from tests:
        // the management /sync contract test would otherwise run a full sync.
        // The committed fixtures are small and deterministic; copyAppTables
        // keeps the seeded users/sessions across the swap.
        config([
            'cmt.data_dir' => $dir,
            'cmt.users_env' => '',
            'cmt.dbf_dir' => base_path('tests/fixtures/dbf'),
        ]);

        $factory = new CompanyConnectionFactory(
            resolver: $this->app->make(ConnectionResolverInterface::class),
            dataDir: $dir,
        );
        $this->app->instance(CompanyConnectionFactory::class, $factory);
        $this->app->instance(CompanyDbManager::class, new CompanyDbManager($dir, 'CMT'));

        $this->seedCompanyDb();
        $this->seedUsers();
    }

    // ── Dashboard ────────────────────────────────────────────────────────

    public function test_dashboard_staff_matches_baseline_signature(): void
    {
        $page = $this->actingAsRole('staff1', 'staff-pw')->get('/');

        $page->assertOk();
        $this->assertBaselineStrings('dashboard/staff', $page->getContent());
    }

    public function test_dashboard_customer_scopes_to_company(): void
    {
        $page = $this->actingAsRole('cust1', 'cust-pw', 'Trisco')->get('/');

        $page->assertOk();
        $this->assertBaselineStrings('dashboard/customer', $page->getContent());
    }

    // ── Shipments list ───────────────────────────────────────────────────

    public function test_shipments_default_matches_baseline(): void
    {
        $page = $this->actingAsRole('staff1', 'staff-pw')->get('/shipments');

        $page->assertOk();
        $this->assertBaselineStrings('shipments/default', $page->getContent());
    }

    public function test_shipments_type_filters_match_baseline_counts(): void
    {
        $staff = $this->actingAsRole('staff1', 'staff-pw');

        // Baseline type_import -> only REF-TRISCO-001; type_export -> the two E rows.
        $import = $staff->get('/shipments?type=import')->getContent();
        $this->assertStringContainsString('REF-TRISCO-001', $import);
        $this->assertStringNotContainsString('REF-OTHER-001', $import);

        $export = $staff->get('/shipments?type=export')->getContent();
        $this->assertStringContainsString('REF-OTHER-001', $export);
        $this->assertStringContainsString('REF-TRISCO-002', $export);
        $this->assertStringNotContainsString('REF-TRISCO-001', $export);

        $other = $staff->get('/shipments?type=other')->getContent();
        $this->assertStringContainsString('Tidak ada pengiriman', $other);
    }

    public function test_shipments_invoice_filters(): void
    {
        $staff = $this->actingAsRole('staff1', 'staff-pw');

        $unissued = $staff->get('/shipments?invoice=unissued')->getContent();
        $this->assertStringContainsString('REF-TRISCO-002', $unissued);
        $this->assertStringNotContainsString('REF-TRISCO-001', $unissued);

        $paid = $staff->get('/shipments?invoice=paid')->getContent();
        $this->assertStringContainsString('REF-TRISCO-001', $paid);
        $this->assertStringNotContainsString('REF-OTHER-001', $paid);
    }

    public function test_shipments_customer_view_has_invoice_column(): void
    {
        $page = $this->actingAsRole('cust1', 'cust-pw', 'Trisco')->get('/shipments');

        $page->assertOk();
        $this->assertBaselineStrings('shipments/customer_view', $page->getContent());
    }

    public function test_shipments_search_miss_shows_empty_state(): void
    {
        $page = $this->actingAsRole('staff1', 'staff-pw')->get('/shipments?q=ZZZNOPE');

        $page->assertOk()->assertSee('Tidak ada pengiriman', false);
    }

    // ── Shipment detail ──────────────────────────────────────────────────

    public function test_shipment_detail_staff_matches_baseline(): void
    {
        $page = $this->actingAsRole('staff1', 'staff-pw')->get('/shipments/detail?ref=REF-TRISCO-001');

        $page->assertOk();
        $this->assertBaselineStrings('shipment_detail/staff_known', $page->getContent());
    }

    public function test_shipment_detail_customer_foreign_ref_still_renders(): void
    {
        // KG-1 parity: NO customer guard on shipment detail — a customer can
        // open another company's shipment (defect-for-defect).
        $page = $this->actingAsRole('cust1', 'cust-pw', 'Trisco')->get('/shipments/detail?ref=REF-OTHER-001');

        $page->assertOk();
        $this->assertBaselineStrings('shipment_detail/customer_foreign_ref', $page->getContent());
    }

    public function test_shipment_detail_unknown_ref_redirects(): void
    {
        $this->actingAsRole('staff1', 'staff-pw')
            ->get('/shipments/detail?ref=NOPE')
            ->assertStatus(303)
            ->assertRedirect('/shipments');
    }

    public function test_shipment_detail_missing_ref_is_fastapi_422(): void
    {
        $this->actingAsRole('staff1', 'staff-pw')
            ->get('/shipments/detail')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc.1', 'ref');
    }

    // ── Invoices ─────────────────────────────────────────────────────────

    public function test_invoices_default_matches_baseline(): void
    {
        $page = $this->actingAsRole('staff1', 'staff-pw')->get('/invoices');

        $page->assertOk();
        $this->assertBaselineStrings('invoices/list_default', $page->getContent());
    }

    public function test_invoices_customer_scopes_to_company(): void
    {
        $page = $this->actingAsRole('cust1', 'cust-pw', 'Trisco')->get('/invoices');

        $page->assertOk();
        $this->assertBaselineStrings('invoices/customer_list', $page->getContent());
    }

    public function test_invoices_search_narrows(): void
    {
        $page = $this->actingAsRole('staff1', 'staff-pw')->get('/invoices?q=INV-0001');

        $page->assertOk();
        $this->assertStringContainsString('INV-0001', $page->getContent());
        $this->assertStringNotContainsString('INV-0002', $page->getContent());
    }

    public function test_invoice_detail_matches_baseline(): void
    {
        $page = $this->actingAsRole('staff1', 'staff-pw')->get('/invoices/detail?k=1');

        $page->assertOk();
        $this->assertBaselineStrings('invoices/detail_known', $page->getContent());
    }

    public function test_invoice_detail_customer_foreign_redirects(): void
    {
        // Rowid 2 = INV-0002 (PT Sumber Rejeki) — foreign for Trisco.
        $this->actingAsRole('cust1', 'cust-pw', 'Trisco')
            ->get('/invoices/detail?k=2')
            ->assertStatus(303)
            ->assertRedirect('/invoices');
    }

    public function test_invoice_detail_missing_k_is_fastapi_422(): void
    {
        $this->actingAsRole('staff1', 'staff-pw')
            ->get('/invoices/detail')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc.1', 'k');
    }

    // ── Sync RBAC (management+) ──────────────────────────────────────────

    public function test_sync_denied_below_management(): void
    {
        $token = $this->login('staff1', 'staff-pw');
        $this->withUnencryptedCookie('cmt_session', $token)
            ->post('/sync')
            ->assertStatus(403);
    }

    public function test_sync_allowed_for_management(): void
    {
        $token = $this->login('chief1', 'chief-pw');
        $this->withUnencryptedCookie('cmt_session', $token)
            ->post('/sync')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    // ── Harness ──────────────────────────────────────────────────────────

    /**
     * Assert the captured baseline strings for a step appear in the page.
     * Baseline strings are HTML-escaped block texts; assert each is present.
     */
    private function assertBaselineStrings(string $step, string $html): void
    {
        $baseline = json_decode(
            (string) file_get_contents(base_path('../tests/parity/baseline/python.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        [$domain, $key] = explode('/', $step);
        $strings = $baseline['domains'][$domain][$key]['data_strings'] ?? null;
        $this->assertIsArray($strings, "baseline step {$step} has no data_strings");

        // The layout chrome differs by role/session between steps; the data
        // strings captured after the chrome are what must match. Compare the
        // tail of the capture (page-specific strings) instead of all.
        $missing = [];
        foreach ($strings as $s) {
            if ($s === '<SYNC_TIME>' || str_starts_with($s, 'Data tersinkron')) {
                continue;
            }
            // Skip chrome-only strings (sidebar, menus, buttons).
            if (in_array($s, ['CMT Dashboard', 'CMT Dash', 'Beranda', 'Penjualan', 'Pengiriman',
                'Akuntansi & Keuangan', 'Invoice', 'Buku Besar', 'cmt', 'CME', 'ID',
                'Bahasa Indonesia', 'English', '中文', 'Light', 'Ringkasan',
                'Gambaran umum data sistem', 'Lihat Semua', 'Semua Jenis', 'Impor', 'Ekspor',
                'Semua Invoice', 'Invoice Belum Diterbitkan', 'Invoice Diterbitkan',
                'Invoice Diterbitkan & Belum Dibayar', 'Invoice Dibayar', 'Halaman 1 / 1',
            ], true)) {
                continue;
            }
            if (! str_contains($html, $s)) {
                $missing[] = $s;
            }
        }
        $this->assertSame([], $missing, "Missing baseline strings for {$step}");
    }

    private function login(string $username, string $password): string
    {
        return $this->post('/login', ['username' => $username, 'password' => $password])
            ->getCookie('cmt_session', false)->getValue();
    }

    private function actingAsRole(string $username, string $password, string $company = ''): static
    {
        $token = $this->login($username, $password);
        $this->withUnencryptedCookie('cmt_session', $token);
        if ($company !== '') {
            // The session row carries customer_company; seed directly.
            $this->app->make(CompanyConnectionFactory::class)->authConnection()
                ->table('users')->where('username', $username)
                ->update(['customer_company' => $company]);
        }

        return $this;
    }

    private function seedUsers(): void
    {
        config(['cmt.users_env' => 'staff1:staff-pw:staff,cust1:cust-pw:customer,chief1:chief-pw:management']);
        $this->app->make(\App\Support\CmtAuth::class)->seedFromEnv();
    }

    /** Public so ProductionSchemaTest can reuse the seed for its gates. */
    public function seedCompanyDb(): void
    {
        // INVOICE is seeded WITHOUT a CONTAINERN column: production schemas are
        // sync-owned (docs/decisions/invoice-container-schema-drift.md) and the
        // detail query must never reference columns the sync doesn't create.
        // Gate: laravel/tests/Feature/ProductionSchemaTest.php.
        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
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
        $pdo->exec('CREATE TABLE VESSEL (KD_VESSEL TEXT PRIMARY KEY, NM_VESSEL TEXT)');
        $pdo->exec('CREATE TABLE AGENT (AGENT TEXT, CODE TEXT, NAME TEXT)');
        $pdo->exec('CREATE TABLE OPRCOST (
            UOPRCOST TEXT PRIMARY KEY, REFSHITMEN TEXT, NOMOR TEXT, TANGGAL TEXT,
            EXRATE REAL, GRAND_USD REAL, GRAND_RP REAL
        )');
        $pdo->exec('CREATE TABLE OPRCOST_R (
            UOPRCOST TEXT, JENISCOST TEXT, DESCRIPTIO TEXT, CURRENCY TEXT,
            QTY REAL, TSRATE REAL, JUMLAH REAL, INVOICE_NO TEXT, INV_TGL TEXT, KETERANGAN TEXT
        )');
        $pdo->exec('CREATE TABLE INVOICE (
            rowid_ INTEGER PRIMARY KEY AUTOINCREMENT, K_INVOICE INTEGER, NOINVOICE TEXT,
            REFSHITMEN TEXT, EI TEXT, DATEOFISSU TEXT, SHIPPER TEXT, EXRATE REAL,
            GRAND_USD REAL, GRAND_RP REAL, BYR_USD REAL, BYR_RP REAL, DIRPKAN REAL,
            DIUSDKAN REAL, SELISIH_US REAL, SELISIH_ID REAL, LUNAS INTEGER,
            REMARK TEXT, NOTEINV TEXT, BLNO TEXT, BLNO3 TEXT,
            PORTOFLOAD TEXT, PORTOFDISC TEXT, VESSELNAME TEXT, ETDDATE TEXT, ETADATE TEXT
        )');
        $pdo->exec('CREATE TABLE INVOICE_R (
            K_INVOICE INTEGER, NOINVOICE TEXT, NO INTEGER, JENISCOST TEXT, DESCRIPTIO TEXT,
            CURRENCY TEXT, QTY REAL, SELLING REAL, JUMLAH REAL, NOTE TEXT, KD_SATUAN TEXT
        )');
        $pdo->exec('CREATE TABLE SATUAN (KD_SATUAN TEXT PRIMARY KEY, SATUAN TEXT)');
        $pdo->exec('CREATE TABLE DRCRFULL (
            UNIK TEXT, NO_REF TEXT, PLUS TEXT, TANGGAL TEXT, TONAME TEXT, MODE TEXT,
            BLNO TEXT, BLNO3 TEXT, TOTAL REAL, TOTAL_IDR REAL, BAYAR REAL, DIRPKAN REAL,
            BAYAR_IDR REAL, DIUSDKAN REAL, LUNAS INTEGER, ACCT_DRCR TEXT, REFSHITMEN TEXT
        )');
        $pdo->exec('CREATE TABLE DRCRFULL_R (
            UNIK TEXT, JENISCOST TEXT, DESCRIPTIO TEXT, CURRENCY TEXT,
            QTY REAL, UNITPRICE REAL, JUMLAH REAL
        )');

        $ins = fn (string $sql, array $p = []) => $pdo->prepare($sql)->execute($p);

        // Parties (parity dataset: C1/C2/C3, S1/S2, AG1/AG2; no vessel match —
        // the dataset leaves KD_EXVESSE null so 'Kapal' renders '-').
        $ins('INSERT INTO CONSIGNEE VALUES (?,?)', ['C1', 'TRISCO']);
        $ins('INSERT INTO CONSIGNEE VALUES (?,?)', ['C2', 'PT MAJU JAYA']);
        $ins('INSERT INTO CONSIGNEE VALUES (?,?)', ['C3', 'MAERSK LINE']);
        $ins('INSERT INTO SHIPPER VALUES (?,?)', ['S1', 'MAERSK LINE']);
        $ins('INSERT INTO SHIPPER VALUES (?,?)', ['S2', 'PT Sumber Rejeki']);
        $ins('INSERT INTO AGENT VALUES (?,?,?)', ['AG1', 'C01', 'Evergreen Agent']);
        $ins('INSERT INTO AGENT VALUES (?,?,?)', ['AG2', 'C02', 'Maersk Agent']);

        // Shipment 1: import, invoiced + paid, with OPRCOST (the baseline anchor).
        $ins('INSERT INTO UnifiedShipment VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            'U1', 'I', '2026-03-15', 'REF-TRISCO-001', 'BL-001', 'C1', 'S1', null, null,
            'AG1', 'C01', '1x20', 'SIN', 'JKT', 'EVERGREEN', 'Gen cargo', 'TRISCO', 'MAERSK LINE',
            'SAID', null, null, null, null, null,
        ]);
        $ins('INSERT INTO OPRCOST (UOPRCOST, REFSHITMEN, NOMOR, TANGGAL, EXRATE, GRAND_USD, GRAND_RP) VALUES (?,?,?,?,?,?,?)', ['OP-1', 'REF-TRISCO-001', 'OPR-1', '2026-03-16', 15000.0, 200.0, 3000000.0]);
        $ins('INSERT INTO OPRCOST_R VALUES (?,?,?,?,?,?,?,?,?,?)', ['OP-1', 'THC', 'Terminal handling', 'USD', 1.0, 200.0, 200.0, 'TI-1', '', '']);

        // Shipment 2: export, no invoice (unissued).
        $ins('INSERT INTO UnifiedShipment VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            'U2', 'E', '2026-04-20', 'REF-TRISCO-002', 'BL-002', '', 'S1', null, '',
            '', '', '1x40', 'SIN', 'NLRTM', '', '', '', 'MAERSK LINE',
            '', '', '', '', '', '',
        ]);

        // Shipment 3: export, invoiced unpaid (the dashboard's unpaid anchor) —
        // the parity dataset's U3: foreign consignee C3, Maersk agent.
        $ins('INSERT INTO UnifiedShipment VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            'U3', 'E', '2026-05-05', 'REF-OTHER-001', 'BL-003', 'C3', 'S2', null, null,
            'AG2', 'C02', '1x20', 'SIN', 'NLRTM', 'MAERSK', null, 'MAERSK LINE', 'PT Sumber Rejeki',
            'SAID', null, null, null, null, null,
        ]);
        $ins('INSERT INTO INVOICE (rowid_, K_INVOICE, NOINVOICE, REFSHITMEN, EI, DATEOFISSU, SHIPPER, EXRATE, GRAND_USD, GRAND_RP, BYR_USD, BYR_RP, DIRPKAN, DIUSDKAN, SELISIH_US, SELISIH_ID, LUNAS, REMARK, NOTEINV, BLNO, BLNO3, PORTOFLOAD, PORTOFDISC, VESSELNAME, ETDDATE, ETADATE) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            1, 1, 'INV-0001', 'REF-TRISCO-001', 'I', '2026-03-18', 'S1', 15000.0, 1200.0, 18000000.0, 1200.0, 18000000.0, null, null, 0.0, 0.0, 1, 'paid in full', 'cust note', 'BL-001', 'BL-001', 'SIN', 'JKT', 'MV OCEAN STAR', '2026-03-15', '2026-03-20',
        ]);
        $ins('INSERT INTO INVOICE (rowid_, K_INVOICE, NOINVOICE, REFSHITMEN, EI, DATEOFISSU, SHIPPER, EXRATE, GRAND_USD, GRAND_RP, BYR_USD, BYR_RP, DIRPKAN, DIUSDKAN, SELISIH_US, SELISIH_ID, LUNAS, REMARK, NOTEINV, BLNO, BLNO3, PORTOFLOAD, PORTOFDISC, VESSELNAME, ETDDATE, ETADATE) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            2, 2, 'INV-0002', 'REF-OTHER-001', 'E', '2026-05-06', 'S2', 15000.0, 90.0, 1350000.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0, '', '', 'BL-003', 'BL-003', 'SIN', 'NLRTM', 'MAERSK ATLANTIC', '2026-05-05', '2026-05-25',
        ]);
        $ins('INSERT INTO INVOICE_R VALUES (?,?,?,?,?,?,?,?,?,?,?)', [1, 'INV-0001', 1, 'FREIGHT', 'Ocean freight', 'USD', 1.0, 1200.0, 1200.0, '', 'CBM']);
        $ins('INSERT INTO SATUAN VALUES (?,?)', ['CBM', 'CBM']);
        // DN row on REF-TRISCO-001 (parity dataset D1) — renders in the
        // shipments list's DNCN columns ($50.00 / Rp 750.000).
        $ins('INSERT INTO DRCRFULL (UNIK, NO_REF, REFSHITMEN, TANGGAL, TONAME, MODE, BLNO, BLNO3, TOTAL, TOTAL_IDR, BAYAR, DIRPKAN, BAYAR_IDR, DIUSDKAN, LUNAS, ACCT_DRCR) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', ['D1', 'DN-1', 'REF-TRISCO-001', '2026-03-20', 'TRISCO', 'DN', 'BL-001', 'BL-001', 50.0, 750000.0, 0.0, 0.0, 0.0, 0.0, 0, '']);
    }
}
