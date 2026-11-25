<?php

namespace Tests\Feature\Parity;

use App\Parity\ParityActions;
use App\Parity\ParityDataset;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ParityBaselineTest extends TestCase
{
    public function testParityCaptureWritesJsonWithAllDomains(): void
    {
        // Build dataset (requires app booted)
        ParityDataset::build();

        $actions = new ParityActions();
        $results = $actions->runAll();

        $this->assertNotEmpty($results);

        $grouped = ParityActions::mapToPythonDomains($results);

        // All Python domains must be present in output (even if 404 / 'missing')
        $expectedDomains = [
            'shipments', 'shipment_detail',
            'invoices', 'ledger_sales',
            'data_quality', 'auth',
            'rbac', 'dashboard', 'apis',
            'projects_jobs_summary', 'projects_jobs_detail',
            'ar_reports', 'ap_reports', 'finance_reports',
            'statement_of_account', 'listing_reference',
            'performance_tracker', 'miscellaneous_views',
            'ship_finance_ap_journalism', 'volume_lifting_gross_profit',
        ];

        foreach ($expectedDomains as $domain) {
            $this->assertArrayHasKey($domain, $grouped, "Missing domain: {$domain}");
            $this->assertNotEmpty($grouped[$domain], "Empty domain: {$domain}");
        }

        // Build final JSON
        $json = [
            'captured_at' => now()->toISOString(),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'domains' => $grouped,
            'summary' => [
                'total_cases' => count($results),
                'domains_covered' => array_keys($grouped),
                'status_counts' => collect($results)
                    ->groupBy('status')
                    ->map(fn ($v) => $v->count())
                    ->toArray(),
            ],
        ];

        // Write to storage/app/parity/laravel.json
        $path = storage_path('app/parity/laravel.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Verify file written and valid JSON
        $this->assertFileExists($path);

        $decoded = json_decode(File::get($path), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('domains', $decoded);
        foreach ($expectedDomains as $domain) {
            $this->assertArrayHasKey($domain, $decoded['domains']);
        }
    }
}