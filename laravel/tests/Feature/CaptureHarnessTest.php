<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\CaptureHarness\Storage\DiskFrameStore;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Contract coverage for POST /api/capture/p5:
 *   payload validation -> 422, frame storage -> disk + run manifest,
 *   parity comparison incl. tolerance boundary, error paths
 *   (401 unauthorized, 429 rate_limited, 404 disabled, 500 storage).
 *
 * The middleware stack (web + auth + throttle) is bypassed by default so
 * each test hits the controller/service/storage contract directly. The
 * 401 and 429 tests deliberately run the real stack.
 *
 * Every parity mismatch assertion carries the measured
 * max_channel_delta / mae / match_ratio from the response. Those numbers,
 * plus run_id and the stored path, are the reproducible steps for a
 * mismatch: diff storage/app/private/<stored.path>/captures/<run_id>/0.json
 * against references/0.json.
 */
class CaptureHarnessTest extends TestCase
{
    private const SKETCH_ID = 'shipment-summary-chart';
    private string $base = 'parity/p5/' . self::SKETCH_ID;
    private string $lockedDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['capture-harness.enabled' => true]);
        Storage::disk('local')->deleteDirectory($this->base);
    }

    protected function tearDown(): void
    {
        if ($this->lockedDir !== '' && is_dir($this->lockedDir)) {
            @chmod($this->lockedDir, 0755);
        }
        parent::tearDown();
    }

    // ── Error paths ──────────────────────────────────────────────────

    public function testDisabledEndpointReturns404(): void
    {
        config(['capture-harness.enabled' => false]);
        $this->withoutMiddleware()
            ->postJson('/api/capture/p5', $this->basePayload())
            ->assertStatus(404)
            ->assertExactJson(['ok' => false, 'error' => 'disabled']);
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        // Runs the real middleware stack (web + auth). Anonymous session
        // throws AuthenticationException, rendered as the contract 401 shape.
        $this->postJson('/api/capture/p5', $this->basePayload())
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'unauthorized']);
    }

    public function testRateLimitedReturns429(): void
    {
        // throttle:60,1 runs after auth, so the client must be authenticated.
        $this->artisan('migrate');
        $user = User::create([
            'name' => 'Capture Tester',
            'email' => 'capture@example.com',
            'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);

        $payload = $this->basePayload();
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/capture/p5', $payload)->assertStatus(200);
        }
        $this->postJson('/api/capture/p5', $payload)
            ->assertStatus(429)
            ->assertExactJson(['ok' => false, 'error' => 'rate_limited']);
    }

    public function testMalformedJsonBodyReturns422(): void
    {
        $this->withoutMiddleware()->call('POST', '/api/capture/p5', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], 'not-json-at-all')
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'validation'])
            ->assertJsonPath('fields.0', 'sketch_id')
            ->assertJsonStructure(['message', 'fields']);
    }

    /** @dataProvider malformedPayloadProvider */
    public function testPayloadValidationRejectsMalformedInput(array $payload, string $field): void
    {
        $this->withoutMiddleware()
            ->postJson('/api/capture/p5', $payload)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'validation'])
            ->assertJsonPath('fields.0', $field)
            ->assertJsonStructure(['message', 'fields']);
    }

    public static function malformedPayloadProvider(): array
    {
        $base = self::basePayload();
        $shortPixels = base64_encode(str_repeat("\x00", 15));

        return [
            'missing sketch_id' => [array_replace($base, ['sketch_id' => null]), 'sketch_id'],
            'uppercase sketch_id' => [array_replace($base, ['sketch_id' => 'Bad_Sketch!']), 'sketch_id'],
            'sketch_id too long' => [array_replace($base, ['sketch_id' => str_repeat('x', 65)]), 'sketch_id'],
            'missing frame_index' => [array_replace($base, ['frame_index' => null]), 'frame_index'],
            'negative frame_index' => [array_replace($base, ['frame_index' => -1]), 'frame_index'],
            'frame_index >= frame_total' => [array_replace($base, ['frame_index' => 1, 'frame_total' => 1]), 'frame_index'],
            'missing frame_total' => [array_replace($base, ['frame_total' => null]), 'frame_total'],
            'zero frame_total' => [array_replace($base, ['frame_total' => 0]), 'frame_total'],
            'missing width' => [array_replace($base, ['width' => null]), 'width'],
            'width at upper bound +1' => [array_replace($base, ['width' => 4097]), 'width'],
            'zero width' => [array_replace($base, ['width' => 0]), 'width'],
            'zero height' => [array_replace($base, ['height' => 0]), 'width'],
            'pixels not base64' => [array_replace($base, ['pixels' => 'not-base64!!!']), 'pixels'],
            'pixels wrong length' => [array_replace($base, ['pixels' => $shortPixels]), 'pixels'],
            'sha256 not lowercase hex' => [array_replace($base, ['sha256' => 'not-hex']), 'payload'],
            'tolerance not numeric' => [array_replace($base, ['tolerance' => ['max_channel_delta' => 'fast']]), 'tolerance'],
        ];
    }

    public function testOversizedDimensionsAreRejected(): void
    {
        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload(), [
            'width' => 5000,
            'height' => 1,
            'pixels' => base64_encode(str_repeat("\x00", 20000)),
        ]))->assertStatus(422);
    }

    // ── Frame storage ────────────────────────────────────────────────

    public function testValidCaptureIsStoredToDisk(): void
    {
        $raw = base64_decode($this->basePayload()['pixels'], true);
        $payload = array_replace($this->basePayload(), ['sha256' => hash('sha256', $raw)]);

        $response = $this->withoutMiddleware()->postJson('/api/capture/p5', $payload);
        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'stored' => ['disk' => 'local', 'frames' => 1, 'bytes' => strlen($raw)],
                'parity' => ['reference_id' => null, 'overall' => null, 'frames' => [], 'skipped' => [0]],
            ])
            ->assertJsonStructure(['run_id']);

        $runId = $response->json('run_id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $runId);

        $capturePath = storage_path("app/private/{$this->base}/captures/{$runId}/0.json");
        $this->assertFileExists($capturePath);
        $this->assertFileExists(storage_path("app/private/{$this->base}/captures/{$runId}/run.json"));

        $stored = json_decode(file_get_contents($capturePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($payload['pixels'], $stored['pixels']);
        $this->assertSame(4, $stored['width']);
        $this->assertSame(4, $stored['height']);
        $this->assertSame($payload['sha256'], $stored['sha256']);
        $this->assertSame('browser', $stored['source']);
    }

    public function testRunManifestRecordsFrameCount(): void
    {
        $runId = $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload())->json('run_id');
        $manifest = json_decode(
            file_get_contents(storage_path("app/private/{$this->base}/captures/{$runId}/run.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(self::SKETCH_ID, $manifest['sketch_id']);
        $this->assertSame($runId, $manifest['run_id']);
        $this->assertSame(1, $manifest['frame_count']);
    }

    public function testEachCaptureGetsIndependentRunDirectory(): void
    {
        $runIds = [];
        foreach ([0, 1] as $index) {
            $runIds[] = $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload(), [
                'frame_index' => $index,
                'frame_total' => 2,
            ]))->json('run_id');
        }
        $this->assertNotSame($runIds[0], $runIds[1]);
        foreach ($runIds as $i => $runId) {
            $this->assertFileExists(storage_path("app/private/{$this->base}/captures/{$runId}/{$i}.json"));
        }
    }

    public function testReferenceFrameRoundTripsThroughDiskStore(): void
    {
        $pixels = $this->rgbaPixels(10, 200, 30, 16);
        $store = new DiskFrameStore();
        $path = $store->storeReferenceFrame(self::SKETCH_ID, 0, [
            'index' => 0, 'width' => 4, 'height' => 4,
            'pixels' => base64_encode($pixels),
            'captured_at' => time(), 'source' => 'reference',
        ]);
        $this->assertStringStartsWith("parity/p5/".self::SKETCH_ID."/references/0.json", $path);
        $this->assertSame(base64_encode($pixels), $store->getReferenceFrame(self::SKETCH_ID, 0)['pixels']);
        $this->assertNull($store->getReferenceFrame(self::SKETCH_ID, 9));
    }

    public function testStorageFailureReturns500(): void
    {
        $this->lockedDir = 'Z:/cmt-capture-locked';
        @mkdir($this->lockedDir, 0755, true);

        config([
            'capture-harness.storage_disk' => 'locked',
            'filesystems.disks.locked' => [
                'driver' => 'local',
                'root' => $this->lockedDir,
                'throw' => false,
                'report' => false,
            ],
        ]);

        $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload())
            ->assertStatus(500)
            ->assertJsonPath('message', 'Unable to create a directory at Z:/cmt-capture-locked.');
    }

    // ── Parity comparison ────────────────────────────────────────────

    public function testMissingReferenceReturnsNullParity(): void
    {
        $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload())
            ->assertStatus(200)
            ->assertJson([
                'parity' => ['reference_id' => null, 'overall' => null, 'frames' => [], 'skipped' => [0]],
            ]);
    }

    public function testIdenticalReferencePassesParity(): void
    {
        $pixels = $this->rgbaPixels(10, 200, 30, 16);
        $this->storeReference($pixels, 4, 4);

        $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($pixels),
        ]))->assertStatus(200)->assertJson([
            'parity' => [
                'reference_id' => null,
                'overall' => true,
                'frames' => [[
                    'index' => 0, 'pass' => true,
                    'max_channel_delta' => 0.0,
                    'mae' => 0.0,
                    'match_ratio' => 1.0,
                ]],
            ],
        ]);
    }

    public function testNearMatchWithinTolerancePasses(): void
    {
        $reference = $this->rgbaPixels(10, 200, 30, 16);
        $capture = $this->rgbaPixels(11, 201, 31, 16); // every channel off by 1
        $this->storeReference($reference, 4, 4);

        $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($capture),
        ]))->assertStatus(200)->assertJson([
            'parity' => ['overall' => true, 'frames' => [[
                'pass' => true,
                'max_channel_delta' => 1.0,
                'mae' => 0.75,
                'match_ratio' => 1.0,
            ]]],
        ]);
    }

    /** @dataProvider maxChannelDeltaBoundaryProvider */
    public function testMaxChannelDeltaBoundary(int $channelDelta, bool $expectedPass): void
    {
        $capture = str_repeat(pack('CCCC', 0, 0, 0, 255), 16);
        $reference = str_repeat(pack('CCCC', $channelDelta, 0, 0, 255), 16);
        $this->storeReference($reference, 4, 4);

        $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($capture),
        ]))->assertStatus(200)
          ->assertJsonPath('parity.overall', $expectedPass)
          ->assertJsonPath('parity.frames.0.max_channel_delta', $channelDelta);
    }

    public static function maxChannelDeltaBoundaryProvider(): array
    {
        return [
            'delta exactly at limit passes' => [4, true],
            'delta one over limit fails' => [5, false],
        ];
    }

    /** @dataProvider maeBoundaryProvider */
    public function testMaeBoundary(int $channelDelta, bool $expectedPass): void
    {
        // 4x4 = 16 pixels = 64 bytes; mae = (16 * $channelDelta) / 64.
        $capture = str_repeat(pack('CCCC', 0, 0, 0, 255), 16);
        $reference = str_repeat(pack('CCCC', $channelDelta, 0, 0, 255), 16);
        $this->storeReference($reference, 4, 4);

        $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($capture),
        ]))->assertStatus(200)->assertJsonPath('parity.overall', $expectedPass);
    }

    public static function maeBoundaryProvider(): array
    {
        return [
            'mae exactly at limit passes' => [4, true],   // mae == 1.0
            'mae over limit fails' => [5, false],          // mae == 1.25
        ];
    }

    /** @dataProvider matchRatioBoundaryProvider */
    public function testMatchRatioBoundary(int $differingPixels, bool $expectedPass): void
    {
        $pixelCount = 1000; // 250 x 4
        $pixels = str_repeat(pack('CCCC', 0, 0, 0, 255), $pixelCount);
        for ($i = 0; $i < $differingPixels; $i++) {
            $pixels[$i * 4] = chr(5); // one channel off by 5: exceeds delta-4 limit, pixel fails match
        }
        $reference = str_repeat(pack('CCCC', 0, 0, 0, 255), $pixelCount);
        $this->storeReference($reference, 250, 4);

        $maxDelta = $differingPixels ? 5 : 0;
        $ratio = $differingPixels ? 0.999 : 1.0;

        $this->withoutMiddleware()->postJson('/api/capture/p5', self::basePayload([
            'width' => 250, 'height' => 4, 'tolerance' => ['min_match_ratio' => 1.0],
            'pixels' => base64_encode($pixels),
        ]))->assertStatus(200)->assertJson([
            'parity' => ['overall' => $expectedPass, 'frames' => [[
                'pass' => $expectedPass,
                'max_channel_delta' => $maxDelta,
                'match_ratio' => $ratio,
            ]]],
        ]);
    }

    public static function matchRatioBoundaryProvider(): array
    {
        return [
            'identical frames ratio 1.0 passes 1.0 floor' => [0, true],
            'one differing pixel ratio 0.999 fails 1.0 floor' => [1, false],
        ];
    }

    public function testZeroToleranceRejectsAnyDelta(): void
    {
        $identical = str_repeat(pack('CCCC', 10, 200, 30, 255), 16);
        $offByOne = $identical;
        for ($i = 0; $i < strlen($offByOne); $i += 4) {
            $offByOne[$i] = chr(ord($offByOne[$i]) + 1);
        }
        $zeroTol = ['max_channel_delta' => 0, 'mae' => 0.0, 'min_match_ratio' => 1.0];
        $this->storeReference($identical, 4, 4);

        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($identical),
        ]), ['tolerance' => $zeroTol]))
            ->assertJsonPath('parity.overall', true);

        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($offByOne),
        ]), ['tolerance' => $zeroTol]))
            ->assertStatus(200)
            ->assertJsonPath('parity.overall', false)
            ->assertJsonPath('parity.frames.0.max_channel_delta', 1);
    }

    public function testDimensionMismatchFails(): void
    {
        $this->storeReference(str_repeat("\x00", 16), 2, 2); // 2x2 reference

        $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode(str_repeat("\x00", 64)),
        ]))->assertStatus(200)->assertJson([
            'parity' => ['overall' => false, 'frames' => [[
                'pass' => false,
                'max_channel_delta' => 255.0,
                'mae' => 255.0,
                'match_ratio' => 0.0,
            ]]],
        ]);
    }

    public function testMismatchCarriesReproducibleNumbers(): void
    {
        // Capture is 5 brighter on the blue channel than the reference:
        // every mismatch can be re-checked by diffing the two files on disk.
        $reference = $this->rgbaPixels(10, 200, 30, 16);
        $capture = $this->rgbaPixels(10, 200, 35, 16);
        $this->storeReference($reference, 4, 4);

        $response = $this->withoutMiddleware()->postJson('/api/capture/p5', $this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($capture),
        ]));
        $response->assertStatus(200)->assertJson([
            'parity' => ['overall' => false, 'frames' => [[
                'pass' => false,
                'max_channel_delta' => 5.0,
                'mae' => 1.25,
                'match_ratio' => 0.0,
            ]]],
        ]);
        $runId = $response->json('run_id');
        $this->assertFileExists(storage_path("app/private/{$this->base}/captures/{$runId}/0.json"));
        $this->assertFileExists(storage_path("app/private/{$this->base}/references/0.json"));
        // Reproducible steps: compare the two stored JSON files' `pixels`
        // fields; max_channel_delta=5.0, mae=1.25, match_ratio=0.0.
    }

    public function testTypedArrayFieldsReturn422Not500(): void
    {
        // Regression: non-scalar / non-int typed fields must surface as 422
        // (CaptureValidationException), not an uncaught TypeError -> 500.
        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload(), [
            'frame_index' => ['0'],
        ]))->assertStatus(422)->assertJson(['ok' => false, 'error' => 'validation']);

        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload(), [
            'frame_total' => 1.5,
        ]))->assertStatus(422);

        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload(), [
            'width' => ['4'],
        ]))->assertStatus(422);
    }

    public function testSha256IntegrityMismatchReturns422(): void
    {
        $raw = base64_decode($this->basePayload()['pixels'], true);
        $payload = array_replace($this->basePayload(), ['sha256' => hash('sha256', 'different-bytes')]);

        $this->withoutMiddleware()->postJson('/api/capture/p5', $payload)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'validation']);
    }

    /** @dataProvider outOfRangeToleranceProvider */
    public function testToleranceOutOfRangeReturns422(array $tolerance): void
    {
        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload(), [
            'tolerance' => $tolerance,
        ]))->assertStatus(422)->assertJson(['ok' => false, 'error' => 'validation']);
    }

    public static function outOfRangeToleranceProvider(): array
    {
        return [
            'max_channel_delta above 255' => [['max_channel_delta' => 256]],
            'max_channel_delta negative' => [['max_channel_delta' => -1]],
            'mae negative' => [['mae' => -0.5]],
            'min_match_ratio above 1' => [['min_match_ratio' => 1.5]],
        ];
    }

    public function testToleranceOverrideTightensMaxChannelDeltaAxis(): void
    {
        // Capture every channel off by 1; override max_channel_delta to 0 -> fail.
        $capture = str_repeat(pack('CCCC', 1, 200, 30, 255), 16);
        $this->storeReference(str_repeat(pack('CCCC', 0, 200, 30, 255), 16), 4, 4);

        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($capture),
        ]), ['tolerance' => ['max_channel_delta' => 0, 'mae' => 0.0, 'min_match_ratio' => 1.0]]))
            ->assertStatus(200)
            ->assertJsonPath('parity.overall', false);
    }

    public function testToleranceOverrideTightensMaeAxis(): void
    {
        // Actual mae is 0.75; override mae to 0.5 -> fail.
        $reference = $this->rgbaPixels(10, 200, 30, 16);
        $capture = $this->rgbaPixels(11, 201, 31, 16);
        $this->storeReference($reference, 4, 4);

        $this->withoutMiddleware()->postJson('/api/capture/p5', array_replace($this->basePayload([
            'width' => 4, 'height' => 4, 'pixels' => base64_encode($capture),
        ]), ['tolerance' => ['max_channel_delta' => 4, 'mae' => 0.5, 'min_match_ratio' => 1.0]]))
            ->assertStatus(200)
            ->assertJsonPath('parity.overall', false);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private static function basePayload(array $overrides = []): array
    {
        return array_replace([
            'sketch_id' => self::SKETCH_ID,
            'frame_index' => 0,
            'frame_total' => 1,
            'width' => 4,
            'height' => 4,
            'pixels' => base64_encode(str_repeat(pack('CCCC', 0, 0, 0, 255), 16)),
        ], $overrides);
    }

    private function rgbaPixels(int $r, int $g, int $b, int $count): string
    {
        return str_repeat(pack('CCCC', $r, $g, $b, 255), $count);
    }

    private function storeReference(string $pixels, int $w, int $h): void
    {
        (new DiskFrameStore())->storeReferenceFrame(self::SKETCH_ID, 0, [
            'index' => 0, 'width' => $w, 'height' => $h,
            'pixels' => base64_encode($pixels),
            'captured_at' => time(), 'source' => 'reference',
        ]);
    }
}
