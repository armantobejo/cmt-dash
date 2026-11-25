<?php

namespace Tests\Parity\Suite;

/**
 * Baseline snapshot store for the parity suite.
 *
 * Layout (per task spec: snapshots live in `tests/snapshots/`):
 *   tests/snapshots/<file>.json          committed baseline (what CI compares against)
 *   tests/snapshots/.current/<file>.json current capture of the run that just happened
 *
 * A snapshot file is a flat map: case path -> payload. Both sides are written
 * with canonical JSON (keys sorted, no whitespace), so byte equality == semantic
 * equality and `tools/snapshot_diff.php` can diff plain files.
 *
 * Volatile values (timestamps, ids, tokens, durations) are stripped before
 * hashing so two consecutive runs are byte-identical.
 */
final class SnapshotStore
{
    public const SCHEMA_VERSION = '1.0.0';

    /** Keys whose values never survive into a snapshot (run-to-run noise). */
    public const VOLATILE_KEYS = [
        'captured_at', 'created_at', 'updated_at', 'request_id', 'duration_ms',
        'token', 'csrf_token', 'last_sync', 'age_seconds', 'expires_at',
        'time_ms', 'started_at', 'sync_time',
    ];

    public static function baselineDir(): string
    {
        return getenv('PARITY_SNAPSHOT_DIR') ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'snapshots';
    }

    public static function currentDir(): string
    {
        return self::baselineDir() . DIRECTORY_SEPARATOR . '.current';
    }

    /** Canonical JSON: recursively sorted keys, no insignificant whitespace. */
    public static function canonical(mixed $value): string
    {
        $value = self::normalize($value);
        $encoded = json_encode(
            self::sortKeys($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            throw new \RuntimeException('snapshot encode failed: ' . json_last_error_msg());
        }

        return $encoded;
    }

    /** Pretty form, for committed baselines humans read. */
    public static function pretty(mixed $value): string
    {
        $encoded = json_encode(
            self::sortKeys(self::normalize($value)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            throw new \RuntimeException('snapshot encode failed: ' . json_last_error_msg());
        }

        return $encoded . "\n";
    }

    /** Drop volatile keys recursively; round floats so tiny drift can't leak in. */
    public static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && in_array($k, self::VOLATILE_KEYS, true)) {
                    continue;
                }
                $out[$k] = self::normalize($v);
            }

            return $out;
        }

        if (is_float($value)) {
            return round($value, 4);
        }

        return $value;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortKeys($v);
        }
        if (! $isList) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    public static function baselinePath(string $file): string
    {
        return self::baselineDir() . DIRECTORY_SEPARATOR . $file . '.json';
    }

    public static function currentPath(string $file): string
    {
        return self::currentDir() . DIRECTORY_SEPARATOR . $file . '.json';
    }

    /** @return array<string, array> */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("invalid snapshot JSON: {$path}");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    public static function save(string $path, array $data): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new \RuntimeException("cannot create snapshot dir: {$dir}");
        }

        file_put_contents($path, self::pretty($data));
    }

    /**
     * Record one case: always writes the current capture, compares it with the
     * committed baseline, and rewrites the baseline when UPDATE_SNAPSHOTS=1.
     *
     * @param  array<string, mixed>  $payload
     * @return array{baseline: mixed, current: array, updated: bool}
     */
    public static function put(string $file, string $case, array $payload): array
    {
        $payload = self::normalize($payload);

        $current = self::load(self::currentPath($file));
        $current[$case] = $payload;
        self::save(self::currentPath($file), $current);

        $baselinePath = self::baselinePath($file);
        $baseline = self::load($baselinePath);

        $update = getenv('UPDATE_SNAPSHOTS') === '1';
        if ($update) {
            $baseline[$case] = $payload;
            self::save($baselinePath, $baseline);

            return ['baseline' => $payload, 'current' => $payload, 'updated' => true];
        }

        return [
            'baseline' => $baseline[$case] ?? null,
            'current'  => $payload,
            'updated'  => false,
        ];
    }

    /**
     * Structured diff between a baseline value and a current value.
     *
     * @return list<array{path: string, kind: string, baseline: string, current: string}>
     */
    public static function diff(mixed $a, mixed $b, string $path = ''): array
    {
        $out = [];
        self::walk($a, $b, $path === '' ? '/' : $path, $out);

        return $out;
    }

    /** @param list<array{path: string, kind: string, baseline: string, current: string}> $out */
    private static function walk(mixed $a, mixed $b, string $path, array &$out): void
    {
        if (gettype($a) !== gettype($b)) {
            $out[] = ['path' => $path, 'kind' => 'type', 'baseline' => self::short($a), 'current' => self::short($b)];

            return;
        }

        if (is_array($a)) {
            foreach (array_keys($a + $b) as $k) {
                $kp = $path . '/' . $k;
                if (! array_key_exists($k, $a)) {
                    $out[] = ['path' => $kp, 'kind' => 'missing_in_baseline', 'baseline' => '-', 'current' => self::short($b[$k])];
                } elseif (! array_key_exists($k, $b)) {
                    $out[] = ['path' => $kp, 'kind' => 'missing_in_current', 'baseline' => self::short($a[$k]), 'current' => '-'];
                } else {
                    self::walk($a[$k], $b[$k], $kp, $out);
                }
            }

            return;
        }

        if ($a !== $b) {
            $out[] = ['path' => $path, 'kind' => 'value', 'baseline' => self::short($a), 'current' => self::short($b)];
        }
    }

    private static function short(mixed $v, int $max = 120): string
    {
        $s = is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strlen($s) <= $max ? $s : substr($s, 0, $max - 3) . '...';
    }

    /** All baseline snapshot files (relative name, no extension). @return list<string> */
    public static function files(): array
    {
        $files = glob(self::baselineDir() . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $names = array_map(fn (string $f) => basename($f, '.json'), $files);
        sort($names);

        return $names;
    }

    /**
     * Diff one snapshot file: committed baseline vs the current capture.
     *
     * @return list<array{path: string, kind: string, baseline: string, current: string}>
     */
    public static function diffFile(string $file): array
    {
        $baseline = self::load(self::baselinePath($file));
        $current = self::load(self::currentPath($file));

        return self::diff($baseline, $current, $file);
    }
}
