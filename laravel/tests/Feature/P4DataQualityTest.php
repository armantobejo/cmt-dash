<?php

namespace Tests\Feature;

use App\Support\CmtAnomalies;
use App\Support\CmtMoney;
use App\Support\CompanyConnectionFactory;
use App\Support\CompanyDbManager;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P4: GL data-quality pages (Python /data-quality + /data-quality/detail).
 *
 * The seed uses the REAL production JE/JE_D column sets (the sync owns those
 * schemas — docs/decisions/invoice-container-schema-drift.md) and mirrors the
 * structure of the pinned Python regression (tests/test_data_quality_detail.py):
 * two genuine bad-date rows + three valid ones, so the detail page can never
 * again list every row when the rollup says 2.
 *
 * The rollup==detail consistency test encodes the audit harness's semantic
 * gate (tools/audit_production_queries.py) at the feature level.
 */
class P4DataQualityTest extends TestCase
{
    protected string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing/'.Str::random(10));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->dataDir = $dir;
        config([
            'cmt.data_dir' => $dir,
            'cmt.users_env' => 'root:root-pw:superadmin,admin1:admin-pw:admin,staff1:staff-pw:staff',
        ]);

        $factory = new CompanyConnectionFactory(
            resolver: $this->app->make(ConnectionResolverInterface::class),
            dataDir: $dir,
        );
        $this->app->instance(CompanyConnectionFactory::class, $factory);
        $this->app->instance(CompanyDbManager::class, new CompanyDbManager($dir, 'CMT'));

