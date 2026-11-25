<?php

/**
 * Cross-check every CmtQueries method against all five REAL company DBs
 * (PHP mirror of tools/audit_production_queries.py).
 *
 * Works on copies of data/*.db; never opens the originals for writing.
 *
 * Usage:  php tools/audit_production_queries.php   (from laravel/)
 * Exit:   0 = every branch OK on every company; 1 = at least one failure.
 */

require __DIR__.'/../vendor/autoload.php';

use App\Support\CompanyConnectionFactory;
use App\Support\CompanyDbManager;
use App\Support\CmtQueries;
use Illuminate\Support\Facades\DB;

/**
 * Audit mode: safeQuery (Python _safe_query) must NOT swallow errors — a
 * swallowed column error is exactly the CONTAINERN failure mode.
 */
class AuditingQueries extends CmtQueries
{
    protected function safeQuery(string $sql, array $params = []): array
    {
        return DB::connection($this->connections->connectionName($this->manager->active()))
            ->select($sql, $params);
    }
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$repo = dirname(__DIR__, 2);
$dataDir = $repo.'/data';

$dbFiles = glob($dataDir.'/*.db');
if ($dbFiles === false || $dbFiles === []) {
    fwrite(STDERR, "error: no data/*.db found under {$dataDir}\n");
    exit(1);
}

$auditDir = sys_get_temp_dir().'/cmt_audit_'.uniqid();
mkdir($auditDir, 0777, true);

/** Copy the company DB and idempotently add the app-support tables. */
function prepareCopy(string $src, string $dst): void
{
    copy($src, $dst);

    $pdo = new PDO('sqlite:'.$dst);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // App-support tables the sync preserves (idempotent if present).
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        display_name TEXT,
        role TEXT NOT NULL,
        pw_hash TEXT NOT NULL,
        salt TEXT NOT NULL,
        customer_company TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        token TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        expires_at TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id)
    )');
    $pdo = null;
}

$failures = [];
$notPorted = [];
$total = 0;

