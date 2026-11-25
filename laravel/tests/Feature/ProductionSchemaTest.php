<?php

namespace Tests\Feature;

use App\Support\CmtQueries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Schema gates (PHP mirror of tests/test_schema_gate.py): phantom-column
 * regressions can never silently return (the INVOICE.CONTAINERN lesson —
 * docs/decisions/invoice-container-schema-drift.md).
 *
 * Layer 1: the committed fixture matches the real data/*.db (skipped when the
 *          checkout has none; hard gate where they exist).
 * Layer 2: the P2 test seed's business tables are a subset of production shape.
 * Layer 3: every CmtQueries method executes against a production-shaped
 *          UnifiedShipment/INVOICE (no CONTAINERN on INVOICE).
 */
class ProductionSchemaTest extends TestCase
{
    private string $dataDir;

    private bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing/p3gate-'.Str::random(8));
        mkdir($dir, 0777, true);
        $this->dataDir = $dir;
        config([
            'cmt.data_dir' => $dir,
            'cmt.users_env' => '',
            'cmt.dbf_dir' => base_path('tests/fixtures/dbf'),
        ]);

        $factory = new \App\Support\CompanyConnectionFactory(
            resolver: $this->app->make(\Illuminate\Database\ConnectionResolverInterface::class),
            dataDir: $dir,
        );
        $this->app->instance(\App\Support\CompanyConnectionFactory::class, $factory);
        $this->app->instance(\App\Support\CompanyDbManager::class, new \App\Support\CompanyDbManager($dir, 'CMT'));
    }

    /** data/*.db sibling dirs, for the mirror of Python's DATA_DIR glob. */
    private static function fixturePath(): string
    {
        return base_path('../tests/parity/fixtures/real_schema.json');
    }

    // ── Layer 1: fixture fidelity ────────────────────────────────────────

    public function test_gate_fixture_matches_real_dbs_when_present(): void
    {
        $dbFiles = glob(base_path('../data/*.db'));
        if ($dbFiles === false || $dbFiles === []) {
            $this->markTestSkipped('no data/*.db in this checkout');
        }

        $committed = [];
        foreach (glob(base_path('../data/*.db')) as $db) {
            $company = strtoupper(pathinfo($db, PATHINFO_FILENAME));
            $pdo = new \PDO('sqlite:'.$db, null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
            $tables = [];
            foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
                $cols = [];
                foreach ($pdo->query('PRAGMA table_info("'.$row['name'].'")') as $c) {
                    $cols[$c['name']] = $c['type'];
                }
                $tables[$row['name']] = $cols;
            }
            $committed[$company] = $tables;
        }

        $fixture = json_decode((string) file_get_contents(self::fixturePath()), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(
            $fixture,
            $committed,
            'tests/parity/fixtures/real_schema.json is stale vs data/*.db — re-run: python tools/dump_real_schema.py'
        );
    }

    public function test_fixture_pins_no_containern_on_invoice(): void
    {
        $fixture = json_decode((string) file_get_contents(self::fixturePath()), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($fixture as $company => $tables) {
            $this->assertArrayNotHasKey(
                'CONTAINERN',
                $tables['INVOICE'] ?? [],
                "$company: INVOICE.CONTAINERN reappeared in the gate fixture — both apps' detail queries must be reconciled first"
            );
        }
    }

    // ── Layer 2: seed ⊆ production shape ─────────────────────────────────

    public function test_seed_tables_are_subset_of_production_shape(): void
    {
        $prod = $this->productionShape()['CMT'] ?? [];
        $seeded = $this->seededColumnSets();

        foreach ($seeded as $table => $cols) {
            $extra = array_diff($cols, array_keys($prod[$table] ?? []));
            $this->assertSame(
                [],
                array_values($extra),
                "$table: seed-only columns would make queries pass in tests but fail on production data (phantom-column class of bug)"
            );
        }
    }

    // ── Layer 3: every CmtQueries method runs on production shape ────────

    public function test_every_query_method_runs_on_production_shape(): void
    {
        // An execution gate: no assertions, only "every query survives".
        $this->expectNotToPerformAssertions();

        $queries = $this->makeQueries();

        $queries->getSummary();
        $queries->getSummary('TRISCO');
        $queries->getRecentShipments();
        $queries->getUnpaidShipments();
        $queries->getShipments();
        $queries->getShipments(search: 'INV');
        $queries->getShipments(typeFilter: 'import');
        $queries->getShipments(invoiceFilter: 'paid');
        $queries->getTopCustomers();
        $queries->getInvoices();
        $queries->getInvoices(search: 'INV');
        $queries->getShipmentDetail('REF-TRISCO-001');
        $queries->getInvoiceDetail('1'); // P2 seed: K_INVOICE INTEGER 1 (k=1 route contract)
        $queries->getCompanyNames();
    }

    public function test_invoice_detail_pinned_containern_regression(): void
    {
        $detail = $this->makeQueries()->getInvoiceDetail('1'); // seed key: k=1 route contract

        $this->assertNotNull($detail);
        $this->assertObjectNotHasProperty(
            'CONTAINERN',
            $detail['header'],
            'invoice detail re-selected the production-absent INVOICE.CONTAINERN column — the exact regression this gate pins'
        );
    }

    // ── Harness ──────────────────────────────────────────────────────────

    /** CMT shape from the committed fixture (Python dump_real_schema.py). */
    private function productionShape(): array
    {
        return json_decode((string) file_get_contents(self::fixturePath()), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Column sets of the P2 seed's business tables (the tables the query
     * layer touches; rowid_-mirroring primary keys excluded). Read live from
     * the seeded file rather than duplicating the DDL here.
     */
    private function seededColumnSets(): array
    {
        $this->seedCompanyDb();

        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $tables = [];
        foreach (['UnifiedShipment', 'OPRCOST', 'OPRCOST_R', 'INVOICE', 'INVOICE_R', 'SATUAN', 'DRCRFULL', 'DRCRFULL_R', 'CONSIGNEE', 'SHIPPER', 'AGENT'] as $table) {
            $cols = [];
            foreach ($pdo->query('PRAGMA table_info("'.$table.'")') as $c) {
                $cols[] = $c['name'];
            }
            if ($cols !== []) {
                $tables[$table] = $cols;
            }
        }

        return $tables;
    }

    private function makeQueries(): CmtQueries
    {
        $this->seedCompanyDb();

        // Ensure the manager's active connection resolves to the seeded file.
        DB::purge();

        return $this->app->make(CmtQueries::class);
    }

    /** Seeds the P2 dataset into this test's data dir (once per test). */
    private function seedCompanyDb(): void
    {
        if ($this->seeded) {
            return;
        }
        $seed = new P2PagesTest('test_dashboard_matches_baseline');
        $seed->dataDir = $this->dataDir;
        $seed->seedCompanyDb();
        $this->seeded = true;
    }
}