        $this->seedLedger();
        $this->app->make(\App\Support\CmtAuth::class)->seedFromEnv();
    }

    /** Company DB with the REAL JE/JE_D production column sets. */
    protected function seedLedger(): void
    {
        $pdo = new \PDO('sqlite:'.$this->dataDir.'/CMT.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE JE (
            rowid_ INTEGER PRIMARY KEY AUTOINCREMENT,
            UJE TEXT, REF_NO TEXT, TANGGAL TEXT, KETERANGAN TEXT, MODUL TEXT,
            TYPETRAN TEXT, TOT_DR REAL, TOT_CR REAL, THN TEXT, BLNO3 TEXT,
            OPR TEXT, PRINT INTEGER, PRINTDATE TEXT, ENTRY TEXT,
            PROSES INTEGER, POSTING INTEGER, READO INTEGER
        )');
        $pdo->exec('CREATE TABLE JE_D (
            rowid_ INTEGER PRIMARY KEY AUTOINCREMENT,
            MODUL TEXT, UJE TEXT, U_JE_D TEXT, KD_ACCOUNT TEXT, URAIAN TEXT,
            QTY REAL, PRICE REAL, JUMLAH REAL, TGLAWAL TEXT, RATEAWAL REAL,
            CURRAWAL TEXT, TANGGAL TEXT, EXRATE REAL, CURRENCY TEXT, DK TEXT,
            BARIS TEXT, URUT INTEGER, SOURCECODE TEXT, TRANSFER INTEGER, KASBANK TEXT
        )');

        // Two unbalanced headers (b1 badly, b2 subtly), two bad dates,
        // three valid headers covering every other balance state.
        $je = [
            ['b1', 'JE-001', '0007-04-17', 'FoxPro era junk date', 100.0, 90.0],   // unbalanced + bad date
            ['b2', 'JE-002', '1999-12-31', 'Pre-2000 voucher', 100.0, 99.99],      // unbalanced + bad date
            ['g1', 'JE-003', '2024-01-15', 'Balanced', 500.0, 500.0],
            ['g2', 'JE-004', '2024-02-20', 'Balanced', 250.5, 250.5],
            ['g3', 'JE-005', '2026-01-05', 'Balanced', 1.0, 1.0],
        ];
        foreach ($je as [$uje, $ref, $tgl, $ket, $dr, $cr]) {
            $pdo->prepare('INSERT INTO JE (UJE, REF_NO, TANGGAL, KETERANGAN, TOT_DR, TOT_CR) VALUES (?,?,?,?,?,?)')
                ->execute([$uje, $ref, $tgl, $ket, $dr, $cr]);
        }

        // Detail rows: two orphans, one bad DK, valid rows for the headers.
        $jed = [
            // orphan rows (UJE has no JE header)
            ['o1', 'orphan-acc', 'Orphan debit row', 100.0, 'RP', null, 'D'],
            ['o2', 'orphan-acc', 'Orphan USD row', 10.0, 'USD', 15800.0, 'K'],
            // bad DK value
            ['g1', 'acc-1', 'Weird flag row', 5.0, 'RP', null, 'X'],
            // valid detail rows under g1 (JUMLAH 50 + 150 = header 200)
            ['g1', 'acc-1', 'Debit part', 50.0, 'RP', null, 'D'],
            ['g1', 'acc-1', 'Credit part', 150.0, 'RP', null, 'K'],
        ];
        foreach ($jed as [$uje, $acct, $uraian, $jumlah, $cur, $exrate, $dk]) {
            $pdo->prepare('INSERT INTO JE_D (UJE, KD_ACCOUNT, URAIAN, JUMLAH, CURRENCY, EXRATE, DK) VALUES (?,?,?,?,?,?,?)')
                ->execute([$uje, $acct, $uraian, $jumlah, $cur, $exrate, $dk]);
        }
    }

    protected function anomalies(): CmtAnomalies
    {
        return $this->app->make(CmtAnomalies::class);
    }

    protected function actingAsRole(string $username, string $password): static
    {
        $token = $this->post('/login', ['username' => $username, 'password' => $password])
            ->getCookie('cmt_session', false)->getValue();
        $this->withUnencryptedCookie('cmt_session', $token);
        // English labels for the HTML assertions (default lang is 'id').
        $this->withUnencryptedCookie('cmt_lang', 'en');

        return $this;
    }

    // ── Role guards (F-07 parity: superadmin only) ───────────────────────

    public function test_data_quality_requires_superadmin(): void
    {
        $this->actingAsRole('admin1', 'admin-pw')->get('/data-quality')
            ->assertStatus(403)
            ->assertJson(['detail' => 'Requires role >= superadmin']);
        $this->actingAsRole('staff1', 'staff-pw')->get('/data-quality')
            ->assertStatus(403);
        $this->actingAsRole('root', 'root-pw')->get('/data-quality')
            ->assertOk();
    }

    public function test_detail_requires_superadmin(): void
    {
        $this->actingAsRole('admin1', 'admin-pw')->get('/data-quality/detail?type=unbalanced')
            ->assertStatus(403);
        $this->actingAsRole('root', 'root-pw')->get('/data-quality/detail?type=unbalanced')
            ->assertOk();
    }

    // ── Rollup page (get_gl_anomalies port) ──────────────────────────────

    public function test_rollup_counts_match_seed(): void
    {
        $page = $this->actingAsRole('root', 'root-pw')->get('/data-quality');
        $page->assertOk();

        // Active company is the CMT default.
        $this->assertStringContainsString('Data Quality', $page->getContent());
        $this->assertStringContainsString('&mdash; CMT', $page->getContent());
        $this->assertStringContainsString('Unbalanced', $page->getContent());
        $this->assertStringContainsString('Orphan', $page->getContent());
        $this->assertStringContainsString('Bad date', $page->getContent());
        $this->assertStringContainsString('Bad DK flag', $page->getContent());
    }

    public function test_rollup_service_counts_match_seed(): void
    {
        $rows = collect($this->anomalies()->rollup('CMT'))->keyBy('type');

        $this->assertSame(2, $rows['unbalanced']['count']);
        $this->assertSame(2, $rows['orphan']['count']);
        $this->assertSame(2, $rows['bad_date']['count']);
        $this->assertSame(1, $rows['bad_dk']['count']);
        // Sample fields come from the seeded rows; SQL MAX('JE-001','JE-002') = 'JE-002'.
        $this->assertSame('JE-002', $rows['unbalanced']['sample_ref']);
        $this->assertSame('1999-12-31', $rows['bad_date']['sample_date']);
        $this->assertSame('Weird flag row', $rows['bad_dk']['sample_desc']);
    }

    // ── Detail page (get_gl_anomaly_detail port) ─────────────────────────

    public function test_detail_returns_exactly_the_anomalous_rows(): void
    {
        $d = $this->anomalies()->detail('bad_date');

        // THE regression: rollup says 2 -> detail lists exactly 2, never the table.
        $this->assertSame(2, $d['total']);
        $this->assertCount(2, $d['rows']);
        $this->assertSame('0007-04-17', $d['rows'][0]['TANGGAL']); // ORDER BY TANGGAL
        $this->assertSame('1999-12-31', $d['rows'][1]['TANGGAL']);
        $this->assertSame(['UJE', 'TANGGAL', 'REF_NO', 'KETERANGAN', 'TOT_DR', 'TOT_CR', 'GAP'], $d['columns']);
        // Page footer: header totals summed as stored.
        $this->assertEqualsWithDelta(200.0, $d['sums']['dr'], 0.001);
        $this->assertEqualsWithDelta(189.99, $d['sums']['cr'], 0.001);
    }

    public function test_unbalanced_detail_and_gap_sums(): void
    {
        $d = $this->anomalies()->detail('unbalanced');

        $this->assertSame(2, $d['total']);
        // ORDER BY TANGGAL DESC: 2024/1999 rows... actual: 1999-12-31 & 0007-04-17
        $this->assertSame('1999-12-31', $d['rows'][0]['TANGGAL']);
        $this->assertSame('0007-04-17', $d['rows'][1]['TANGGAL']);
        // b1: 100-90=10; b2: 100-99.99=0.01
        $this->assertEqualsWithDelta(10.0, $d['rows'][1]['GAP'], 0.001);
        $this->assertEqualsWithDelta(0.01, $d['rows'][0]['GAP'], 0.001);
    }

    public function test_orphan_detail_converts_idr_footer(): void
    {
        $d = $this->anomalies()->detail('orphan');

        $this->assertSame(2, $d['total']);
        // 100 RP stays 100; 10 USD * 15800 = 158000 -> total 158100.
        $this->assertEqualsWithDelta(158100.0, $d['sums']['amount_idr'], 0.001);
        $this->assertEqualsWithDelta(100.0, $d['sums']['dr_idr'], 0.001);
        $this->assertEqualsWithDelta(158000.0, $d['sums']['cr_idr'], 0.001);
        $this->assertSame('orphan-acc', $d['rows'][0]['KD_ACCOUNT']);
    }

    public function test_bad_dk_detail_lists_only_the_bad_flag(): void
    {
        $d = $this->anomalies()->detail('bad_dk');

        $this->assertSame(1, $d['total']);
        $this->assertSame('X', $d['rows'][0]['DK']);
        $this->assertSame('Weird flag row', $d['rows'][0]['URAIAN']);
    }

    public function test_detail_paginates_with_window(): void
    {
        $d = $this->anomalies()->detail('orphan', page: 1, perPage: 1);

        $this->assertSame(2, $d['total']);
        $this->assertSame(2, $d['pages']);
        $this->assertSame(1, $d['page']);
        $this->assertCount(1, $d['rows']);

        $page = $this->actingAsRole('root', 'root-pw')->get('/data-quality/detail?type=bad_date&page=1');
        $page->assertOk();
        $this->assertStringContainsString('Back to Data Quality', $page->getContent());
    }

    public function test_invalid_type_redirects_303(): void
    {
        $this->actingAsRole('root', 'root-pw')
            ->get('/data-quality/detail?type=bogus')
            ->assertStatus(303)
            ->assertRedirect('/data-quality');
        // Service-level contract for an unknown type: the Python empty shape.
        $empty = $this->anomalies()->detail('bogus');
        $this->assertSame(0, $empty['total']);
        $this->assertSame([], $empty['rows']);
    }

    // ── The consistency rule (audit harness gate, feature level) ─────────

    public function test_rollup_count_equals_detail_total_for_every_type(): void
    {
        $rows = collect($this->anomalies()->rollup('CMT'))->keyBy('type');

        foreach (array_keys(CmtAnomalies::SPECS) as $type) {
            $rollup = $rows[$type]['count'] ?? 0;
            $detail = $this->anomalies()->detail($type);

            $this->assertSame(
                $rollup,
                $detail['total'],
                "rollup vs detail drifted for {$type} — count-SQL and data-SQL no longer share CmtAnomalies::SPECS",
            );
        }
    }

    // ── num formatting (Python _num_plain vectors) ───────────────────────

    public function test_num_matches_python_plain_number_format(): void
    {
        $this->assertSame('', CmtMoney::num(null));
        $this->assertSame('0', CmtMoney::num(0));
        $this->assertSame('1.234.567,89', CmtMoney::num(1234567.89));
        $this->assertSame('1.234.568', CmtMoney::num(1234567.9999));
        $this->assertSame('100', CmtMoney::num(100.0));
        $this->assertSame('0,01', CmtMoney::num(0.01));
        $this->assertSame('(10)', CmtMoney::num(-10.0));
        $this->assertSame('(1.234,5)', CmtMoney::num(-1234.50));
    }

    public function test_num_renders_in_detail_footer_and_cells(): void
    {
        $page = $this->actingAsRole('root', 'root-pw')
            ->get('/data-quality/detail?type=unbalanced');
        $page->assertOk();
        // Footer totals rendered symbol-free: 200 and 189,99.
        $this->assertStringContainsString('200', $page->getContent());
        $this->assertStringContainsString('189,99', $page->getContent());
    }
}
