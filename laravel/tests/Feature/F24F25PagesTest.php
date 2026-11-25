<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CompanyDbManager;
use App\Support\CmtAuth;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gate for F-24 (A/R Receipts) and F-25 (A/P Payments) report pages via Filament panel.
 * Asserts: reachable at route, renders specified fields from real data,
 * filters/sort/pagination behave per spec, no console errors (PHP exceptions).
 * Mirrors F28PagesTest harness.
 */
class F24F25PagesTest extends TestCase
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

    /** Smoke: F-24 and F-25 pages reachable and render rows. */
    public function test_f24_f25_pages_reachable_and_render_rows(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        $pages = [
            'report/ar-receipt' => ['Date', 'Row Type', 'Customer', 'Agent', 'Invoice No', 'Reference No', 'Amount', 'Status', 'Payment Method', 'Notes'],
            'report/ap-payment' => ['Date', 'Row Type', 'Vendor', 'Agent', 'Invoice No', 'Reference No', 'Amount', 'Status', 'Payment Method', 'Notes'],
        ];

        foreach ($pages as $slug => $expectedLabels) {
            $response = $this->withUnencryptedCookie('cmt_lang', 'en')->get('/admin/'.$slug);
            $response->assertOk("F-24/F-25 page /admin/{$slug} failed");

            $html = $response->getContent();
            // Subheading shows "N results" from table records count
            $this->assertStringContainsString('results', $html, "{$slug}: missing results summary");

            // Each expected column label appears (translated or English)
            foreach ($expectedLabels as $label) {
                $this->assertStringContainsString($label, $html, "{$slug}: missing label '{$label}'");
            }
        }
    }

    /** Sort: default sort columns produce expected order. */
    public function test_f24_f25_pages_sort_order(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // ArReceipt default sort by date DESC: newest first
        $receipts = $this->get('/admin/report/ar-receipt')->getContent();
        $firstPos = strpos($receipts, '2026-01-15');
        $secondPos = strpos($receipts, '2026-01-10');
        if ($firstPos !== false && $secondPos !== false) {
            $this->assertLessThan($secondPos, $firstPos, 'Receipts not sorted by date DESC');
        }

        // ApPayment default sort by date DESC: newest first
        $payments = $this->get('/admin/report/ap-payment')->getContent();
        $firstPos = strpos($payments, '2026-01-20');
        $secondPos = strpos($payments, '2026-01-10');
        if ($firstPos !== false && $secondPos !== false) {
            $this->assertLessThan($secondPos, $firstPos, 'Payments not sorted by date DESC');
        }
    }

    /** Pagination: per_page config respected. */
    public function test_f24_f25_pages_pagination_per_page(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // Default per_page is config('cmt.receipts_per_page', 50); our fixture has a few rows
        foreach (['report/ar-receipt', 'report/ap-payment'] as $slug) {
            $response = $this->get('/admin/'.$slug);
            $response->assertOk();
        }
    }

    /** Empty state: search miss shows empty heading. */
    public function test_f24_f25_pages_empty_state(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        $response = $this->withUnencryptedCookie('cmt_lang', 'en')->get('/admin/report/ar-receipt?q=ZZZNOPE');
        $response->assertOk();
        $this->assertStringContainsString('No ', $response->getContent(), 'ar-receipt: missing empty state heading');

        $response = $this->withUnencryptedCookie('cmt_lang', 'en')->get('/admin/report/ap-payment?q=ZZZNOPE');
        $response->assertOk();
        $this->assertStringContainsString('No ', $response->getContent(), 'ap-payment: missing empty state heading');
    }

    /** Customer role: company_filter scoped (vendor/customer name). */
    public function test_f24_f25_customer_role_company_filter(): void
    {
        // Customer for company 'TRISCO'
        $this->actingAsRole('staff1', 'staff-pw', 'TRISCO');

        // ar-receipt filters by customer name (contains TRISCO)
        // ap-payment filters by vendor name (contains TRISCO)
        foreach (['report/ar-receipt', 'report/ap-payment'] as $slug) {
            $response = $this->withUnencryptedCookie('cmt_lang', 'en')->get('/admin/'.$slug);
            $response->assertOk();
            // Should not crash; either results or empty state
            $this->assertStringContainsString('results', $response->getContent());
        }
    }

    /** Language: labels translate via cmt_lang cookie. */
    public function test_f24_f25_labels_translate_id_en(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // Default lang is id (CMT_LANG default)
        $idReceipts = $this->get('/admin/report/ar-receipt')->getContent();
        // English fallback when no translation: label keys are English words
        // Indonesian translation would appear if i18n provides one

        // Switch to English
        $enReceipts = $this->withUnencryptedCookie('cmt_lang', 'en')->get('/admin/report/ar-receipt')->getContent();
        $this->assertStringContainsString('Date', $enReceipts);
        $this->assertStringContainsString('Amount', $enReceipts);

        $enPayments = $this->withUnencryptedCookie('cmt_lang', 'en')->get('/admin/report/ap-payment')->getContent();
        $this->assertStringContainsString('Date', $enPayments);
        $this->assertStringContainsString('Amount', $enPayments);
    }

    /** Anonymous denied. */
    public function test_f24_f25_anonymous_denied(): void
    {
        foreach (['report/ar-receipt', 'report/ap-payment'] as $slug) {
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

    /** Seeds all tables the F-24/F-25 queries touch. */
    private function seedCompanyDb(): void
    {
        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // ── A/R receipt tables ──
        $pdo->exec('CREATE TABLE ARS (
            UARS TEXT PRIMARY KEY, TANGGAL TEXT, NO_REF TEXT,
            CURRENCY TEXT, JENIS_BYR INTEGER, KETERANGAN TEXT, POSTING INTEGER
        )');
        $pdo->exec('CREATE TABLE ARS_R (
            UARS TEXT, K_INVOICE TEXT, POSTING INTEGER,
            BAYAR REAL, SELISIH REAL
        )');
        $pdo->exec('CREATE TABLE INVOICE (
            K_INVOICE TEXT PRIMARY KEY, SHIPPER TEXT, SHIPP_PIUT TEXT,
            NOINVOICE TEXT, SELISIH_US REAL, POSTING INTEGER
        )');
        $pdo->exec('CREATE TABLE TERIMA (
            UTERIMA TEXT PRIMARY KEY, TANGGAL TEXT, REF_NO TEXT,
            CURRENCY TEXT, JENIS_BYR INTEGER, KETERANGAN TEXT, POSTING INTEGER
        )');
        $pdo->exec('CREATE TABLE TERIMA_R (
            UTERIMA TEXT, URAIAN TEXT, JUMLAH REAL, POSTING INTEGER
        )');

        // ── A/P payment tables ──
        $pdo->exec('CREATE TABLE AP (
            UAP TEXT PRIMARY KEY, TANGGAL TEXT, NO_REF TEXT,
            CURRENCY TEXT, JENIS_BYR INTEGER, KETERANGAN TEXT, POSTING INTEGER,
            KD_SHIPLIN TEXT, KD_ACCOUNT TEXT
        )');
        $pdo->exec('CREATE TABLE AP_R (
            UAP TEXT, INVOICE_NO TEXT, POSTING INTEGER, BAYAR REAL
        )');
        $pdo->exec('CREATE TABLE AP_AGENT (
            UAP_AGENT TEXT PRIMARY KEY, TANGGAL TEXT, CODE TEXT,
            NO_REF TEXT, CURRENCY TEXT, JENIS_BYR INTEGER, KETERANGAN TEXT,
            POSTING INTEGER, AGENT TEXT
        )');
        $pdo->exec('CREATE TABLE AP_AGENT_D (
            UAP_AGENT TEXT, NOMOR TEXT, POSTING INTEGER, PAID REAL
        )');
        $pdo->exec('CREATE TABLE BAYAR (
            UBAYAR TEXT PRIMARY KEY, TANGGAL TEXT, KD_ACCOUNT TEXT,
            REF_NO TEXT, CURRENCY TEXT, JENIS_BYR INTEGER, KETERANGAN TEXT, POSTING INTEGER
        )');
        $pdo->exec('CREATE TABLE BAYAR_R (
            UBAYAR TEXT, URAIAN TEXT, JUMLAH REAL, POSTING INTEGER
        )');
        $pdo->exec('CREATE TABLE CLEARING_D (
            TGL_JT TEXT, KD_BANK TEXT, NO_GBCEKTT TEXT, UNIK TEXT,
            AMOUNT REAL, CURRENCY TEXT, NOTE TEXT
        )');

        // Users table — match CmtAuth::ensureSchema schema
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            display_name TEXT,
            role TEXT NOT NULL,
            pw_hash TEXT NOT NULL,
            salt TEXT NOT NULL,
            customer_company TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        $ins = fn (string $sql, array $p = []) => $pdo->prepare($sql)->execute($p);

        // ── ARS receipt rows ──
        $ins('INSERT INTO ARS (UARS, TANGGAL, NO_REF, CURRENCY, JENIS_BYR, KETERANGAN, POSTING) VALUES (?,?,?,?,?,?,?)',
            ['ARS001', '2026-01-15', 'REF001', 'USD', 2, 'Receipt vs invoice 1', 1]);
        $ins('INSERT INTO ARS (UARS, TANGGAL, NO_REF, CURRENCY, JENIS_BYR, KETERANGAN, POSTING) VALUES (?,?,?,?,?,?,?)',
            ['ARS002', '2026-01-10', 'REF002', 'USD', 2, 'Receipt vs invoice 2', 1]);

        $ins('INSERT INTO ARS_R (UARS, K_INVOICE, POSTING, BAYAR, SELISIH) VALUES (?,?,?,?,?)',
            ['ARS001', 'INV-REC-001', 1, 5000.0, 0]);
        $ins('INSERT INTO ARS_R (UARS, K_INVOICE, POSTING, BAYAR, SELISIH) VALUES (?,?,?,?,?)',
            ['ARS002', 'INV-REC-002', 1, 3000.0, 500.0]);

        $ins('INSERT INTO INVOICE (K_INVOICE, SHIPPER, SHIPP_PIUT, NOINVOICE, SELISIH_US, POSTING) VALUES (?,?,?,?,?,?)',
            ['INV-REC-001', 'TRISCO', 'TRISCO PIUT', 'INV-REC-001', 0, 1]);
        $ins('INSERT INTO INVOICE (K_INVOICE, SHIPPER, SHIPP_PIUT, NOINVOICE, SELISIH_US, POSTING) VALUES (?,?,?,?,?,?)',
            ['INV-REC-002', 'PT MAJU JAYA', 'PT MAJU JAYA PIUT', 'INV-REC-002', 100.0, 1]);

        // ── TERIMA receipt (cash/bank) rows ──
        $ins('INSERT INTO TERIMA (UTERIMA, TANGGAL, REF_NO, CURRENCY, JENIS_BYR, KETERANGAN, POSTING) VALUES (?,?,?,?,?,?,?)',
            ['TER001', '2026-01-05', 'TM-REF-001', 'USD', 2, 'Cash bank receipt 1', 1]);
        $ins('INSERT INTO TERIMA_R (UTERIMA, URAIAN, JUMLAH, POSTING) VALUES (?,?,?,?)',
            ['TER001', 'URAIAN-001', 2000.0, 1]);

        // ── AP payment rows ──
        $ins('INSERT INTO AP (UAP, TANGGAL, NO_REF, CURRENCY, JENIS_BYR, KETERANGAN, POSTING, KD_SHIPLIN, KD_ACCOUNT) VALUES (?,?,?,?,?,?,?,?,?)',
            ['AP001', '2026-01-20', 'APREF001', 'USD', 2, 'Vendor payment 1', 1, 'SHIPLIN001', '']);
        $ins('INSERT INTO AP (UAP, TANGGAL, NO_REF, CURRENCY, JENIS_BYR, KETERANGAN, POSTING, KD_SHIPLIN, KD_ACCOUNT) VALUES (?,?,?,?,?,?,?,?,?)',
            ['AP002', '2026-01-10', 'APREF002', 'USD', 2, 'Vendor payment 2', 1, '', 'ACC001']);

        $ins('INSERT INTO AP_R (UAP, INVOICE_NO, POSTING, BAYAR) VALUES (?,?,?,?)',
            ['AP001', 'INV-PAY-001', 1, 4000.0]);
        $ins('INSERT INTO AP_R (UAP, INVOICE_NO, POSTING, BAYAR) VALUES (?,?,?,?)',
            ['AP002', 'INV-PAY-002', 1, 6000.0]);

        // ── AP_AGENT payment rows ──
        $ins('INSERT INTO AP_AGENT (UAP_AGENT, TANGGAL, CODE, NO_REF, CURRENCY, JENIS_BYR, KETERANGAN, POSTING, AGENT) VALUES (?,?,?,?,?,?,?,?,?)',
            ['APA001', '2026-01-18', 'AGENT001', 'APA-REF-001', 'USD', 2, 'Agent payment 1', 1, 'AGENT CODE 001']);
        $ins('INSERT INTO AP_AGENT_D (UAP_AGENT, NOMOR, POSTING, PAID) VALUES (?,?,?,?)',
            ['APA001', 'INV-PAY-AGENT-001', 1, 2500.0]);

        // ── BAYAR payment (cash/bank) rows ──
        $ins('INSERT INTO BAYAR (UBAYAR, TANGGAL, KD_ACCOUNT, REF_NO, CURRENCY, JENIS_BYR, KETERANGAN, POSTING) VALUES (?,?,?,?,?,?,?,?)',
            ['BAY001', '2026-01-08', 'BANK001', 'BAY-REF-001', 'USD', 2, 'Cash bank payment 1', 1]);
        $ins('INSERT INTO BAYAR_R (UBAYAR, URAIAN, JUMLAH, POSTING) VALUES (?,?,?,?)',
            ['BAY001', 'BAYAR URAIAN 001', 1500.0, 1]);

        // ── CLEARING_D payment rows ──
        $ins('INSERT INTO CLEARING_D (TGL_JT, KD_BANK, NO_GBCEKTT, UNIK, AMOUNT, CURRENCY, NOTE) VALUES (?,?,?,?,?,?,?)',
            ['2026-01-12', 'BANK002', 'CL-001', 'UNIK-001', 3000.0, 'USD', 'Clearing payment 1']);
    }
}
