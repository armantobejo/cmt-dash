<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CompanyDbManager;
use App\Support\CmtAuth;
use Filament\Tables\Table;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gate for the Filament adoption (kanban/ — todo `adopt-filament-view-layer`).
 *
 * During parallel run the Invoices list (F-07) exists twice by design: the
 * parity-verified Blade page at /invoices, and the Filament page at
 * /admin/invoices. This test asserts both renderings carry the SAME data for
 * the same fixture — same rows, same order, same totals, same translated
 * labels — and the same role gates, including the customer_company filter
 * (FR-005b).
 *
 * Run with the project's PHP shim so pdo_sqlite/sqlite3 are loaded:
 *   PHP_INI_SCAN_DIR=laravel/.dev-ini php vendor/bin/phpunit --filter FilamentInvoicesParityTest
 */
class FilamentInvoicesParityTest extends TestCase
{
    private string $dataDir;

    /**
     * Fixture invoices, in the order the list must render them (DATEOFISSU
     * DESC): the two TRISCO rows first, then the foreign one. Amounts are
     * distinct so a mis-ordered or mis-joined column cannot pass by accident.
     */
    private const EXPECTED_ORDER = ['INV-1003', 'INV-1002', 'INV-1001'];

    /** The customer-scoped subset for customer_company = TRISCO. */
    private const EXPECTED_CUSTOMER_ORDER = ['INV-1003', 'INV-1001'];

    private const EXPECTED_MONEY = [
        'INV-1003' => ['$50.75', 'Rp 750.000', '$25.00', 'Rp 375.000'],
        'INV-1002' => ['$200.25', 'Rp 3.000.000', '$0.00', 'Rp 0'],
        'INV-1001' => ['$1,000.50', 'Rp 15.000.000', '$1,000.50', 'Rp 15.000.000'],
    ];

    /** Column label keys — the same 11 the Blade page renders through $t(). */
    private const LABEL_KEYS = [
        'Ref.', 'Kind', 'Customer', 'Shipper', 'Date', 'No. Invoice',
        'Amount (USD)', 'Amount (IDR)', 'Paid (USD)', 'Paid (IDR)', 'Status',
    ];

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

    // ── Criterion 3: same rows, same order, same totals ──────────────────

    public function test_filament_and_blade_render_the_same_rows_in_the_same_order(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        $blade = $this->get('/invoices');
        $filament = $this->get('/admin/invoices');

        $blade->assertOk();
        $filament->assertOk();

        $this->assertSame(self::EXPECTED_ORDER, $this->invoiceNumbers($blade->getContent()));
        $this->assertSame(
            $this->invoiceNumbers($blade->getContent()),
            $this->invoiceNumbers($filament->getContent()),
            'Filament and Blade must list the same invoices in the same order',
        );
    }

    public function test_filament_and_blade_report_the_same_total_and_money_values(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        $blade = $this->get('/invoices')->getContent();
        $filament = $this->get('/admin/invoices')->getContent();

        // Totals: the Blade page's "N hasil" summary and the Filament page's
        // subheading are the same query's COUNT(*).
        $this->assertSame('3 hasil', $this->totalSummary($blade));
        $this->assertSame($this->totalSummary($blade), $this->totalSummary($filament));

        // Per-invoice money: identical CmtMoney output on both surfaces.
        foreach (self::EXPECTED_MONEY as $invoice => $amounts) {
            foreach ($amounts as $amount) {
                $this->assertStringContainsString($amount, $blade, "Blade page missing {$amount} for {$invoice}");
                $this->assertStringContainsString($amount, $filament, "Filament page missing {$amount} for {$invoice}");
            }
        }
    }

    // ── Criterion 4: role gates (5 roles) + customer_company filter ──────

    public function test_all_five_roles_get_the_same_allow_on_both_pages(): void
    {
        $roles = [
            'root1' => 'root-pw',      // superadmin
            'admin1' => 'admin-pw',    // admin
            'chief1' => 'chief-pw',    // management
            'staff1' => 'staff-pw',    // staff
            'cust1' => 'cust-pw',      // customer
        ];

        foreach ($roles as $username => $password) {
            $this->actingAsRole($username, $password);

            $blade = $this->get('/invoices');
            $filament = $this->get('/admin/invoices');

            // F-07 is a role `all` surface: allowed, and showing identical data
            // for every role (the customer role is filtered — asserted below).
            $blade->assertOk();
            $filament->assertOk();
            $this->assertSame(
                $this->invoiceNumbers($blade->getContent()),
                $this->invoiceNumbers($filament->getContent()),
                "Invoice list differs between Blade and Filament for role of {$username}",
            );
        }
    }

    public function test_anonymous_users_are_denied_on_both_pages(): void
    {
        $this->get('/invoices')->assertRedirect('/login');
        $this->get('/admin/invoices')->assertRedirect('/login');
    }