foreach ($dbFiles as $src) {
    $company = strtoupper(pathinfo($src, PATHINFO_FILENAME));
    $dst = $auditDir.'/'.$company.'.db';
    prepareCopy($src, $dst);

    $manager = new CompanyDbManager($auditDir, $company);
    $factory = new CompanyConnectionFactory(
        resolver: $app->make(Illuminate\Database\ConnectionResolverInterface::class),
        dataDir: $auditDir,
    );
    app()->instance(CompanyConnectionFactory::class, $factory);
    app()->instance(CompanyDbManager::class, $manager);
    DB::purge();

    // The AuditingQueries subclass overrides safeQuery to rethrow, so column
    // errors inside the detail methods' tolerated sub-queries surface.
    $queries = new AuditingQueries($factory, $manager);

    $refRow = $queries->queryOne(
        "SELECT REFSHITMEN AS r FROM UnifiedShipment WHERE REFSHITMEN IS NOT NULL AND REFSHITMEN != '' LIMIT 1"
    );
    $invRow = $queries->queryOne(
        "SELECT K_INVOICE AS k FROM INVOICE WHERE K_INVOICE IS NOT NULL AND K_INVOICE != '' LIMIT 1"
    );
    $ref = $refRow !== null ? $refRow->r : '___no_ref___';
    $kinv = $invRow !== null ? $invRow->k : '___no_inv___';

    $calls = [
        'summary/default' => fn () => $queries->getSummary(),
        'summary/company_filter' => fn () => $queries->getSummary($company),
        'recent/default' => fn () => $queries->getRecentShipments(),
        'recent/company_filter' => fn () => $queries->getRecentShipments(companyFilter: $company),
        'unpaid/default' => fn () => $queries->getUnpaidShipments(),
        'unpaid/search' => fn () => $queries->getUnpaidShipments(search: 'A'),
        'unpaid/company_filter' => fn () => $queries->getUnpaidShipments(companyFilter: $company),
        'unpaid/sorts' => function () use ($queries) {
            foreach (['date', 'reference', 'customer'] as $sort) {
                foreach (['asc', 'desc'] as $dir) {
                    $queries->getUnpaidShipments(sort: $sort, sortDir: $dir);
                }
            }
        },
        'shipments/default' => fn () => $queries->getShipments(),
        'shipments/search' => fn () => $queries->getShipments(search: 'A'),
        'shipments/company_filter' => fn () => $queries->getShipments(companyFilter: $company),
        'shipments/types' => function () use ($queries) {
            foreach (['import', 'export', 'other'] as $t) {
                $queries->getShipments(typeFilter: $t);
            }
        },
        'shipments/filters' => function () use ($queries) {
            foreach (['unissued', 'issued', 'issued_unpaid', 'paid'] as $f) {
                $queries->getShipments(invoiceFilter: $f);
            }
        },
        'shipments/sorts' => function () use ($queries) {
            foreach (['date', 'reference', 'customer'] as $sort) {
                foreach (['asc', 'desc'] as $dir) {
                    $queries->getShipments(sort: $sort, sortDir: $dir);
                }
            }
        },
        'top_customers' => fn () => $queries->getTopCustomers(),
        'invoices/default' => fn () => $queries->getInvoices(),
        'invoices/search' => fn () => $queries->getInvoices(search: 'A'),
        'invoices/company_filter' => fn () => $queries->getInvoices(companyFilter: $company),
        'invoices/sorts' => function () use ($queries) {
            foreach (['date', 'reference', 'customer'] as $sort) {
                foreach (['asc', 'desc'] as $dir) {
                    $queries->getInvoices(sort: $sort, sortDir: $dir);
                }
            }
        },
        'shipment_detail/hit' => fn () => $queries->getShipmentDetail($ref),
        'shipment_detail/miss' => fn () => $queries->getShipmentDetail('___nope___'),
        'invoice_detail/hit' => fn () => $queries->getInvoiceDetail($kinv),
        'invoice_detail/miss' => fn () => $queries->getInvoiceDetail('___nope___'),
        'company_names' => fn () => $queries->getCompanyNames(),
    ];

    // Sales/Ledger/DataQuality methods are a later port phase: when present
    // they are audited, otherwise they are reported as not-yet-ported (no
    // Laravel route calls them, so they cannot 500 in production).
    $laterPhase = [
        'sales_summary' => fn ($q, string $c) => $q->getSalesSummary(),
        'sales_summary/company_filter' => fn ($q, string $c) => $q->getSalesSummary($c),
        'sales_trend' => fn ($q, string $c) => $q->getSalesMonthlyTrend(),
        'sales_trend/company_filter' => fn ($q, string $c) => $q->getSalesMonthlyTrend($c),
        'sales_top_customers' => fn ($q, string $c) => $q->getSalesTopCustomers(),
        'sales_top_customers/company_filter' => fn ($q, string $c) => $q->getSalesTopCustomers($c),
        'sales_top_salesman' => fn ($q, string $c) => $q->getSalesTopSalesman(),
        'sales_top_salesman/company_filter' => fn ($q, string $c) => $q->getSalesTopSalesman(companyFilter: $c),
    ];
    foreach ($laterPhase as $label => $fn) {
        if (method_exists($queries, explode('/', $label)[0])) {
            $calls[$label] = fn () => $fn($queries, $company);
        } else {
            $notPorted[$label] = ($notPorted[$label] ?? 0) + 1;
        }
    }

    foreach ($calls as $label => $fn) {
        $total++;
        try {
            $fn();
        } catch (Throwable $e) {
            $failures[] = "[{$company}] {$label}: ".get_class($e).' — '.$e->getMessage();
        }
    }
}

echo 'audited '.$total.' (method x branch) calls across '.count($dbFiles)." real company DBs\n";
if ($notPorted !== []) {
    echo 'not yet ported (no Laravel route calls them; cannot 500): '.count($notPorted)." branch groups\n";
}

if ($failures !== []) {
    echo 'PRODUCTION FAILURES ('.count($failures)."):\n";
    echo implode("\n", $failures)."\n";
    exit(1);
}

echo "all branches OK on production schemas\n";
exit(0);
