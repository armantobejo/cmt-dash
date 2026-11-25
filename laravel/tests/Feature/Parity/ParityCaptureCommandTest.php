<?php

namespace Tests\Feature\Parity;

use Illuminate\Support\Facades\File;
use App\Parity\ParityConstants;
use Tests\TestCase;

class ParityCaptureCommandTest extends TestCase
{
    private string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStoragePath = storage_path('framework/testing/parity-capture-' . uniqid());
        $this->app->useStoragePath($this->testStoragePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testStoragePath);
        parent::tearDown();
    }

    public function test_command_writes_valid_json_with_all_nine_populated_domains(): void
    {
        $this->artisan('parity:capture')->assertExitCode(0);

        $path = storage_path('app/parity/laravel.json');
        $this->assertFileExists($path);

        $payload = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload['domains'] ?? null);
        $this->assertCount(20, $payload['domains']);

        foreach (ParityConstants::DOMAINS as $domain) {
            $this->assertArrayHasKey($domain, $payload['domains']);
            $this->assertIsArray($payload['domains'][$domain]);
            $this->assertNotEmpty($payload['domains'][$domain], "Domain {$domain} must contain captures");
        }
    }
}