    public function test_customer_role_is_company_filtered_on_both_renderings(): void
    {
        $this->actingAsRole('cust1', 'cust-pw', 'TRISCO');

        $blade = $this->get('/invoices');
        $filament = $this->get('/admin/invoices');

        $blade->assertOk();
        $filament->assertOk();

        $this->assertSame(self::EXPECTED_CUSTOMER_ORDER, $this->invoiceNumbers($blade->getContent()));
        $this->assertSame(
            $this->invoiceNumbers($blade->getContent()),
            $this->invoiceNumbers($filament->getContent()),
        );
        // The foreign company's invoice must be absent from both.
        $this->assertStringNotContainsString('INV-1002', $filament->getContent());
    }

    // ── Criterion 5: every visible string through the translation path ───

    public function test_labels_are_translated_and_match_the_blade_page_per_language(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // Default language is id (CMT_LANG default): both pages render the id
        // dictionaries from lang/cmt/id.json, and the panel's own strings come
        // from Filament's shipped id translations.
        $idBlade = $this->get('/invoices')->getContent();
        $idPanel = $this->get('/admin/invoices')->getContent();

        $this->assertStringContainsString('Jenis', $idPanel);
        $this->assertStringContainsString('Pengirim', $idPanel);
        $this->assertStringContainsString('Dibayar (USD)', $idPanel);
        $this->assertStringContainsString('hasil', $idPanel);
        $this->assertStringContainsString('Cari', $idPanel, 'Filament’s own search label is not localised to id');
        $this->assertStringNotContainsString('Shipper', $idPanel, 'English label leaked into the id rendering');

        // Switching the app's language cookie switches the panel too.
        $enPanel = $this->withUnencryptedCookie('cmt_lang', 'en')
            ->get('/admin/invoices')
            ->getContent();

        $this->assertStringContainsString('Shipper', $enPanel);
        $this->assertStringContainsString('Paid (USD)', $enPanel);
        $this->assertStringContainsString('results', $enPanel);
        $this->assertStringContainsString('Search', $enPanel);
        $this->assertStringNotContainsString('Jenis', $enPanel);

        // Same label set on both renderings for a given language.
        $i18n = $this->app->make(\App\Support\CmtI18n::class);
        foreach (self::LABEL_KEYS as $key) {
            $label = $i18n->t('id', $key);
            $this->assertStringContainsString($label, $idBlade, "Blade page missing label {$label}");
            $this->assertStringContainsString($label, $idPanel, "Filament page missing label {$label}");
        }
    }

    // ── Criterion 2: the DESIGN.md token map is actually wired ───────────

    public function test_panel_page_loads_the_design_token_stylesheet(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        $panel = $this->get('/admin/invoices')->getContent();

        $this->assertStringContainsString('css/cmt/filament-tokens.css', $panel);
    }

    // ── Sort direction flip parity ────────────────────────────────────────

    /**
     * Test that the table's declared default sort is authoritative.
     *
     * This test creates inline subclasses of the Invoices page with different
     * defaultSort() declarations (desc vs asc) and asserts the rendered order
     * follows the declaration. On current code the callback fallback hardcodes
     * 'desc', so flipping the declaration has no effect — this test MUST fail
     * until the declaration is wired into the records() callback.
     */
    public function test_declared_default_sort_is_authoritative(): void
    {
        $this->actingAsRole('staff1', 'staff-pw');

        // Variant A: defaultSort = desc (should render INV-1003, INV-1002, INV-1001)
        $componentDesc = Livewire::test(
            new class extends \App\Filament\Pages\Invoices {
                public function table(Table $table): Table
                {
                    return parent::table($table)->defaultSort('DATEOFISSU', 'desc');
                }
            }
        );

        $htmlDesc = $componentDesc->html();
        $orderDesc = $this->invoiceNumbers($htmlDesc);

        // Variant B: defaultSort = asc (should render INV-1001, INV-1002, INV-1003)
        $componentAsc = Livewire::test(
            new class extends \App\Filament\Pages\Invoices {
                public function table(Table $table): Table
                {
                    return parent::table($table)->defaultSort('DATEOFISSU', 'asc');
                }
            }
        );

        $htmlAsc = $componentAsc->html();
        $orderAsc = $this->invoiceNumbers($htmlAsc);

        // The declared sort MUST change the rendered order.
        // On current code this FAILS because the callback fallback hardcodes 'desc'
        // and ignores the defaultSort() declaration entirely.
        $this->assertNotSame(
            $orderDesc,
            $orderAsc,
            'Flipping defaultSort direction must reverse the row order; '.
            'current code ignores defaultSort due to callback fallback'
        );
    }

    // ── Harness ──────────────────────────────────────────────────────────

