<?php

namespace Tests\Unit\Parity;

use App\Parity\ParityActions;
use App\Parity\ParityConstants;
use App\Parity\ParityDataset;
use Tests\TestCase;

/**
 * Unit tests for ParityActions::capture() response capture.
 * Mirrors Python tests/parity/parity_actions.py capture helpers.
 */
class ParityActionsTest extends TestCase
{
    public function testCaptureReturnsEntryPerDomainWithStatusHeadersBody(): void
    {
        $dataset = new ParityDataset(); // defaults: 20 domains matching Python baseline
        $actions = new ParityActions();

        $results = $actions->capture($dataset);

        $this->assertIsArray($results);
        $this->assertCount(20, $results);
        $this->assertSame(
            ParityConstants::DOMAINS,
            array_keys($results),
        );

        foreach ($results as $domain => $entry) {
            $this->assertIsArray($entry, "Entry for {$domain} must be an array");
            $this->assertArrayHasKey('status', $entry);
            $this->assertArrayHasKey('headers', $entry);
            $this->assertArrayHasKey('body', $entry);

            // Non-empty status (int for working pages, 'missing' string for 404 report pages)
            $this->assertNotNull($entry['status'], "Status must be non-null for {$domain}");
            $this->assertTrue(
                is_int($entry['status']) || $entry['status'] === 'missing',
                "Status must be int or 'missing' for {$domain}, got: " . gettype($entry['status'])
            );

            // Non-empty headers for working pages (missing pages may have empty headers)
            $this->assertIsArray($entry['headers']);
            if ($entry['status'] !== 'missing') {
                $this->assertNotEmpty($entry['headers'], "Headers must be non-empty for {$domain}");
            }

            // Non-empty body for working pages
            $this->assertIsString($entry['body']);
            if ($entry['status'] !== 'missing') {
                $this->assertNotEmpty($entry['body'], "Body must be non-empty for {$domain}");
            }

            // Error key present (null on success; may be non-null for missing/unimplemented pages)
            $this->assertArrayHasKey('error', $entry);
            if ($entry['status'] !== 'missing') {
                $this->assertNull($entry['error'], "Error must be null for working pages");
            } else {
                $this->assertNotNull($entry['error'], "Missing pages should have error message");
            }
        }
    }

    public function testUnknownDomainRecordsErrorMarkerAndDoesNotCrash(): void
    {
        $dataset = new ParityDataset(['auth', 'nope', 'apis']);
        $actions = new ParityActions();

        $results = $actions->capture($dataset);

        $this->assertIsArray($results);
        $this->assertCount(3, $results);

        // Known domains still capture normally
        $this->assertIsInt($results['auth']['status']);
        $this->assertNotEmpty($results['auth']['headers']);
        $this->assertNotEmpty($results['auth']['body']);
        $this->assertNull($results['auth']['error']);

        $this->assertIsInt($results['apis']['status']);
        $this->assertNull($results['apis']['error']);

        // Unknown domain: error marker, no crash
        $this->assertNull($results['nope']['status']);
        $this->assertEmpty($results['nope']['headers']);
        $this->assertSame('', $results['nope']['body']);
        $this->assertNotNull($results['nope']['error']);
        $this->assertStringContainsString('unknown domain', $results['nope']['error']);
    }

    public function testEmptyDatasetReturnsEmptyArray(): void
    {
        $dataset = new ParityDataset([]);
        $actions = new ParityActions();

        $results = $actions->capture($dataset);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }
}
