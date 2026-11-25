<?php

namespace Tests\Parity\Suite;

/**
 * Acceptance gate for the snapshot layer itself (runs last by filename order):
 * every baseline file parses, is canonically encoded, and the run that just
 * finished produced byte-identical captures — zero variance.
 */
class ZzSnapshotIntegrityTest extends ParitySuiteTestCase
{
    /** Every parity area that must have a baseline file. */
    private const EXPECTED_FILES = [
        'auth-gates', 'controllers', 'eloquent', 'events', 'meta',
        'middleware', 'notifications', 'policies', 'routing', 'validation',
    ];

    public function test_every_expected_baseline_file_exists(): void
    {
        $files = SnapshotStore::files();

        foreach (self::EXPECTED_FILES as $expected) {
            $this->assertContains($expected, $files, "missing baseline snapshot tests/snapshots/{$expected}.json");
        }
    }

    public function test_baseline_files_are_canonically_encoded(): void
    {
        foreach (SnapshotStore::files() as $file) {
            $path = SnapshotStore::baselinePath($file);
            $data = SnapshotStore::load($path);

            $this->assertNotEmpty($data, "{$file} snapshot is empty");
            $this->assertSame(
                SnapshotStore::pretty($data),
                (string) file_get_contents($path),
                "{$file}.json is not canonically encoded — regenerate with UPDATE_SNAPSHOTS=1"
            );
        }
    }

    public function test_baseline_case_count_is_at_least_fifty(): void
    {
        $cases = 0;
        $perFile = [];
        foreach (SnapshotStore::files() as $file) {
            $perFile[$file] = count(SnapshotStore::load(SnapshotStore::baselinePath($file)));
            $cases += $perFile[$file];
        }

        $this->assertGreaterThanOrEqual(50, $cases, 'parity suite must pin at least 50 snapshot cases');
        $this->snapshotCase('meta', 'snapshot_counts', $perFile);
    }

    public function test_current_run_covers_every_baseline_case(): void
    {
        foreach (SnapshotStore::files() as $file) {
            $baseline = SnapshotStore::load(SnapshotStore::baselinePath($file));
            $current = SnapshotStore::load(SnapshotStore::currentPath($file));

            $missing = array_diff(array_keys($baseline), array_keys($current));
            $this->assertSame([], array_values($missing), "{$file}: baseline cases not captured by this run");
        }
    }

    public function test_zero_variance_between_baseline_and_current_run(): void
    {
        $diffs = [];
        foreach (SnapshotStore::files() as $file) {
            foreach (SnapshotStore::diffFile($file) as $diff) {
                $diffs[] = $diff;
            }
        }

        $this->assertSame(
            [],
            array_slice($diffs, 0, 10),
            count($diffs) . ' snapshot variance(s) between tests/snapshots and this run'
        );
    }
}