    /** Ordered, de-duplicated invoice numbers as they appear in the HTML. */
    private function invoiceNumbers(string $html): array
    {
        preg_match_all('/INV-\d+/', $html, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** "N hasil" / "N results" summary line. */
    private function totalSummary(string $html): ?string
    {
        if (preg_match('/>\s*(\d+ (?:hasil|results))\s*</u', $html, $m) === 1) {
            return $m[1];
        }

        preg_match('/(\d+ (?:hasil|results))/u', $html, $m);

        return $m[1] ?? null;
    }

    private function actingAsRole(string $username, string $password, string $company = ''): static
    {
        // First, get a session token by directly calling the auth service
        // This bypasses the CSRF issue with the login form
        $auth = $this->app->make(CmtAuth::class);
        $token = $auth->authenticate($username, $password);
        
        if ($token === null) {
            throw new \RuntimeException("Failed to authenticate user: {$username}");
        }
        
        // Set the session cookie using the configured cookie name
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

    /** Only the tables the invoice-list query touches (INVOICE + its joins). */
    private function seedCompanyDb(): void
    {
        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE UnifiedShipment (
            UNIK TEXT PRIMARY KEY, TYPETRAN TEXT, DATE TEXT, REFSHITMEN TEXT UNIQUE,
            KD_CONSIG TEXT, KD_SHIPPER TEXT
        )');
        $pdo->exec('CREATE TABLE CONSIGNEE (KD_CONSIG TEXT PRIMARY KEY, NAME TEXT)');
        $pdo->exec('CREATE TABLE SHIPPER (KD_SHIPPER TEXT PRIMARY KEY, NAME TEXT)');
        $pdo->exec('CREATE TABLE INVOICE (
            rowid_ INTEGER PRIMARY KEY AUTOINCREMENT, K_INVOICE INTEGER, NOINVOICE TEXT,
            REFSHITMEN TEXT, EI TEXT, DATEOFISSU TEXT, SHIPPER TEXT,
            GRAND_USD REAL, GRAND_RP REAL, BYR_USD REAL, BYR_RP REAL,
            SELISIH_US REAL, SELISIH_ID REAL, LUNAS INTEGER, REMARK TEXT
        )');

        $ins = fn (string $sql, array $params = []) => $pdo->prepare($sql)->execute($params);

        $ins('INSERT INTO CONSIGNEE VALUES (?,?)', ['C1', 'TRISCO']);
        $ins('INSERT INTO CONSIGNEE VALUES (?,?)', ['C3', 'MAERSK LINE']);
        $ins('INSERT INTO SHIPPER VALUES (?,?)', ['S1', 'MAERSK LINE']);
        $ins('INSERT INTO SHIPPER VALUES (?,?)', ['S2', 'PT MAJU JAYA']);

        // Import rows: customer = consignee name. Export row: customer = shipper name.
        $ins('INSERT INTO UnifiedShipment (UNIK, TYPETRAN, DATE, REFSHITMEN, KD_CONSIG, KD_SHIPPER) VALUES (?,?,?,?,?,?)',
            ['U1', 'I', '2026-01-08', 'REF-TRISCO-001', 'C1', 'S1']);
        $ins('INSERT INTO UnifiedShipment (UNIK, TYPETRAN, DATE, REFSHITMEN, KD_CONSIG, KD_SHIPPER) VALUES (?,?,?,?,?,?)',
            ['U2', 'E', '2026-02-18', 'REF-MAJU-001', 'C3', 'S2']);
        $ins('INSERT INTO UnifiedShipment (UNIK, TYPETRAN, DATE, REFSHITMEN, KD_CONSIG, KD_SHIPPER) VALUES (?,?,?,?,?,?)',
            ['U3', 'I', '2026-03-03', 'REF-TRISCO-002', 'C1', 'S1']);

        $ins('INSERT INTO INVOICE (K_INVOICE, NOINVOICE, REFSHITMEN, EI, DATEOFISSU, SHIPPER, GRAND_USD, GRAND_RP, BYR_USD, BYR_RP, SELISIH_US, SELISIH_ID, LUNAS, REMARK) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [1, 'INV-1001', 'REF-TRISCO-001', 'I', '2026-01-10', 'S1', 1000.5, 15000000.0, 1000.5, 15000000.0, 0.0, 0.0, 1, 'paid in full']);
        $ins('INSERT INTO INVOICE (K_INVOICE, NOINVOICE, REFSHITMEN, EI, DATEOFISSU, SHIPPER, GRAND_USD, GRAND_RP, BYR_USD, BYR_RP, SELISIH_US, SELISIH_ID, LUNAS, REMARK) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [2, 'INV-1002', 'REF-MAJU-001', 'E', '2026-02-20', 'S2', 200.25, 3000000.0, 0.0, 0.0, 0.0, 0.0, 0, '']);
        $ins('INSERT INTO INVOICE (K_INVOICE, NOINVOICE, REFSHITMEN, EI, DATEOFISSU, SHIPPER, GRAND_USD, GRAND_RP, BYR_USD, BYR_RP, SELISIH_US, SELISIH_ID, LUNAS, REMARK) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [3, 'INV-1003', 'REF-TRISCO-002', 'I', '2026-03-05', 'S1', 50.75, 750000.0, 25.0, 375000.0, 0.0, 0.0, 0, 'partial']);
    }
}
