<?php

namespace App\CaptureHarness\Storage;

use Illuminate\Support\Facades\File;

class DiskFrameStore
{
    public function storeReferenceFrame(string $sketchId, int $frameIndex, array $frame): string
    {
        $dir = storage_path("app/private/parity/p5/{$sketchId}/references");
        File::ensureDirectoryExists($dir);

        $path = "{$dir}/{$frameIndex}.json";
        File::put($path, json_encode($frame, JSON_PRETTY_PRINT));

        return "parity/p5/{$sketchId}/references/{$frameIndex}.json";
    }

    public function getReferenceFrame(string $sketchId, int $frameIndex): ?array
    {
        $path = storage_path("app/private/parity/p5/{$sketchId}/references/{$frameIndex}.json");

        if (!File::exists($path)) {
            return null;
        }

        return json_decode(File::get($path), true);
    }
}
