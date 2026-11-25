<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use DateTime;

/**
 * Port of Python queries.py — dashboard, shipment, and invoice queries, on the
 * ACTIVE company DB. SQL is the same (the SQLite files are shared), so row
 * content and ordering match by construction; only the parameter binding
 * style differs (positional ? everywhere, like Python).
 *
 * Company scoping: case-insensitive partial match on CONSIGNEE.NAME (c/co) and
 * SHIPPER.NAME (s/sh), exactly like Python queries._company_filter.
 *
 * COUNT/DATA PAIR POLICY: when porting a paginated list or a rollup+detail
 * pair (e.g. GL data-quality), count and data queries MUST compose their
 * WHERE/JOIN fragments from one shared variable or spec table — never two
 * independently maintained string literals. The bad_date GLOB defect shipped
 * because count-SQL and data-SQL drifted while each half worked; the Python
 * source now enforces this structurally (queries._GL_ANOMALY_SPECS) and the
 * production audit (tools/audit_production_queries.py) reconciles rollup vs
 * detail counts on all real DBs. Match that construction here.
 */
class CmtQueries
{
    public function __construct(
        protected CompanyConnectionFactory $connections,
        protected CompanyDbManager $manager,
    ) {
    }

    /** Query builder on the active company DB. */
    protected function q()
    {
        return $this->connections->query($this->manager, 'x');
    }

    /** Single scalar/row (stdClass|null) — Python queries.query_one. */
    public function queryOne(string $sql, array $params = []): ?object
    {
        $row = DB::connection($this->connections->connectionName($this->manager->active()))
            ->selectOne($sql, $params);

        return $row ?: null;
    }

    /** Python queries._company_filter — case-insensitive partial company match. */
    public static function companyFilter(string $companyFilter, string $a = 'co', string $b = 'sh'): array
    {
        if ($companyFilter === '') {
            return ['', []];
        }
        $like = '%'.mb_strtoupper($companyFilter).'%';

        return [
            "(UPPER(COALESCE({$a}.NAME,'')) LIKE ? OR UPPER(COALESCE({$b}.NAME,'')) LIKE ?)",
            [$like, $like],
        ];
    }

    /** Python queries.table_count. */
    protected function tableCount(string $table): int
    {
        return (int) $this->queryOne("SELECT COUNT(*) AS c FROM {$table}")->c;
    }

    // ── Dashboard (Python queries.get_summary) ──────────────────────────

    public function getSummary(string $companyFilter = ''): array
    {
        if ($companyFilter !== '') {
            [$companySql, $companyParams] = self::companyFilter($companyFilter, 'c', 's');
            [$invCond, $invParams] = self::companyFilter($companyFilter, 'co', 'sh');
            $join = 'FROM UnifiedShipment u '
                .'LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG '
                .'LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER ';
            $shipmentCount = (int) ($this->queryOne(
                "SELECT COUNT(*) AS c {$join}WHERE u.DATE IS NOT NULL AND u.DATE != '' AND {$companySql}",
                $companyParams,
            )->c ?? 0);
            $importCount = (int) ($this->queryOne(
                "SELECT COUNT(*) AS c {$join}WHERE u.TYPETRAN = 'I' AND u.DATE IS NOT NULL AND u.DATE != '' AND {$companySql}",
                $companyParams,
            )->c ?? 0);
            $exportCount = (int) ($this->queryOne(
                "SELECT COUNT(*) AS c {$join}WHERE u.TYPETRAN != 'I' AND u.DATE IS NOT NULL AND u.DATE != '' AND {$companySql}",
                $companyParams,
            )->c ?? 0);
            $invoiceCount = (int) ($this->queryOne(
                'SELECT COUNT(*) AS c FROM INVOICE i '
                .'LEFT JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN '
                .'LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG '
                .'LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER '
                ."WHERE {$invCond}",
                $invParams,
            )->c ?? 0);
        } else {
            $shipmentCount = $this->tableCount('UnifiedShipment');
            $importCount = (int) ($this->queryOne("SELECT COUNT(*) as c FROM UnifiedShipment WHERE TYPETRAN = 'I'")->c ?? 0);
            $exportCount = (int) ($this->queryOne("SELECT COUNT(*) as c FROM UnifiedShipment WHERE TYPETRAN != 'I'")->c ?? 0);
            $invoiceCount = $this->tableCount('INVOICE');
        }

        return [
            'shipments' => $shipmentCount,
            'imports' => $importCount,
            'exports' => $exportCount,
            'invoices' => $invoiceCount,
            'voyages' => $this->tableCount('VESSEL'),
            'recent_shipments' => $this->getRecentShipments(companyFilter: $companyFilter),
        ];
    }

    // ── Shared shipment join/select (Python _SHIPMENT_JOINS/_SELECT) ────

    public const SHIPMENT_JOINS = <<<'SQL'
        FROM UnifiedShipment u
        LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG
        LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER
        LEFT JOIN (SELECT REFSHITMEN, SUM(GRAND_USD) AS GRAND_USD, SUM(GRAND_RP) AS GRAND_RP
                   FROM OPRCOST GROUP BY REFSHITMEN) oc ON oc.REFSHITMEN = u.REFSHITMEN
        LEFT JOIN (SELECT REFSHITMEN, SUM(GRAND_USD) AS GRAND_USD, SUM(GRAND_RP) AS GRAND_RP,
                          MIN(LUNAS) AS LUNAS,
                          GROUP_CONCAT(DISTINCT NOINVOICE) AS NOINVOICE,
                          SUM(CASE WHEN BYR_USD IS NULL AND DIRPKAN IS NULL THEN NULL
                                   ELSE COALESCE(BYR_USD, 0) + COALESCE(DIRPKAN, 0) END) AS PAY_USD,
                          SUM(CASE WHEN BYR_RP IS NULL AND DIUSDKAN IS NULL THEN NULL
                                   ELSE COALESCE(BYR_RP, 0) + COALESCE(DIUSDKAN, 0) END) AS PAY_IDR
                   FROM INVOICE GROUP BY REFSHITMEN) inv ON inv.REFSHITMEN = u.REFSHITMEN
        LEFT JOIN (SELECT REFSHITMEN, SUM(TOTAL) AS TOTAL, SUM(TOTAL_IDR) AS TOTAL_IDR,
                          SUM(CASE WHEN BAYAR IS NULL AND DIRPKAN IS NULL THEN NULL
                                   ELSE COALESCE(BAYAR, 0) + COALESCE(DIRPKAN, 0) END) AS PAY_USD,
                          SUM(CASE WHEN BAYAR_IDR IS NULL AND DIUSDKAN IS NULL THEN NULL
                                   ELSE COALESCE(BAYAR_IDR, 0) + COALESCE(DIUSDKAN, 0) END) AS PAY_IDR
                   FROM DRCRFULL GROUP BY REFSHITMEN) dn ON dn.REFSHITMEN = u.REFSHITMEN
        SQL."\n";

    public const SHIPMENT_SELECT = 'SELECT u.UNIK, u.TYPETRAN AS KIND, u.DATE, u.REFSHITMEN, u.BLNO3, '
        .'inv.NOINVOICE, '
        ."CASE WHEN u.TYPETRAN='I' THEN c.NAME ELSE s.NAME END AS CUSTOMER, "
        .'oc.GRAND_USD AS OPR_USD, oc.GRAND_RP AS OPR_RP, '
        .'inv.GRAND_USD AS INV_USD, inv.GRAND_RP AS INV_RP, '
        .'inv.LUNAS AS PAID, '
        .'inv.PAY_USD AS INV_PAY_USD, inv.PAY_IDR AS INV_PAY_IDR, '
        .'dn.TOTAL AS DNCN_USD, dn.TOTAL_IDR AS DNCN_IDR, '
        .'dn.PAY_USD AS DNCN_PAY_USD, dn.PAY_IDR AS DNCN_PAY_IDR '
        .self::SHIPMENT_JOINS;

    public const SHIPMENT_SORT_COLUMNS = [
        'date' => 'u.DATE',
        'reference' => 'u.REFSHITMEN',
        'customer' => 'CUSTOMER',
    ];

    /** Python queries.get_recent_shipments. */
    public function getRecentShipments(int $limit = 10, string $companyFilter = ''): array
    {
        $where = "WHERE u.DATE IS NOT NULL AND u.DATE != ''";
        $params = [];
        if ($companyFilter !== '') {
            [$cond, $cparams] = self::companyFilter($companyFilter, 'c', 's');
            $where .= " AND {$cond}";
            $params = array_merge($params, $cparams);
        }

        return DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(self::SHIPMENT_SELECT."{$where} ORDER BY u.DATE DESC LIMIT ?", array_merge($params, [$limit]));
    }

