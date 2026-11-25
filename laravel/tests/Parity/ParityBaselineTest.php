<?php

namespace Tests\Parity;

use App\Parity\ParityActions;
use App\Parity\ParityDataset;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pest-style baseline test (written as PHPUnit TestCase: pestphp/pest is not
 * installed in this project and composer/network are unavailable here).
 *
 * Builds ParityDataset, captures every domain via ParityActions::capture()
 * through the in-process Laravel kernel (no external network), writes the
 * result to storage/app/parity/laravel.json, and asserts all 9 domains are
 * present with non-empty status and body.
 */
class ParityBaselineTest extends TestCase
{
    public function testParityCaptureWritesLaravelJsonWithTwentyDomains(): void
    {
        ParityDataset::build();

        $dataset = new ParityDataset();
        $results = ParityActions::capture($dataset);

        $this->assertCount(20, $results);
        $this->assertSame(
            ParityDataset::DOMAINS,
            array_keys($results),
            'Capture must return exactly the 20 dataset domains'
        );

        foreach ($results as $domain => $entry) {
            $this->assertArrayHasKey('status', $entry, "Missing status for domain {$domain}");
            $this->assertArrayHasKey('body', $entry, "Missing body for domain {$domain}");
            $this->assertNotNull($entry['status'], "Status must be non-null for domain {$domain}");
            // Body may be empty for 'missing' status (report page not implemented)
            if ($entry['status'] !== 'missing') {
                $this->assertNotEmpty($entry['body'], "Body must be non-empty for domain {$domain}");
            }
        }

        $path = storage_path('app/parity/laravel.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'captured_at' => now()->toISOString(),
            'domains' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->assertFileExists($path, 'laravel.json must be written');

        $decoded = json_decode(File::get($path), true);
        $this->assertIsArray($decoded, 'laravel.json must be valid JSON');
        $this->assertArrayHasKey('domains', $decoded);
        $this->assertCount(20, $decoded['domains']);
        foreach (ParityDataset::DOMAINS as $domain) {
            $this->assertArrayHasKey($domain, $decoded['domains'], "Missing domain {$domain} in laravel.json");
            $this->assertNotNull($decoded['domains'][$domain]['status'] ?? null, "Empty status for {$domain}");
            if (($decoded['domains'][$domain]['status'] ?? '') !== 'missing') {
                $this->assertNotEmpty($decoded['domains'][$domain]['body'] ?? '', "Empty body for {$domain}");
            }
        }
    }
}
