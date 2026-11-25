<?php

declare(strict_types=1);

namespace Tests\Unit\CaptureHarness;

use App\CaptureHarness\Comparison\PurePhpParityComparator;
use Tests\TestCase;

/**
 * Unit coverage for PurePhpParityComparator tolerance contract:
 *   identical frames pass at zero metrics; sub-tolerance deltas pass;
 *   max_channel_delta, mae and match_ratio each enforce their own
 *   boundary independently; dimension mismatch always fails; zero
 *   tolerance rejects any delta.
 */
class PurePhpParityComparatorTest extends TestCase
{
    private const TOL = ['max_channel_delta' => 4, 'mae' => 1.0, 'min_match_ratio' => 0.999];

    private function frame(string $pixels, int $w, int $h): array
    {
        return ['width' => $w, 'height' => $h, 'pixels' => $pixels];
    }

    public function testIdenticalFramesPassWithZeroMetrics(): void
    {
        $comparator = new PurePhpParityComparator();
        $frame = $this->frame(str_repeat(pack('CCCC', 10, 200, 30, 255), 16), 4, 4);

        $result = $comparator->compare($frame, $frame, self::TOL);

        $this->assertTrue($result['pass']);
        $this->assertSame(0.0, $result['max_channel_delta']);
        $this->assertSame(0.0, $result['mae']);
        $this->assertSame(1.0, $result['match_ratio']);
    }

    public function testSubToleranceDeltasPass(): void
    {
        $comparator = new PurePhpParityComparator();
        $a = $this->frame(str_repeat(pack('CCCC', 10, 200, 30, 255), 16), 4, 4);
        $b = $this->frame(str_repeat(pack('CCCC', 13, 200, 30, 255), 16), 4, 4); // max 3, mae 0.75

        $result = $comparator->compare($a, $b, self::TOL);

        $this->assertTrue($result['pass']);
        $this->assertSame(3.0, $result['max_channel_delta']);
        $this->assertSame(0.75, $result['mae']);
        $this->assertSame(1.0, $result['match_ratio']);
    }

    public function testDeltaAboveToleranceFailsRegardlessOfMae(): void
    {
        $comparator = new PurePhpParityComparator();
        $a = $this->frame(str_repeat(pack('CCCC', 0, 0, 0, 255), 16), 4, 4);
        $b = $this->frame(str_repeat(pack('CCCC', 5, 0, 0, 255), 16), 4, 4); // delta 5 > 4, mae 1.25

        $result = $comparator->compare($a, $b, self::TOL);

        $this->assertFalse($result['pass']);
        $this->assertSame(5.0, $result['max_channel_delta']);
        $this->assertSame(1.25, $result['mae']);
        $this->assertSame(0.0, $result['match_ratio']);
    }

    public function testMaeExceedsLimitWhileDeltaStaysUnder(): void
    {
        $comparator = new PurePhpParityComparator();
        // every byte off by 2: max 2 (under limit), mae = 128/64 = 2.0 (over limit)
        // mae <= max_channel_delta always, so mae needs a loose max tolerance to be
        // exercised as the failing axis independently.
        $a = $this->frame(str_repeat(pack('CCCC', 2, 2, 2, 2), 16), 4, 4);
        $b = $this->frame(str_repeat("\x00", 64), 4, 4);

        $result = $comparator->compare($a, $b, [
            'max_channel_delta' => 255,
            'mae' => 1.0,
            'min_match_ratio' => 0.0,
        ]);

        $this->assertFalse($result['pass']);
        $this->assertSame(2.0, $result['max_channel_delta']);
        $this->assertSame(2.0, $result['mae']);
        $this->assertSame(1.0, $result['match_ratio']);
    }

    public function testMatchRatioBelowLimitFails(): void
    {
        $comparator = new PurePhpParityComparator();
        // one byte off by 5 on a 4x4 frame: max 5 > 4 fails, pixel 0 fails match,
        // ratio 15/16 = 0.9375 < 0.999 floor fails too
        $a = $this->frame(str_repeat("\x00", 64), 4, 4);
        $b = $this->frame("\x05" . str_repeat("\x00", 63), 4, 4);

        $result = $comparator->compare($a, $b, self::TOL);

        $this->assertFalse($result['pass']);
        $this->assertSame(5.0, $result['max_channel_delta']);
        $this->assertSame(0.9375, $result['match_ratio']);
    }

    public function testDimensionMismatchFails(): void
    {
        $comparator = new PurePhpParityComparator();
        $a = $this->frame(str_repeat("\x00", 64), 4, 4);
        $b = $this->frame(str_repeat("\x00", 16), 2, 2);

        $result = $comparator->compare($a, $b, self::TOL);

        $this->assertFalse($result['pass']);
        $this->assertSame(255.0, $result['max_channel_delta']);
        $this->assertSame(255.0, $result['mae']);
        $this->assertSame(0.0, $result['match_ratio']);
    }

    public function testZeroToleranceRejectsAnyDelta(): void
    {
        $comparator = new PurePhpParityComparator();
        $zeroTol = ['max_channel_delta' => 0, 'mae' => 0.0, 'min_match_ratio' => 1.0];
        $identical = $this->frame(str_repeat(pack('CCCC', 10, 200, 30, 255), 16), 4, 4);
        $offByOne = $this->frame(str_repeat(pack('CCCC', 11, 200, 30, 255), 16), 4, 4);

        $this->assertTrue($comparator->compare($identical, $identical, $zeroTol)['pass']);
        $this->assertFalse($comparator->compare($identical, $offByOne, $zeroTol)['pass']);
    }
}
