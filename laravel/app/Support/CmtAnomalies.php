<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Port of Python queries.get_gl_anomalies / get_gl_anomaly_detail — GL
 * data-quality anomalies (unbalanced / orphan / bad_date / bad_dk).
 *
 * COUNT/DATA PAIR POLICY (the bad_date GLOB lesson): the rollup and the
 * detail page MUST compose every count and data query from the ONE shared
 * self::SPECS table below — never from independent string literals. The
 * original defect shipped because count-SQL and data-SQL drifted while each
 * half worked in isolation (rollup said 43, detail listed every row); the
 * production audit (laravel/tools/audit_production_queries.php) reconciles
 * rollup count vs detail total for every type on all five real DBs.
 *
 * Unlike CmtQueries (which binds to the request-scoped active DB), anomaly
 * scans are full-table and run inside the superadmin-only pages against a
 * SPECIFIC company file — so the PDO connection is bound per instance
 * (fileBound()), keeping the query methods pure functions of one DB file
 * exactly like the Python originals (which take db_path).
 */
class CmtAnomalies
{
    /**
     * Single source of truth for every anomaly's SQL condition — the verbatim
     * port of Python queries._GL_ANOMALY_SPECS. `from` + `cond` build both the
     * rollup's and the detail's WHERE clauses; `select`/`cols`/`order` are
     * detail-page specifics. Any condition change happens here, once.
     */
    public const SPECS = [
        'unbalanced' => [
            'from' => 'JE',
            'cond' => 'ABS(TOT_DR - TOT_CR) > 0.01',
            'cols' => ['UJE', 'TANGGAL', 'REF_NO', 'KETERANGAN', 'TOT_DR', 'TOT_CR', 'GAP'],
            'select' => 'UJE, TANGGAL, REF_NO, KETERANGAN, TOT_DR, TOT_CR, TOT_DR - TOT_CR AS GAP',
            'order' => 'TANGGAL DESC',
        ],
        'orphan' => [
            'from' => 'JE_D d LEFT JOIN (SELECT DISTINCT UJE FROM JE) j ON d.UJE = j.UJE',
            'cond' => 'j.UJE IS NULL',
            'cols' => ['UJE', 'KD_ACCOUNT', 'JUMLAH', 'CURRENCY', 'EXRATE', 'DK', 'URAIAN'],
            'select' => 'd.UJE, d.KD_ACCOUNT, d.JUMLAH, d.CURRENCY, d.EXRATE, d.DK, d.URAIAN',
            'order' => 'd.UJE',
        ],
        'bad_date' => [
            'from' => 'JE',
            'cond' => "TANGGAL IS NOT NULL AND (CAST(strftime('%Y', TANGGAL) AS INTEGER) < 2000 "
                ."OR TANGGAL NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]')",
            'cols' => ['UJE', 'TANGGAL', 'REF_NO', 'KETERANGAN', 'TOT_DR', 'TOT_CR', 'GAP'],
            'select' => 'UJE, TANGGAL, REF_NO, KETERANGAN, TOT_DR, TOT_CR, TOT_DR - TOT_CR AS GAP',
            'order' => 'TANGGAL',
        ],
        'bad_dk' => [
            'from' => 'JE_D',
            'cond' => "DK NOT IN ('D', 'K') AND DK IS NOT NULL",
            'cols' => ['UJE', 'KD_ACCOUNT', 'JUMLAH', 'CURRENCY', 'EXRATE', 'DK', 'URAIAN'],
            'select' => 'UJE, KD_ACCOUNT, JUMLAH, CURRENCY, EXRATE, DK, URAIAN',
            'order' => 'UJE',
        ],
    ];

    /** Badge color per type — Python template_helpers.badge_class. */
    public const BADGE_CLASS = [
        'unbalanced' => 'warning',
        'orphan' => 'danger',
        'bad_date' => 'info',
        'bad_dk' => 'secondary',
    ];

    /** Translatable label key per type — Python template_helpers.anomaly_label. */
    public const LABEL = [
        'unbalanced' => 'Unbalanced',
        'orphan' => 'Orphan',
        'bad_date' => 'Bad date',
        'bad_dk' => 'Bad DK flag',
    ];

