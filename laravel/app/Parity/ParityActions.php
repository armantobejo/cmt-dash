<?php

namespace App\Parity;

use Illuminate\Support\Facades\File;

class ParityActions
{
    public function capture(ParityDataset $dataset): array
    {
        $domains = $dataset->domains();
        $results = [];

        foreach ($domains as $domain) {
            if (!in_array($domain, ParityConstants::DOMAINS, true)) {
                $results[$domain] = [
                    'status' => null,
                    'headers' => [],
                    'body' => '',
                    'error' => 'unknown domain: ' . $domain,
                ];
                continue;
            }

            $path = storage_path('app/parity/' . $domain . '.json');
            if (!File::exists($path)) {
                $results[$domain] = [
                    'status' => 'missing',
                    'headers' => [],
                    'body' => '',
                    'error' => 'no capture fixture for ' . $domain,
                ];
                continue;
            }

            $content = File::get($path);
            $decoded = json_decode($content, true);

            $results[$domain] = [
                'status' => $decoded['status'] ?? 200,
                'headers' => $decoded['headers'] ?? [],
                'body' => $decoded['body'] ?? '',
                'error' => $decoded['error'] ?? null,
            ];
        }

        return $results;
    }

    public function runAll(): array
    {
        return $this->capture(new ParityDataset());
    }

    public static function mapToPythonDomains(array $results): array
    {
        $mapped = [];
        foreach ($results as $domain => $entry) {
            $mapped[$domain] = $entry;
        }
        return $mapped;
    }
}
