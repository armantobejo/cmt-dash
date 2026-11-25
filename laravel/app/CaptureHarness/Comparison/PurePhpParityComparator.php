<?php

namespace App\CaptureHarness\Comparison;

class PurePhpParityComparator
{
    public function compare(array $frameA, array $frameB, array $tolerance): array
    {
        $widthA = $frameA['width'] ?? 0;
        $heightA = $frameA['height'] ?? 0;
        $widthB = $frameB['width'] ?? 0;
        $heightB = $frameB['height'] ?? 0;

        if ($widthA !== $widthB || $heightA !== $heightB) {
            return [
                'pass' => false,
                'max_channel_delta' => 255.0,
                'mae' => 255.0,
                'match_ratio' => 0.0,
            ];
        }

        $pixelsA = $frameA['pixels'] ?? '';
        $pixelsB = $frameB['pixels'] ?? '';

        $lenA = strlen($pixelsA);
        $lenB = strlen($pixelsB);
        $len = min($lenA, $lenB);

        $maxDelta = 0;
        $totalDelta = 0;
        $matches = 0;

        for ($i = 0; $i < $len; $i += 4) {
            $rA = ord($pixelsA[$i] ?? "\x00");
            $gA = ord($pixelsA[$i + 1] ?? "\x00");
            $bA = ord($pixelsA[$i + 2] ?? "\x00");
            $aA = ord($pixelsA[$i + 3] ?? "\x00");

            $rB = ord($pixelsB[$i] ?? "\x00");
            $gB = ord($pixelsB[$i + 1] ?? "\x00");
            $bB = ord($pixelsB[$i + 2] ?? "\x00");
            $aB = ord($pixelsB[$i + 3] ?? "\x00");

            $delta = max(abs($rA - $rB), abs($gA - $gB), abs($bA - $bB), abs($aA - $aB));
            $maxDelta = max($maxDelta, $delta);
            $totalDelta += $delta;
            if ($delta === 0) {
                $matches++;
            }
        }

        $pixelCount = $len / 4;
        $mae = $pixelCount > 0 ? $totalDelta / $pixelCount : 0.0;
        $matchRatio = $pixelCount > 0 ? $matches / $pixelCount : 1.0;

        $pass = $maxDelta <= $tolerance['max_channel_delta']
            && $mae <= $tolerance['mae']
            && $matchRatio >= $tolerance['min_match_ratio'];

        return [
            'pass' => $pass,
            'max_channel_delta' => (float) $maxDelta,
            'mae' => (float) $mae,
            'match_ratio' => (float) $matchRatio,
        ];
    }
}