    /**
     * Instance bound to ONE company DB file. The default resolves the
     * request's active company from the manager (the HTTP pages' behavior);
     * fileBound() rebinds for audit/test/drive-by-DB use.
     */
    public function __construct(
        protected CompanyDbManager $manager,
        protected CompanyConnectionFactory $connections,
        protected ?string $dbPath = null,
    ) {
    }

    /** A clone pointed at a specific company DB file (tests, audit tool). */
    public function fileBound(string $dbPath): static
    {
        return new static($this->manager, $this->connections, $dbPath);
    }

    protected function pdo(): \PDO
    {
        if ($this->dbPath !== null) {
            $pdo = new \PDO('sqlite:'.$this->dbPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return $pdo;
        }

        // HTTP pages: the active company DB through the factory's registered
        // cmt_* connection (lazy, on-demand — same semantics as CmtQueries).
        return DB::connection($this->connections->connectionName($this->manager->active()))
            ->getPdo();
    }

    /**
     * Python get_gl_anomalies for ONE company DB (the page scopes to the
     * active company; Python's per-DB cache is a FastAPI-process concern and
     * is not ported — the Laravel pages run per-request).
     *
     * Returns rows: company, type, count, dr, cr, sample_ref, sample_date,
     * sample_desc, accounts (orphan) / account, amount (bad_dk).
     */
    public function rollup(string $company): array
    {
        $pdo = $this->pdo();
        $rows = [];

        try {
            $u = $this->one($pdo, 'unbalanced', 'COUNT(*) AS c, '
                ."COALESCE(SUM(TOT_DR), 0) AS dr, COALESCE(SUM(TOT_CR), 0) AS cr, "
                ."COALESCE(MAX(REF_NO), '') AS ref, COALESCE(MAX(TANGGAL), '') AS dt");
            if ($u !== null && (int) $u->c !== 0) {
                $rows[] = [
                    'company' => $company, 'type' => 'unbalanced',
                    'count' => (int) $u->c, 'dr' => (float) $u->dr, 'cr' => (float) $u->cr,
                    'sample_ref' => (string) $u->ref, 'sample_date' => (string) $u->dt,
                ];
            }

            $o = $this->one($pdo, 'orphan', 'COUNT(*) AS c, '
                ."COALESCE(SUM(JUMLAH), 0) AS amt, "
                ."COALESCE(GROUP_CONCAT(DISTINCT KD_ACCOUNT), '') AS accts, "
                ."COALESCE(MAX(URAIAN), '') AS sample_desc");
            if ($o !== null && (int) $o->c !== 0) {
                $rows[] = [
                    'company' => $company, 'type' => 'orphan',
                    'count' => (int) $o->c, 'dr' => 0, 'cr' => 0,
                    'sample_ref' => '', 'sample_date' => '',
                    'sample_desc' => (string) $o->sample_desc, 'accounts' => (string) $o->accts,
                ];
            }

            $b = $this->one($pdo, 'bad_date', 'COUNT(*) AS c, '
                ."COALESCE(MAX(REF_NO), '') AS ref, COALESCE(MAX(TANGGAL), '') AS dt, "
                ."COALESCE(MAX(KETERANGAN), '') AS sample_desc, "
                .'COALESCE(SUM(TOT_DR), 0) AS dr, COALESCE(SUM(TOT_CR), 0) AS cr');
            if ($b !== null && (int) $b->c !== 0) {
                $rows[] = [
                    'company' => $company, 'type' => 'bad_date',
                    'count' => (int) $b->c, 'dr' => (float) $b->dr, 'cr' => (float) $b->cr,
                    'sample_ref' => (string) $b->ref, 'sample_date' => (string) $b->dt,
                    'sample_desc' => (string) $b->sample_desc,
                ];
            }

            $dk = $this->one($pdo, 'bad_dk', 'COUNT(*) AS c, '
                ."COALESCE(MAX(URAIAN), '') AS sample_desc, "
                ."COALESCE(MAX(KD_ACCOUNT), '') AS acct, COALESCE(MAX(JUMLAH), 0) AS amt");
            if ($dk !== null && (int) $dk->c !== 0) {
                $rows[] = [
                    'company' => $company, 'type' => 'bad_dk',
                    'count' => (int) $dk->c, 'dr' => 0, 'cr' => 0,
                    'sample_ref' => '', 'sample_date' => '',
                    'sample_desc' => (string) $dk->sample_desc,
                    'account' => (string) $dk->acct, 'amount' => (float) $dk->amt,
                ];
            }
        } catch (\Throwable) {
            return []; // JE/JE_D missing in this DB — Python renders zero rows
        }

        return $rows;
    }

    /**
     * Python get_gl_anomaly_detail — paginated rows for one anomaly type.
     * Count and data compose the SAME self::SPECS fragments, so the rollup
     * and detail totals can never disagree (see the class docblock).
     */
    public function detail(string $type, int $page = 1, int $perPage = 100): array
    {
        $empty = ['type' => $type, 'company' => '', 'columns' => [], 'rows' => [],
            'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1, 'sums' => []];

        $spec = self::SPECS[$type] ?? null;
        if ($spec === null) {
            return $empty;
        }

        $page = max($page, 1);
        $perPage = max($perPage, 1);

        $countSql = 'SELECT COUNT(*) AS c FROM '.$spec['from'].' WHERE '.$spec['cond'];
        $dataSql = 'SELECT '.$spec['select'].' FROM '.$spec['from'].' WHERE '.$spec['cond']
            .' ORDER BY '.$spec['order'].' LIMIT ? OFFSET ?';

        try {
            $pdo = $this->pdo();
            $total = (int) $this->scalar($pdo, $countSql);
        } catch (\Throwable) {
            return $empty; // JE/JE_D missing in this DB
        }

        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $stmt = $pdo->prepare($dataSql);
        $stmt->execute([$perPage, ($page - 1) * $perPage]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[] = array_combine($spec['cols'], array_values($r));
        }

        // Footer auto-sums (Python detail function, same rules):
        // JE header totals (TOT_DR/TOT_CR) are summed as stored — one book
        // currency; JUMLAH rows are mixed-currency and convert to IDR via
        // EXRATE (same rule as get_account_detail).
        $sums = [];
        if ($type === 'unbalanced' || $type === 'bad_date') {
            $dr = 0.0;
            $cr = 0.0;
            foreach ($rows as $r) {
                $dr += (float) ($r['TOT_DR'] ?? 0);
                $cr += (float) ($r['TOT_CR'] ?? 0);
            }
            $sums = ['dr' => $dr, 'cr' => $cr, 'gap' => $dr - $cr];
        } elseif ($type === 'orphan' || $type === 'bad_dk') {
            $amountIdr = 0.0;
            $drIdr = 0.0;
            foreach ($rows as $r) {
                $amt = (float) ($r['JUMLAH'] ?? 0);
                $cur = strtoupper(trim((string) ($r['CURRENCY'] ?? '')));
                $isRp = in_array($cur, ['RP', 'IDR', 'RP.'], true);
                $idr = $isRp ? $amt : $amt * (float) ($r['EXRATE'] ?? 0);
                $amountIdr += $idr;
                if (($r['DK'] ?? '') === 'D') {
                    $drIdr += $idr;
                }
            }
            $sums = ['amount_idr' => $amountIdr, 'dr_idr' => $drIdr,
                'cr_idr' => $amountIdr - $drIdr];
        }

        $company = '';
        try {
            $company = strtoupper(pathinfo($this->dbPath ?? $this->manager->path(), PATHINFO_FILENAME));
        } catch (\Throwable) {
        }

        return ['type' => $type, 'company' => $company, 'columns' => $spec['cols'],
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'per_page' => $perPage, 'pages' => $pages, 'sums' => $sums];
    }

    /** One rollup aggregate row composed from the shared spec (null when the scan matches nothing). */
    protected function one(\PDO $pdo, string $type, string $aggregates): ?object
    {
        $spec = self::SPECS[$type];

        return $pdo->query('SELECT '.$aggregates.' FROM '.$spec['from'].' WHERE '.$spec['cond'])
            ->fetchObject() ?: null;
    }

    protected function scalar(\PDO $pdo, string $sql): int
    {
        return (int) $pdo->query($sql)->fetchColumn();
    }
}