    /** Python queries.get_unpaid_shipments. */
    public function getUnpaidShipments(int $page = 1, int $perPage = 5, string $search = '',
        string $sort = 'date', string $sortDir = 'desc', string $companyFilter = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $where = "WHERE u.DATE IS NOT NULL AND u.DATE != ''"
            ." AND COALESCE(inv.PAY_USD, 0) = 0 AND COALESCE(inv.PAY_IDR, 0) = 0"
            ." AND COALESCE(dn.PAY_USD, 0) = 0 AND COALESCE(dn.PAY_IDR, 0) = 0";
        $params = [];
        if ($companyFilter !== '') {
            [$cond, $cparams] = self::companyFilter($companyFilter, 'c', 's');
            $where .= " AND {$cond}";
            $params = array_merge($params, $cparams);
        }
        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $where .= " AND (UPPER(u.REFSHITMEN) LIKE ? OR UPPER(u.BLNO3) LIKE ?"
                ." OR UPPER(COALESCE(c.NAME,'')) LIKE ? OR UPPER(COALESCE(s.NAME,'')) LIKE ?)";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        $total = (int) ($this->queryOne("SELECT COUNT(*) AS c ".self::SHIPMENT_JOINS."{$where}", $params)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $orderCol = self::SHIPMENT_SORT_COLUMNS[$sort] ?? 'u.DATE';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                self::SHIPMENT_SELECT."{$where} ORDER BY {$orderCol} {$direction}, u.DATE DESC LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page - 1) * $perPage]),
            );

        // Python keeps SUM() NULL when no shipment contributes invoice totals
        // (the stat card then renders '') — do NOT coalesce to 0.
        $totals = $this->queryOne(
            'SELECT COUNT(*) AS c, '
            .'SUM(CASE WHEN inv.GRAND_USD IS NOT NULL THEN inv.GRAND_USD ELSE 0 END) AS total_usd, '
            .'SUM(CASE WHEN inv.GRAND_RP IS NOT NULL THEN inv.GRAND_RP ELSE 0 END) AS total_idr '
            .self::SHIPMENT_JOINS."{$where}",
            $params,
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'total_usd' => $totals->total_usd ?? null,
            'total_idr' => $totals->total_idr ?? null,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Python queries.get_shipments — the /shipments list with type/invoice filters. */
    public function getShipments(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'date', string $sortDir = 'desc', string $companyFilter = '',
        string $typeFilter = '', string $invoiceFilter = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $where = "WHERE u.DATE IS NOT NULL AND u.DATE != ''";
        if ($typeFilter === 'import') {
            $where .= " AND u.TYPETRAN = 'I'";
        } elseif ($typeFilter === 'export') {
            $where .= " AND u.TYPETRAN = 'E'";
        } elseif ($typeFilter === 'other') {
            $where .= " AND u.TYPETRAN NOT IN ('I', 'E')";
        }
        if ($invoiceFilter === 'unissued') {
            $where .= " AND (inv.NOINVOICE IS NULL OR inv.NOINVOICE = '')";
        } elseif ($invoiceFilter === 'issued') {
            $where .= " AND inv.NOINVOICE IS NOT NULL AND inv.NOINVOICE != ''";
        } elseif ($invoiceFilter === 'issued_unpaid') {
            $where .= " AND inv.NOINVOICE IS NOT NULL AND inv.NOINVOICE != '' "
                .'AND (COALESCE(inv.PAY_USD, 0) + COALESCE(inv.PAY_IDR, 0))'
                .' < (COALESCE(inv.GRAND_USD, 0) + COALESCE(inv.GRAND_RP, 0))';
        } elseif ($invoiceFilter === 'paid') {
            $where .= " AND inv.NOINVOICE IS NOT NULL AND inv.NOINVOICE != '' "
                .'AND (COALESCE(inv.PAY_USD, 0) + COALESCE(inv.PAY_IDR, 0))'
                .' >= (COALESCE(inv.GRAND_USD, 0) + COALESCE(inv.GRAND_RP, 0))';
        }
        $params = [];
        if ($companyFilter !== '') {
            [$cond, $cparams] = self::companyFilter($companyFilter, 'c', 's');
            $where .= " AND {$cond}";
            $params = array_merge($params, $cparams);
        }
        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $where .= " AND (UPPER(u.REFSHITMEN) LIKE ? OR UPPER(u.BLNO3) LIKE ?"
                ." OR UPPER(COALESCE(c.NAME,'')) LIKE ? OR UPPER(COALESCE(s.NAME,'')) LIKE ?)";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        $total = (int) ($this->queryOne("SELECT COUNT(*) AS c ".self::SHIPMENT_JOINS."{$where}", $params)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $orderCol = self::SHIPMENT_SORT_COLUMNS[$sort] ?? 'u.DATE';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                self::SHIPMENT_SELECT."{$where} ORDER BY {$orderCol} {$direction}, u.DATE DESC LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page - 1) * $perPage]),
            );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Python queries.get_top_customers. */
    public function getTopCustomers(int $limit = 5): array
    {
        return [
            'import' => DB::connection($this->connections->connectionName($this->manager->active()))->select(
                "SELECT COALESCE(c.NAME, u.KD_CONSIG, '-') AS name, COUNT(*) AS cnt "
                .'FROM UnifiedShipment u '
                .'LEFT JOIN CONSIGNEE c ON u.KD_CONSIG = c.KD_CONSIG '
                ."WHERE u.TYPETRAN = 'I' AND u.DATE IS NOT NULL AND u.DATE != '' "
                .'GROUP BY name ORDER BY cnt DESC LIMIT ?',
                [$limit],
            ),
            'export' => DB::connection($this->connections->connectionName($this->manager->active()))->select(
                "SELECT COALESCE(s.NAME, u.KD_SHIPPER, '-') AS name, COUNT(*) AS cnt "
                .'FROM UnifiedShipment u '
                .'LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER '
                ."WHERE u.TYPETRAN != 'I' AND u.DATE IS NOT NULL AND u.DATE != '' "
                .'GROUP BY name ORDER BY cnt DESC LIMIT ?',
                [$limit],
            ),
        ];
    }

    // ── Invoices (Python _INVOICE_JOINS/_SELECT, get_invoices) ──────────

    public const INVOICE_JOINS = <<<'SQL'
        FROM INVOICE i
        LEFT JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN
        LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG
        LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER
        SQL."\n";

    // Parity note: Python selects i.rowid_ (not K_INVOICE) yet its template
    // links detail via inv.K_INVOICE — which is ALWAYS empty. The Blade port
    // reproduces that rendering (empty k= links) defect-for-defect.
    public const INVOICE_SELECT = 'SELECT i.rowid_ AS rowid_, i.NOINVOICE, i.REFSHITMEN, i.EI, i.DATEOFISSU, '
        .'i.SHIPPER AS SHIPPER_CODE, '
        ."CASE WHEN i.EI = 'I' THEN co.NAME ELSE sh.NAME END AS CUSTOMER, "
        .'i.GRAND_USD, i.GRAND_RP, i.BYR_USD, i.BYR_RP, '
        .'i.SELISIH_US, i.SELISIH_ID, i.LUNAS, i.REMARK '
        .self::INVOICE_JOINS;

    public const INVOICE_SORT_COLUMNS = [
        'date' => 'i.DATEOFISSU',
        'reference' => 'i.REFSHITMEN',
        'customer' => 'CUSTOMER',
    ];

    /** Python queries.get_invoices. */
    public function getInvoices(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'date', string $sortDir = 'desc', string $companyFilter = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $where = "WHERE i.NOINVOICE IS NOT NULL AND i.NOINVOICE != ''";
        $params = [];
        if ($companyFilter !== '') {
            [$cond, $cparams] = self::companyFilter($companyFilter, 'co', 'sh');
            $where .= " AND {$cond}";
            $params = array_merge($params, $cparams);
        }
        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $where .= " AND (UPPER(i.NOINVOICE) LIKE ? OR UPPER(i.REFSHITMEN) LIKE ?"
                ." OR UPPER(COALESCE(co.NAME,'')) LIKE ? OR UPPER(COALESCE(sh.NAME,'')) LIKE ?)";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        $total = (int) ($this->queryOne("SELECT COUNT(*) AS c ".self::INVOICE_JOINS."{$where}", $params)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $orderCol = self::INVOICE_SORT_COLUMNS[$sort] ?? 'i.DATEOFISSU';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                self::INVOICE_SELECT."{$where} ORDER BY {$orderCol} {$direction}, i.DATEOFISSU DESC LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page - 1) * $perPage]),
            );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Python queries._safe_query — empty array when the table is missing. */
    protected function safeQuery(string $sql, array $params = []): array
    {
        try {
            return DB::connection($this->connections->connectionName($this->manager->active()))->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Details (Python get_shipment_detail / get_invoice_detail) ───────

    public function getShipmentDetail(string $refshifmen): ?array
    {
        $header = $this->queryOne(
            'SELECT u.UNIK, u.TYPETRAN AS KIND, u.DATE, u.REFSHITMEN, u.BLNO3, '
            ."CASE WHEN u.TYPETRAN='I' THEN co.NAME ELSE sh.NAME END AS CUSTOMER, "
            .'u.KD_CONSIG, u.KD_SHIPPER, u.CONTAINERN, u.PORTOFLOAD, u.PORTOFDISC, '
            .'u.SHIPPINGMA, u.DESCRIPTIO AS DESCRIPTION, '
            .'u.CONSIGNEE AS CONSIGNEE_NAME, u.SHIPPER AS SHIPPER_NAME, '
            .'u.SAIDTOCONT, u.BL_NO, u.MBL_NO, u.ETD, u.ETA, u.ATA, '
            .'ag.NAME AS AGENT_NAME, v.NM_VESSEL AS VESSEL_NAME, u.EXVOYAGE '
            .'FROM UnifiedShipment u '
            .'LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG '
            .'LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER '
            .'LEFT JOIN AGENT ag ON u.AGENT = ag.AGENT AND u.CODE = ag.CODE '
            .'LEFT JOIN VESSEL v ON u.KD_EXVESSE = v.KD_VESSEL '
            .'WHERE u.REFSHITMEN = ?',
            [$refshifmen],
        );
        if ($header === null) {
            return null;
        }

        $oprHeaders = $this->safeQuery(
            'SELECT UOPRCOST, NOMOR, TANGGAL, EXRATE, GRAND_USD, GRAND_RP '
            .'FROM OPRCOST WHERE REFSHITMEN = ? ORDER BY TANGGAL',
            [$refshifmen],
        );
        $oprItems = $this->safeQuery(
            'SELECT r.UOPRCOST, r.JENISCOST, r.DESCRIPTIO, r.CURRENCY, '
            .'r.QTY, r.TSRATE, r.JUMLAH, r.INVOICE_NO, r.INV_TGL, r.KETERANGAN '
            .'FROM OPRCOST_R r JOIN OPRCOST o ON r.UOPRCOST = o.UOPRCOST '
            .'WHERE o.REFSHITMEN = ? ORDER BY r.JENISCOST',
            [$refshifmen],
        );
        $oprItemsByParent = [];
        foreach ($oprItems as $item) {
            $oprItemsByParent[$item->UOPRCOST][] = $item;
        }

        $invoices = $this->safeQuery(
            // Parity note: Python does NOT select K_INVOICE here, so its
            // template's inv_items lookup (by inv.K_INVOICE) always misses and
            // invoice line items never render in shipment detail. Reproduced.
            'SELECT NOINVOICE, DATEOFISSU, SHIPPER AS SHIPPER_CODE, '
            .'EXRATE, GRAND_USD, GRAND_RP, BYR_USD, BYR_RP, '
            .'SELISIH_US, SELISIH_ID, LUNAS, REMARK, EI '
            .'FROM INVOICE WHERE REFSHITMEN = ? ORDER BY DATEOFISSU',
            [$refshifmen],
        );
        $invItemsRaw = $this->safeQuery(
            'SELECT r.NOINVOICE, r.JENISCOST, r.DESCRIPTIO, r.CURRENCY, '
            .'r.QTY, r.SELLING, r.JUMLAH, r.NOTE '
            .'FROM INVOICE_R r '
            .'JOIN INVOICE i ON r.NOINVOICE = i.NOINVOICE '
            .'WHERE i.REFSHITMEN = ? ORDER BY r.NOINVOICE, r.JENISCOST',
            [$refshifmen],
        );
        $invItemsByParent = [];
        foreach ($invItemsRaw as $item) {
            $invItemsByParent[$item->NOINVOICE][] = $item;
        }

        // Debit / Credit notes: negative total -> DN, positive/zero -> CN.
        // Parity note: Python's DRCRFULL select does NOT include UNIK (the
        // grouping key its template looks up), so DN/CN line items NEVER
        // render there. Reproduced: keep the note rows UNIK-less and let the
        // template's lookup miss.
        $allNotes = $this->safeQuery(
            'SELECT NO_REF, PLUS, TANGGAL, TONAME, MODE, BLNO, BLNO3, '
            .'TOTAL, TOTAL_IDR, BAYAR, DIRPKAN, BAYAR_IDR, DIUSDKAN, LUNAS, ACCT_DRCR '
            .'FROM DRCRFULL WHERE REFSHITMEN = ? '
            .'ORDER BY TANGGAL',
            [$refshifmen],
        );
        $debitNotes = [];
        $creditNotes = [];
        foreach ($allNotes as $note) {
            $amount = $note->TOTAL ?? 0;
            $amountIdr = $note->TOTAL_IDR ?? 0;
            if ((is_numeric($amount) && $amount < 0) || (is_numeric($amountIdr) && $amountIdr < 0)) {
                $debitNotes[] = $note;
            } else {
                $creditNotes[] = $note;
            }
        }
        $drItems = $this->safeQuery(
            'SELECT r.UNIK, r.JENISCOST, r.DESCRIPTIO, r.CURRENCY, '
            .'r.QTY, r.UNITPRICE, r.JUMLAH '
            .'FROM DRCRFULL_R r JOIN DRCRFULL d ON r.UNIK = d.UNIK '
            .'WHERE d.REFSHITMEN = ? ORDER BY d.NO_REF, r.JENISCOST',
            [$refshifmen],
        );
        $drItemsByParent = [];
        foreach ($drItems as $item) {
            $drItemsByParent[$item->UNIK][] = $item;
        }

        // Gross Profit = total invoice - total operational cost.
        $gpRow = $this->queryOne(
            'SELECT COALESCE(SUM(i.GRAND_USD), 0) - COALESCE(('
            .'SELECT SUM(o.GRAND_USD) FROM OPRCOST o WHERE o.REFSHITMEN = ?), 0) AS gp_usd, '
            .'COALESCE(SUM(i.GRAND_RP), 0) - COALESCE(('
            .'SELECT SUM(o.GRAND_RP) FROM OPRCOST o WHERE o.REFSHITMEN = ?), 0) AS gp_rp '
            .'FROM INVOICE i WHERE i.REFSHITMEN = ?',
            [$refshifmen, $refshifmen, $refshifmen],
        );
        $headerArr = (array) $header;
        $headerArr['GP_USD'] = $gpRow->gp_usd ?? 0;
        $headerArr['GP_RP'] = $gpRow->gp_rp ?? 0;

        return [
            'header' => (object) $headerArr,
            'opr_headers' => $oprHeaders,
            'opr_items' => $oprItemsByParent,
            'invoices' => $invoices,
            'inv_items' => $invItemsByParent,
            'debit_notes' => $debitNotes,
            'credit_notes' => $creditNotes,
            'dr_items' => $drItemsByParent,
        ];
    }

    public function getInvoiceDetail(string $kInvoice): ?array
    {
        $header = $this->queryOne(
            'SELECT i.K_INVOICE, i.NOINVOICE, i.EI, i.REFSHITMEN, i.DATEOFISSU, '
            .'i.SHIPPER AS SHIPPER_CODE, sh.NAME AS SHIPPER_NAME, '
            .'i.EXRATE, i.GRAND_USD, i.GRAND_RP, i.BYR_USD, i.BYR_RP, '
            .'i.DIRPKAN, i.DIUSDKAN, i.SELISIH_US, i.SELISIH_ID, '
            .'i.LUNAS, i.REMARK, i.NOTEINV, '
            // CONTAINERN is deliberately NOT selected — see queries.py
            // get_invoice_detail: the sync-owned INVOICE schema has no such
            // column; selecting it 500s on real company DBs.
            .'i.BLNO, i.BLNO3, i.PORTOFLOAD, i.PORTOFDISC, '
            .'i.VESSELNAME, i.ETDDATE, i.ETADATE, '
            ."CASE WHEN i.EI = 'I' THEN co.NAME ELSE s.NAME END AS CUSTOMER "
            .'FROM INVOICE i '
            .'LEFT JOIN SHIPPER sh ON i.SHIPPER = sh.KD_SHIPPER '
            .'LEFT JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN '
            .'LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG '
            .'LEFT JOIN SHIPPER s ON u.KD_SHIPPER = s.KD_SHIPPER '
            .'WHERE i.K_INVOICE = ?',
            [$kInvoice],
        );
        if ($header === null) {
            return null;
        }

        $lineItems = $this->safeQuery(
            'SELECT r.JENISCOST, r.DESCRIPTIO, r.CURRENCY, r.QTY, '
            .'r.SELLING, r.JUMLAH, r.NOTE, '
            .'st.SATUAN AS SATUAN_NAME, r.KD_SATUAN '
            .'FROM INVOICE_R r '
            .'LEFT JOIN SATUAN st ON r.KD_SATUAN = st.KD_SATUAN '
            .'WHERE r.K_INVOICE = ? ORDER BY r.NO, r.JENISCOST',
            [$kInvoice],
        );

        return ['header' => $header, 'line_items' => $lineItems];
    }

    /** Python queries.get_company_names. */
    public function getCompanyNames(): array
    {
        $rows = DB::connection($this->connections->connectionName($this->manager->active()))->select(
            "SELECT DISTINCT NAME FROM SHIPPER WHERE NAME IS NOT NULL AND NAME != '' "
            .'UNION '
            ."SELECT DISTINCT NAME FROM CONSIGNEE WHERE NAME IS NOT NULL AND NAME != '' "
            .'ORDER BY NAME'
        );

        return array_map(fn ($r) => $r->NAME, $rows);
    }

    // ── F-28 Listing Reference pages ─────────────────────────────────────

    /** Sort columns for Agent table. */
    public const AGENT_SORT_COLUMNS = [
        'agent' => 'a.AGENT',
        'code' => 'a.CODE',
        'name' => 'a.NAME',
        'rate' => 'a.RATE',
    ];

    /** Get agents list with pagination, search, sort, company filter. */
    public function getAgents(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'name', string $sortDir = 'asc', string $companyFilter = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $where = "WHERE 1=1";
        $params = [];

        // Company filter is ambiguous on reference data (build-spec item 8):
        // AGENT is a global reference table with no company column, so no filter.

        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $where .= " AND (UPPER(a.AGENT) LIKE ? OR UPPER(a.CODE) LIKE ? OR UPPER(a.NAME) LIKE ?)";
            $params = array_merge($params, [$like, $like, $like]);
        }

        $total = (int) ($this->queryOne("SELECT COUNT(*) AS c FROM AGENT a {$where}", $params)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $orderCol = self::AGENT_SORT_COLUMNS[$sort] ?? 'a.NAME';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                "SELECT a.AGENT, a.CODE, a.NAME, a.AGENTINV, a.RATE, "
                ."a.ADDRESS1, a.ADDRESS2, a.ADDRESS3, a.TELP, a.FAX, a.PIC, a.KD_ACCOUNT, a.ACCT_CR "
                ."FROM AGENT a {$where} ORDER BY {$orderCol} {$direction} LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page - 1) * $perPage]),
            );

        // Add computed address field
        foreach ($rows as $row) {
            $parts = array_filter([
                $row->ADDRESS1 ?? '',
                $row->ADDRESS2 ?? '',
                $row->ADDRESS3 ?? '',
            ]);
            $row->ADDRESS = implode(' ', $parts);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Sort columns for Shipper table. */
    public const SHIPPER_SORT_COLUMNS = [
        'code' => 's.KD_SHIPPER',
        'name' => 's.NAME',
        'bu' => 's.KD_BU',
        'type' => 's.KD_JNSCUST',
    ];

    /** Get shippers list with pagination, search, sort, company filter. */
    public function getShippers(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'name', string $sortDir = 'asc', string $companyFilter = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $where = "WHERE 1=1";
        $params = [];

        // SHIPPER IS the company reference: filter on its own NAME.
        if ($companyFilter !== '') {
            $where .= " AND UPPER(COALESCE(s.NAME,'')) LIKE ?";
            $params[] = '%'.mb_strtoupper($companyFilter).'%';
        }

        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $where .= " AND (UPPER(s.KD_SHIPPER) LIKE ? OR UPPER(s.NAME) LIKE ? OR UPPER(s.KD_BU) LIKE ?)";
            $params = array_merge($params, [$like, $like, $like]);
        }

        $total = (int) ($this->queryOne("SELECT COUNT(*) AS c FROM SHIPPER s {$where}", $params)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $orderCol = self::SHIPPER_SORT_COLUMNS[$sort] ?? 's.NAME';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                "SELECT s.KD_SHIPPER, s.KD_BU, s.NAME, s.ADDRESS1, s.ADDRESS2, s.ADDRESS3, "
                ."s.TELP, s.FAX, s.KD_JNSCUST, s.TERM, s.KD_ACCOUNT, s.NPWP, s.NPWP_ALAMA, s.NPWP_ALAM2, "
                ."s.NPWP_DATE, s.NO_SIUP, s.TGL_SIUP, s.NOTE "
                ."FROM SHIPPER s {$where} ORDER BY {$orderCol} {$direction} LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page - 1) * $perPage]),
            );

        foreach ($rows as $row) {
            $parts = array_filter([
                $row->ADDRESS1 ?? '',
                $row->ADDRESS2 ?? '',
                $row->ADDRESS3 ?? '',
            ]);
            $row->ADDRESS = implode(' ', $parts);
            $npwpParts = array_filter([
                $row->NPWP_ALAMA ?? '',
                $row->NPWP_ALAM2 ?? '',
            ]);
            $row->NPWP_ADDRESS = implode(' ', $npwpParts);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Sort columns for Consignee table. */
    public const CONSIGNEE_SORT_COLUMNS = [
        'code' => 'c.KD_CONSIG',
        'name' => 'c.NAME',
    ];

    /** Get consignees list with pagination, search, sort, company filter. */
    public function getConsignees(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'name', string $sortDir = 'asc', string $companyFilter = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $where = "WHERE 1=1";
        $params = [];

        // Company filter ambiguous on CONSIGNEE (build-spec item 8):
        // CONSIGNEE is a global reference table with no company column.

        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $where .= " AND (UPPER(c.KD_CONSIG) LIKE ? OR UPPER(c.NAME) LIKE ?)";
            $params = array_merge($params, [$like, $like]);
        }

        $total = (int) ($this->queryOne("SELECT COUNT(*) AS c FROM CONSIGNEE c {$where}", $params)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $orderCol = self::CONSIGNEE_SORT_COLUMNS[$sort] ?? 'c.NAME';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                "SELECT c.KD_CONSIG, c.NAME, c.ADDRESS1, c.ADDRESS2, c.ADDRESS3, c.TELP, c.FAX "
                ."FROM CONSIGNEE c {$where} ORDER BY {$orderCol} {$direction} LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page - 1) * $perPage]),
            );

        foreach ($rows as $row) {
            $parts = array_filter([
                $row->ADDRESS1 ?? '',
                $row->ADDRESS2 ?? '',
                $row->ADDRESS3 ?? '',
            ]);
            $row->ADDRESS = implode(' ', $parts);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Sort columns for Account table. */
    public const ACCOUNT_SORT_COLUMNS = [
        'code' => 'a.KD_ACCOUNT',
        'name' => 'a.NM_ACCOUNT',
        'type' => 'a.TYPE',
        'group' => 'a.GROUP',
    ];

    /** Type code to label mapping for Account.TYPE badge. */
    public const ACCOUNT_TYPE_LABELS = [
        'A' => 'Asset',
        'L' => 'Liability',
        'E' => 'Equity',
        'I' => 'Income',
        'R' => 'Revenue',
    ];

    /** Normal balance code to label mapping. */
    public const ACCOUNT_SLD_NORMAL_LABELS = [
        'D' => 'Debit',
        'K' => 'Credit',
    ];

    /** Entry allowed boolean to label. */
    public const ACCOUNT_ENTRY_LABELS = [
        0 => 'No',
        1 => 'Yes',
        '0' => 'No',
        '1' => 'Yes',
    ];

    // ── F-29 Performance Tracker pages (metric formulas TBD) ─────────────

    /** Sort columns for Performance tables. */
    public const PERFORMANCE_SORT_COLUMNS = [
        'shipment_count' => 'shipment_count',
        'revenue_usd' => 'revenue_usd',
        'revenue_idr' => 'revenue_idr',
        'cost_usd' => 'cost_usd',
        'cost_idr' => 'cost_idr',
        'gp_usd' => 'gp_usd',
        'gp_idr' => 'gp_idr',
        'margin_pct' => 'margin_pct',
        'avg_rate_usd' => 'avg_rate_usd',
    ];

    /** Performance Tracker — By Shipper. */
    public function getPerformanceByShipper(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'revenue_usd', string $sortDir = 'desc', string $companyFilter = '',
        string $dateFrom = '', string $dateTo = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $where = "WHERE 1=1";
        $params = [];

        if ($dateFrom !== '') {
            $where .= " AND i.DATEOFISSU >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where .= " AND i.DATEOFISSU <= ?";
            $params[] = $dateTo;
        }

        if ($companyFilter !== '') {
            $where .= " AND UPPER(COALESCE(i.SHIPPER,'')) LIKE ?";
            $params[] = '%'.mb_strtoupper($companyFilter).'%';
        }

        // Search applies to the outer aggregated rows (shipper code / name).

        // Build CTE for aggregated metrics
        $sql = "
            WITH inv AS (
                SELECT i.REFSHITMEN, i.SHIPPER,
                       SUM(COALESCE(i.GRAND_USD, 0)) AS GRAND_USD,
                       SUM(COALESCE(i.GRAND_RP, 0)) AS GRAND_RP
                FROM INVOICE i
                WHERE i.NOINVOICE IS NOT NULL AND i.NOINVOICE != ''
                {$where}
                GROUP BY i.REFSHITMEN, i.SHIPPER
            ),
            opr AS (
                SELECT o.REFSHITMEN,
                       SUM(COALESCE(o.GRAND_USD, 0)) AS GRAND_USD,
                       SUM(COALESCE(o.GRAND_RP, 0)) AS GRAND_RP
                FROM OPRCOST o
                GROUP BY o.REFSHITMEN
            ),
            base AS (
                SELECT s.KD_SHIPPER, s.NAME AS SHIPPER_NAME,
                       COUNT(DISTINCT inv.REFSHITMEN) AS shipment_count,
                       SUM(inv.GRAND_USD) AS revenue_usd,
                       SUM(inv.GRAND_RP) AS revenue_idr,
                       SUM(COALESCE(opr.GRAND_USD, 0)) AS cost_usd,
                       SUM(COALESCE(opr.GRAND_RP, 0)) AS cost_idr
                FROM SHIPPER s
                LEFT JOIN inv ON s.KD_SHIPPER = inv.SHIPPER
                LEFT JOIN opr ON inv.REFSHITMEN = opr.REFSHITMEN
                GROUP BY s.KD_SHIPPER, s.NAME
            )
            SELECT KD_SHIPPER, SHIPPER_NAME,
                   shipment_count,
                   revenue_usd, revenue_idr,
                   cost_usd, cost_idr,
                   (revenue_usd - cost_usd) AS gp_usd,
                   (revenue_idr - cost_idr) AS gp_idr,
                   CASE WHEN revenue_usd > 0 THEN ROUND((revenue_usd - cost_usd) / revenue_usd * 100, 2) ELSE 0 END AS margin_pct,
                   CASE WHEN shipment_count > 0 THEN ROUND(revenue_usd / shipment_count, 2) ELSE 0 END AS avg_rate_usd
            FROM base
        ";

        // Add search to the final query
        $searchWhere = '';
        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $searchWhere = "WHERE UPPER(KD_SHIPPER) LIKE ? OR UPPER(SHIPPER_NAME) LIKE ?";
            $params = array_merge($params, [$like, $like]);
        }

        $orderCol = self::PERFORMANCE_SORT_COLUMNS[$sort] ?? 'revenue_usd';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $countSql = "SELECT COUNT(*) AS c FROM ({$sql}) t {$searchWhere}";
        $total = (int) ($this->queryOne($countSql, $params)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $dataSql = "{$sql} {$searchWhere} ORDER BY {$orderCol} {$direction} LIMIT ? OFFSET ?";
        $params = array_merge($params, [$perPage, ($page - 1) * $perPage]);

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select($dataSql, $params);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Performance Tracker — By Shipping Lines (BLOCKED: VENDOR not synced). */
    public function getPerformanceByShippingLines(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'revenue_usd', string $sortDir = 'desc', string $companyFilter = '',
        string $dateFrom = '', string $dateTo = ''): array
    {
        // BLOCKER: VENDOR table not synced from DBF.
        // Return empty structure with note.
        return [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => $perPage,
            'pages' => 1,
            'blocked' => true,
            'blocker' => 'VENDOR table not synced',
        ];
    }

    /** Performance Tracker — By Agent. */
    public function getPerformanceByAgent(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'revenue_usd', string $sortDir = 'desc', string $companyFilter = '',
        string $dateFrom = '', string $dateTo = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $where = "WHERE 1=1";
        $params = [];

        if ($dateFrom !== '') {
            $where .= " AND u.DATE >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where .= " AND u.DATE <= ?";
            $params[] = $dateTo;
        }

        // Company filter on agent performance ambiguous (build-spec item 8).
        // Placeholder: not applied until contract confirmed.

        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $where .= " AND (UPPER(ag.AGENT) LIKE ? OR UPPER(ag.CODE) LIKE ? OR UPPER(ag.NAME) LIKE ?)";
            $params = array_merge($params, [$like, $like, $like]);
        }

        $sql = "
            WITH inv AS (
                SELECT i.REFSHITMEN,
                       SUM(COALESCE(i.GRAND_USD, 0)) AS GRAND_USD,
                       SUM(COALESCE(i.GRAND_RP, 0)) AS GRAND_RP
                FROM INVOICE i
                WHERE i.NOINVOICE IS NOT NULL AND i.NOINVOICE != ''
                GROUP BY i.REFSHITMEN
            ),
            opr AS (
                SELECT o.REFSHITMEN,
                       SUM(COALESCE(o.GRAND_USD, 0)) AS GRAND_USD,
                       SUM(COALESCE(o.GRAND_RP, 0)) AS GRAND_RP
                FROM OPRCOST o
                GROUP BY o.REFSHITMEN
            ),
            base AS (
                SELECT ag.AGENT, ag.CODE, ag.NAME AS AGENT_NAME,
                       COUNT(DISTINCT u.REFSHITMEN) AS shipment_count,
                       SUM(inv.GRAND_USD) AS revenue_usd,
                       SUM(inv.GRAND_RP) AS revenue_idr,
                       SUM(COALESCE(opr.GRAND_USD, 0)) AS cost_usd,
                       SUM(COALESCE(opr.GRAND_RP, 0)) AS cost_idr
                FROM UnifiedShipment u
                LEFT JOIN AGENT ag ON u.AGENT = ag.AGENT AND u.CODE = ag.CODE
                LEFT JOIN inv ON u.REFSHITMEN = inv.REFSHITMEN
                LEFT JOIN opr ON u.REFSHITMEN = opr.REFSHITMEN
                {$where}
                GROUP BY ag.AGENT, ag.CODE, ag.NAME
            )
            SELECT AGENT, CODE, AGENT_NAME,
                   shipment_count,
                   revenue_usd, revenue_idr,
                   cost_usd, cost_idr,
                   (revenue_usd - cost_usd) AS gp_usd,
                   (revenue_idr - cost_idr) AS gp_idr,
                   CASE WHEN revenue_usd > 0 THEN ROUND((revenue_usd - cost_usd) / revenue_usd * 100, 2) ELSE 0 END AS margin_pct,
                   CASE WHEN shipment_count > 0 THEN ROUND(revenue_usd / shipment_count, 2) ELSE 0 END AS avg_rate_usd
            FROM base
        ";

        $orderCol = self::PERFORMANCE_SORT_COLUMNS[$sort] ?? 'revenue_usd';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $countSql = "SELECT COUNT(*) AS c FROM ({$sql}) t";
        $total = (int) ($this->queryOne($countSql, $params)->c ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $dataSql = "{$sql} ORDER BY {$orderCol} {$direction} LIMIT ? OFFSET ?";
        $params = array_merge($params, [$perPage, ($page - 1) * $perPage]);

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select($dataSql, $params);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Performance Tracker — By Consignee Import Only (BLOCKED: NA table sync TBD). */
    public function getPerformanceByConsigneeImport(int $page = 1, int $perPage = 25, string $search = '',
        string $sort = 'revenue_usd', string $sortDir = 'desc', string $companyFilter = '',
        string $dateFrom = '', string $dateTo = ''): array
    {
        // BLOCKER: NA table sync status unknown; linkage to CONSIGNEE undefined.
        // Return empty structure with note.
        return [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => $perPage,
            'pages' => 1,
            'blocked' => true,
            'blocker' => 'NA table sync status unknown',
        ];
    }

    // ── F-24 A/R Receipt & F-25 A/P Payment report pages ─────────────────

    /** A/R receipt row types (F-24). */
    public const AR_RECEIPT_ROW_TYPES = [
        'receipt_invoice',
        'receipt_discrepancy',
        'receipt_cashbank',
    ];

    /** A/P payment row types (F-25). */
    public const AP_PAYMENT_ROW_TYPES = [
        'payment_vendor',
        'payment_agent',
        'payment_refund_cost',
        'payment_cashbank',
    ];

    /** JENIS_BYR: 1=cash, 2=bank, 4=clearing/transfer (Python JENIS_BYR_MAP). */
    public const PAYMENT_METHOD_MAP = [
        1 => 'cash',
        2 => 'bank',
        4 => 'clearing',
    ];

    /** Row-type display labels. */
    public const ROW_TYPE_LABELS = [
        'receipt_invoice'       => 'Receipt Invoice',
        'receipt_discrepancy'   => 'Receipt Discrepancy',
        'receipt_cashbank'      => 'Receipt Cash/Bank',
        'payment_vendor'        => 'Payment Vendor',
        'payment_agent'         => 'Payment Agent',
        'payment_refund_cost'   => 'Payment Refund Cost',
        'payment_cashbank'      => 'Payment Cash/Bank',
    ];

    /** Status label: settled when amount != 0, else open. */
    public const STATUS_LABELS = [
        'open'    => 'Open',
        'settled' => 'Settled',
    ];

    /**
     * Python queries.get_ar_receipt — combined receipt row types with
     * safeQuery fallback for missing tables. Search/sort/paginate in PHP
     * (union across disparate tables is impractical at SQL level).
     */
    public function getArReceipts(int $page = 1, int $perPage = 50, string $search = '',
        string $sort = 'date', string $sortDir = 'desc', string $companyFilter = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);

        $items = [];

        $items = array_merge($items, $this->safeQuery(
            "SELECT a.TANGGAL AS date, 'receipt_invoice' AS row_type,
                COALESCE(i.SHIPPER, i.SHIPP_PIUT, '') AS customer,
                '' AS vendor, '' AS agent,
                i.NOINVOICE AS invoice_no, a.NO_REF AS reference_no,
                COALESCE(ar.BAYAR, 0) AS amount, a.CURRENCY AS currency,
                a.JENIS_BYR AS jenis_byr, a.KETERANGAN AS notes
            FROM ARS a
            JOIN ARS_R ar ON a.UARS = ar.UARS
            JOIN INVOICE i ON ar.K_INVOICE = i.K_INVOICE
            WHERE a.POSTING = 1 AND ar.POSTING = 1 AND i.POSTING = 1"
        ));

        $items = array_merge($items, $this->safeQuery(
            "SELECT a.TANGGAL AS date, 'receipt_discrepancy' AS row_type,
                COALESCE(i.SHIPPER, i.SHIPP_PIUT, '') AS customer,
                '' AS vendor, '' AS agent,
                i.NOINVOICE AS invoice_no, a.NO_REF AS reference_no,
                COALESCE(ar.SELISIH, i.SELISIH_US, 0) AS amount, a.CURRENCY AS currency,
                a.JENIS_BYR AS jenis_byr, a.KETERANGAN AS notes
            FROM ARS a
            JOIN ARS_R ar ON a.UARS = ar.UARS
            JOIN INVOICE i ON ar.K_INVOICE = i.K_INVOICE
            WHERE a.POSTING = 1 AND ar.POSTING = 1 AND i.POSTING = 1
              AND (COALESCE(ar.SELISIH, 0) != 0 OR COALESCE(i.SELISIH_US, 0) != 0)"
        ));

        $items = array_merge($items, $this->safeQuery(
            "SELECT t.TANGGAL AS date, 'receipt_cashbank' AS row_type,
                '' AS customer, '' AS vendor, '' AS agent,
                tr.URAIAN AS invoice_no, t.REF_NO AS reference_no,
                COALESCE(tr.JUMLAH, 0) AS amount, t.CURRENCY AS currency,
                t.JENIS_BYR AS jenis_byr, t.KETERANGAN AS notes
            FROM TERIMA t
            JOIN TERIMA_R tr ON t.UTERIMA = tr.UTERIMA
            WHERE t.POSTING = 1 AND tr.POSTING = 1"
        ));

        // Company filter on customer name (partial match, customer role).
        if ($companyFilter !== '') {
            $cf = mb_strtoupper($companyFilter);
            $items = array_values(array_filter($items, fn ($row) =>
                stripos((string) ($row->customer ?? ''), $cf) !== false));
        }

        // Search across invoice_no, reference_no, customer, notes.
        if (trim($search) !== '') {
            $like = mb_strtoupper(trim($search));
            $items = array_values(array_filter($items, fn ($row) =>
                stripos((string) ($row->invoice_no ?? ''), $like) !== false ||
                stripos((string) ($row->reference_no ?? ''), $like) !== false ||
                stripos((string) ($row->customer ?? ''), $like) !== false ||
                stripos((string) ($row->notes ?? ''), $like) !== false));
        }

        // Compute derived fields: status, payment_method label.
        foreach ($items as $row) {
            $row->status = ((float) ($row->amount ?? 0) !== 0) ? 'settled' : 'open';
            $row->payment_method = self::PAYMENT_METHOD_MAP[$row->jenis_byr ?? 0] ?? 'clearing';
            $row->row_type_label = self::ROW_TYPE_LABELS[$row->row_type ?? ''] ?? $row->row_type;
            $row->status_label = self::STATUS_LABELS[$row->status] ?? $row->status;
        }

        // Sort.
        $keyFn = match ($sort) {
            'date'       => fn ($r) => (string) ($r->date ?? ''),
            'customer'   => fn ($r) => (string) (($r->customer ?? $r->vendor ?? '')),
            'amount'     => fn ($r) => (float) ($r->amount ?? 0),
            'status'     => fn ($r) => (string) ($r->status ?? ''),
            'invoice_no' => fn ($r) => (string) ($r->invoice_no ?? ''),
            default      => fn ($r) => (string) ($r->date ?? ''),
        };
        usort($items, fn ($a, $b) =>
            $sortDir === 'desc'
                ? $keyFn($b) <=> $keyFn($a)
                : $keyFn($a) <=> $keyFn($b));

        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $pageItems = array_slice($items, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => $pageItems,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * Python queries.get_ap_payment — combined payment row types with
     * safeQuery fallback for missing tables. Search/sort/paginate in PHP.
     */
    public function getApPayments(int $page = 1, int $perPage = 50, string $search = '',
        string $sort = 'date', string $sortDir = 'desc', string $companyFilter = ''): array
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);

        $items = [];

        $items = array_merge($items, $this->safeQuery(
            "SELECT ap.TANGGAL AS date, 'payment_vendor' AS row_type,
                '' AS customer,
                COALESCE(ap.KD_SHIPLIN, ap.KD_ACCOUNT, '') AS vendor,
                '' AS agent, COALESCE(apr.INVOICE_NO, '') AS invoice_no,
                ap.NO_REF AS reference_no, COALESCE(apr.BAYAR, 0) AS amount,
                ap.CURRENCY AS currency, ap.JENIS_BYR AS jenis_byr, ap.KETERANGAN AS notes
            FROM AP ap
            JOIN AP_R apr ON ap.UAP = apr.UAP
            WHERE ap.POSTING = 1 AND apr.POSTING = 1"
        ));

        $items = array_merge($items, $this->safeQuery(
            "SELECT a.TANGGAL AS date, 'payment_agent' AS row_type,
                '' AS customer, COALESCE(a.AGENT, '') AS vendor,
                a.CODE AS agent, ad.NOMOR AS invoice_no,
                a.NO_REF AS reference_no, COALESCE(ad.PAID, 0) AS amount,
                a.CURRENCY AS currency, a.JENIS_BYR AS jenis_byr, a.KETERANGAN AS notes
            FROM AP_AGENT a
            JOIN AP_AGENT_D ad ON a.UAP_AGENT = ad.UAP_AGENT
            WHERE a.POSTING = 1 AND ad.POSTING = 1"
        ));

        $items = array_merge($items, $this->safeQuery(
            "SELECT b.TANGGAL AS date, 'payment_refund_cost' AS row_type,
                '' AS customer, b.KD_ACCOUNT AS vendor, '' AS agent,
                br.URAIAN AS invoice_no, b.REF_NO AS reference_no,
                COALESCE(br.JUMLAH, 0) AS amount, b.CURRENCY AS currency,
                b.JENIS_BYR AS jenis_byr, b.KETERANGAN AS notes
            FROM BAYAR b
            JOIN BAYAR_R br ON b.UBAYAR = br.UBAYAR
            WHERE b.POSTING = 1 AND br.POSTING = 1"
        ));

        $items = array_merge($items, $this->safeQuery(
            "SELECT cd.TGL_JT AS date, 'payment_cashbank' AS row_type,
                '' AS customer, cd.KD_BANK AS vendor, '' AS agent,
                cd.NO_GBCEKTT AS invoice_no, cd.UNIK AS reference_no,
                COALESCE(cd.AMOUNT, 0) AS amount, cd.CURRENCY AS currency,
                4 AS jenis_byr, cd.NOTE AS notes
            FROM CLEARING_D cd"
        ));

        // Company filter on vendor name (partial match, customer role).
        if ($companyFilter !== '') {
            $cf = mb_strtoupper($companyFilter);
            $items = array_values(array_filter($items, fn ($row) =>
                stripos((string) ($row->vendor ?? ''), $cf) !== false));
        }

        // Search across invoice_no, reference_no, vendor, notes.
        if (trim($search) !== '') {
            $like = mb_strtoupper(trim($search));
            $items = array_values(array_filter($items, fn ($row) =>
                stripos((string) ($row->invoice_no ?? ''), $like) !== false ||
                stripos((string) ($row->reference_no ?? ''), $like) !== false ||
                stripos((string) ($row->vendor ?? ''), $like) !== false ||
                stripos((string) ($row->notes ?? ''), $like) !== false));
        }

        // Compute derived fields: status, payment_method label.
        foreach ($items as $row) {
            $row->status = ((float) ($row->amount ?? 0) !== 0) ? 'settled' : 'open';
            $row->payment_method = self::PAYMENT_METHOD_MAP[$row->jenis_byr ?? 0] ?? 'clearing';
            $row->row_type_label = self::ROW_TYPE_LABELS[$row->row_type ?? ''] ?? $row->row_type;
            $row->status_label = self::STATUS_LABELS[$row->status] ?? $row->status;
        }

        // Sort.
        $keyFn = match ($sort) {
            'date'       => fn ($r) => (string) ($r->date ?? ''),
            'customer'   => fn ($r) => (string) (($r->customer ?? $r->vendor ?? '')),
            'amount'     => fn ($r) => (float) ($r->amount ?? 0),
            'status'     => fn ($r) => (string) ($r->status ?? ''),
            'invoice_no' => fn ($r) => (string) ($r->invoice_no ?? ''),
            default      => fn ($r) => (string) ($r->date ?? ''),
        };
        usort($items, fn ($a, $b) =>
            $sortDir === 'desc'
                ? $keyFn($b) <=> $keyFn($a)
                : $keyFn($a) <=> $keyFn($b));

        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $pageItems = array_slice($items, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => $pageItems,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Python queries.get_accounts — paginated chart of accounts. */
    public function getAccounts(
        int $page = 1,
        int $perPage = 25,
        string $search = '',
        string $sort = 'code',
        string $sortDir = 'asc',
        string $typeFilter = '',
        string $companyFilter = '',
    ): array {
        $page = max((int) $page, 1);
        $perPage = max((int) $perPage, 1);
        $where = 'WHERE 1=1';
        $params = [];

        if (trim($search) !== '') {
            $like = '%'.mb_strtoupper(trim($search)).'%';
            $where .= " AND (UPPER(a.KD_ACCOUNT) LIKE ? OR UPPER(a.NM_ACCOUNT) LIKE ?)";
            $params = array_merge($params, [$like, $like]);
        }
        if ($typeFilter === 'detail') {
            $where .= " AND a.TYPE = 'D'";
        } elseif ($typeFilter === 'group') {
            $where .= " AND a.TYPE = 'G'";
        }

        $total = (int) (($this->queryOne("SELECT COUNT(*) AS c FROM ACCOUNT a {$where}", $params)->c ?? 0));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $orderCol = self::ACCOUNT_SORT_COLUMNS[$sort] ?? 'a.KD_ACCOUNT';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                "SELECT a.KD_ACCOUNT, a.NM_ACCOUNT, a.SLD_NORMAL, a.TYPE, a.\"GROUP\", a.ACTIVE "
                ."FROM ACCOUNT a {$where} ORDER BY {$orderCol} {$direction} LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page - 1) * $perPage]),
            );

        return [
            'rows' => array_map(fn($r) => (array) $r, $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** Python queries.get_account_detail — journal entries (JE_D) for one account. */
    public function getAccountDetail(
        string $kdAccount,
        string $dateFrom = '',
        string $dateTo = '',
        int $page = 1,
        int $perPage = 25,
        string $sort = 'date',
        string $sortDir = 'desc',
    ): ?array {
        $account = $this->queryOne(
            "SELECT KD_ACCOUNT, NM_ACCOUNT, SLD_NORMAL, TYPE, \"GROUP\", ACTIVE "
            ."FROM ACCOUNT WHERE KD_ACCOUNT = ?",
            [$kdAccount],
        );
        if ($account === null) {
            return null;
        }

        $where = 'WHERE d.KD_ACCOUNT = ?';
        $params = [$kdAccount];
        if ($dateFrom !== '') {
            $where .= ' AND h.TANGGAL >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where .= ' AND h.TANGGAL <= ?';
            $params[] = $dateTo.' 23:59:59';
        }

        $total = (int) (($this->queryOne(
            "SELECT COUNT(*) AS c FROM JE_D d JOIN JE h ON d.UJE = h.UJE {$where}",
            $params,
        )->c ?? 0));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $sortCols = ['date' => 'h.TANGGAL', 'modul' => 'd.MODUL', 'ref' => 'h.REF_NO'];
        $orderCol = $sortCols[$sort] ?? 'h.TANGGAL';
        $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';

        $rows = DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                "SELECT d.MODUL, h.REF_NO, h.TANGGAL, d.URAIAN, d.JUMLAH, "
                ."d.DK, d.CURRENCY, d.EXRATE "
                ."FROM JE_D d JOIN JE h ON d.UJE = h.UJE {$where} "
                ."ORDER BY {$orderCol} {$direction}, h.REF_NO ASC LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, ($page - 1) * $perPage]),
            );

        $idr = "CASE WHEN UPPER(d.CURRENCY) IN ('RP','IDR','RP.') THEN d.JUMLAH ELSE d.JUMLAH * COALESCE(d.EXRATE, 0) END";
        $totals = $this->queryOne(
            "SELECT "
            ."SUM(CASE WHEN d.DK = 'D' THEN {$idr} ELSE 0 END) AS debit, "
            ."SUM(CASE WHEN d.DK = 'K' THEN {$idr} ELSE 0 END) AS credit "
            ."FROM JE_D d JOIN JE h ON d.UJE = h.UJE {$where}",
            $params,
        );
        $t = $totals ?? new stdClass();

        return [
            'account' => $account,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'debit' => (float) ($t->debit ?? 0),
            'credit' => (float) ($t->credit ?? 0),
        ];
    }

    /** Python queries.get_sales_summary — invoice totals, outstanding, import/export split. */
    public function getSalesSummary(string $companyFilter = ''): array
    {
        [$invWhere, $params] = self::invoiceWhere($companyFilter);

        $totals = $this->queryOne(
            "SELECT COUNT(*) AS total_invoices, "
            ."SUM(GRAND_USD) AS total_usd, SUM(GRAND_RP) AS total_idr, "
            ."SUM(COALESCE(BYR_USD, 0)) AS paid_usd, SUM(COALESCE(BYR_RP, 0)) AS paid_idr, "
            ."SUM(GRAND_USD - COALESCE(BYR_USD, 0)) AS outstanding_usd, "
            ."SUM(GRAND_RP - COALESCE(BYR_RP, 0)) AS outstanding_idr, "
            ."COUNT(CASE WHEN COALESCE(BYR_USD, 0) = 0 AND COALESCE(BYR_RP, 0) = 0 THEN 1 END) AS unpaid_count "
            ."FROM INVOICE {$invWhere}",
            $params,
        );
        $t = $totals ?? new stdClass();

        $import = $this->queryOne(
            "SELECT COUNT(*) AS cnt, SUM(GRAND_USD) AS usd, SUM(GRAND_RP) AS idr "
            ."FROM INVOICE {$invWhere} AND EI = 'I'",
            $params,
        );
        $export = $this->queryOne(
            "SELECT COUNT(*) AS cnt, SUM(GRAND_USD) AS usd, SUM(GRAND_RP) AS idr "
            ."FROM INVOICE {$invWhere} AND EI = 'E'",
            $params,
        );
        $get = fn (?object $row, string $key): float => $row !== null ? (float) (($row->$key ?? 0)) : 0.0;

        return [
            'total_invoices' => (int) ($t->total_invoices ?? 0),
            'total_usd' => (float) ($t->total_usd ?? 0),
            'total_idr' => (float) ($t->total_idr ?? 0),
            'paid_usd' => (float) ($t->paid_usd ?? 0),
            'paid_idr' => (float) ($t->paid_idr ?? 0),
            'outstanding_usd' => (float) ($t->outstanding_usd ?? 0),
            'outstanding_idr' => (float) ($t->outstanding_idr ?? 0),
            'unpaid_count' => (int) ($t->unpaid_count ?? 0),
            'import_count' => (int) $get($import, 'cnt'),
            'import_usd' => $get($import, 'usd'),
            'import_idr' => $get($import, 'idr'),
            'export_count' => (int) $get($export, 'cnt'),
            'export_usd' => $get($export, 'usd'),
            'export_idr' => $get($export, 'idr'),
        ];
    }

    /** Python queries.get_sales_monthly_trend — monthly revenue buckets. */
    public function getSalesMonthlyTrend(string $companyFilter = '', int $months = 12): array
    {
        $months = max((int) $months, 1);
        $whereInv = "WHERE DATEOFISSU IS NOT NULL AND DATEOFISSU != '' AND NOINVOICE IS NOT NULL AND NOINVOICE != ''";
        $params = [];

        if ($companyFilter !== '') {
            [$cond, $cparams] = self::companyFilter($companyFilter);
            $whereInv .= " AND REFSHITMEN IN ("
                ."SELECT REFSHITMEN FROM UnifiedShipment u "
                ."LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
                ."LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
                ."WHERE {$cond})";
            $params = $cparams;
        }

        return DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                "SELECT SUBSTR(DATEOFISSU, 1, 7) AS month, "
                ."COUNT(*) AS invoices, "
                ."SUM(GRAND_USD) AS usd, SUM(GRAND_RP) AS idr, "
                ."SUM(COALESCE(BYR_USD, 0)) AS paid_usd, "
                ."SUM(COALESCE(BYR_RP, 0)) AS paid_idr, "
                ."SUM(GRAND_USD - COALESCE(BYR_USD, 0)) AS unpaid_usd, "
                ."SUM(GRAND_RP - COALESCE(BYR_RP, 0)) AS unpaid_idr "
                ."FROM INVOICE {$whereInv} "
                ."GROUP BY month ORDER BY month DESC LIMIT ?",
                array_merge($params, [$months]),
            );
    }

    /** Python queries.get_sales_top_customers — top customers by invoice revenue. */
    public function getSalesTopCustomers(string $companyFilter = '', int $limit = 10): array
    {
        $limit = max((int) $limit, 1);
        $where = "WHERE i.NOINVOICE IS NOT NULL AND i.NOINVOICE != ''";
        $params = [];

        if ($companyFilter !== '') {
            [$cond, $cparams] = self::companyFilter($companyFilter, 'co', 'sh');
            $where .= " AND {$cond}";
            $params = array_merge($params, $cparams);
        }

        $hasUserTable = $this->queryOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='USER'") !== null;
        $userJoin = $hasUserTable ? 'LEFT JOIN USER us ON u.SALES = us._id ' : '';
        $nameCol = $hasUserTable ? 'us.NAME AS salesman_name, ' : 'NULL AS salesman_name, ';
        $groupName = $hasUserTable ? 'us.NAME' : '1';

        return DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                "SELECT CASE WHEN i.EI = 'I' THEN co.NAME ELSE sh.NAME END AS customer, "
                ."i.EI AS type, u.SALES AS salesman, ".$nameCol
                ."COUNT(*) AS invoices, "
                ."SUM(i.GRAND_USD) AS usd, SUM(i.GRAND_RP) AS idr, "
                ."SUM(COALESCE(i.BYR_USD, 0)) AS paid_usd, "
                ."SUM(COALESCE(i.BYR_RP, 0)) AS paid_idr "
                ."FROM INVOICE i "
                ."LEFT JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN "
                .$userJoin
                ."LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
                ."LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
                ."{$where} "
                ."GROUP BY customer, type, salesman, {$groupName} ORDER BY usd DESC LIMIT ?",
                array_merge($params, [$limit]),
            );
    }

    /** Python queries.get_sales_top_salesman — top salesmen by shipments/revenue. */
    public function getSalesTopSalesman(string $companyFilter = '', string $period = 'all', int $limit = 10): array
    {
        if (! in_array($period, ['month', 'year', 'round', 'all'], true)) {
            $period = 'all';
        }

        $whereInv = "WHERE i.NOINVOICE IS NOT NULL AND i.NOINVOICE != '' "
            ."AND u.SALES IS NOT NULL AND u.SALES != ''";
        $params = [];

        if ($period === 'month') {
            $whereInv .= " AND i.DATEOFISSU LIKE '".date('Y-m')."%'";
        } elseif ($period === 'year') {
            $whereInv .= " AND i.DATEOFISSU LIKE '".date('Y')."%'";
        } elseif ($period === 'round') {
            $start = (new DateTime('first day of -11 months'))->format('Y-m');
            $whereInv .= " AND i.DATEOFISSU >= '{$start}'";
        }

        if ($companyFilter !== '') {
            [$cond, $cparams] = self::companyFilter($companyFilter, 'co', 'sh');
            $whereInv .= " AND {$cond}";
            $params = array_merge($params, $cparams);
        }

        $hasUserTable = $this->queryOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='USER'") !== null;
        $userJoin = $hasUserTable ? 'LEFT JOIN USER us ON u.SALES = us._id ' : '';
        $nameCol = $hasUserTable ? 'us.NAME AS salesman_name, ' : 'NULL AS salesman_name, ';
        $groupCol = $hasUserTable ? 'u.SALES, us.NAME' : 'u.SALES';

        return DB::connection($this->connections->connectionName($this->manager->active()))
            ->select(
                "SELECT u.SALES AS salesman, ".$nameCol
                ."COUNT(DISTINCT u.REFSHITMEN) AS shipments, "
                ."COUNT(*) AS invoices, "
                ."SUM(i.GRAND_USD) AS usd, SUM(i.GRAND_RP) AS idr "
                ."FROM INVOICE i "
                ."JOIN UnifiedShipment u ON i.REFSHITMEN = u.REFSHITMEN "
                .$userJoin
                ."LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
                ."LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
                ."{$whereInv} "
                ."GROUP BY {$groupCol} ORDER BY usd DESC LIMIT ?",
                array_merge($params, [$limit]),
            );
    }

    /**
     * Shared INVOICE base WHERE + company subquery (Python _company_filter over
     * UnifiedShipment/CONSIGNEE/SHIPPER via REFSHITMEN).
     */
    private static function invoiceWhere(string $companyFilter = ''): array
    {
        $where = "WHERE NOINVOICE IS NOT NULL AND NOINVOICE != ''";
        $params = [];

        if ($companyFilter !== '') {
            [$cond, $cparams] = self::companyFilter($companyFilter);
            $where .= " AND REFSHITMEN IN ("
                ."SELECT REFSHITMEN FROM UnifiedShipment u "
                ."LEFT JOIN CONSIGNEE co ON u.KD_CONSIG = co.KD_CONSIG "
                ."LEFT JOIN SHIPPER sh ON u.KD_SHIPPER = sh.KD_SHIPPER "
                ."WHERE {$cond})";
            $params = $cparams;
        }

        return [$where, $params];
    }
}
