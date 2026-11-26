<?php

namespace App\Http\Controllers;

use App\Support\CmtAnomalies;
use App\Support\CompanyDbManager;
use App\Support\CmtQueries;
use App\Support\CmtUser;
use App\Support\Dbf\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Port of Python main.py handlers for /, /shipments, /shipments/detail,
 * /invoices, /invoices/detail — same query params, same guards, same
 * pagination window (page±2), same role-conditional context.
 *
 * The layout context (user, sync_time, DB selector, lang menu, i18n) is
 * provided by CmtViewComposer; controllers pass only page data.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected CmtQueries $queries,
        protected CompanyDbManager $manager,
        protected SyncService $sync,
        protected CmtAnomalies $anomalies,
    ) {
    }

    /** Resolve the current request's user — controllers can be reused across
     *  in-process capture requests, so never cache the user in the controller. */
    private function user(): CmtUser
    {
        return app(CmtUser::class);
    }

    /** Python index() — GET /. */
    public function index(Request $request): View
    {
        $page = $this->intParam($request, 'page', 1);
        $search = (string) $request->query('q', '');
        $sort = (string) $request->query('sort', 'date');
        $sortDir = (string) $request->query('dir', 'desc');
        $company = $this->user()->customerCompany;

        $unpaid = $this->queries->getUnpaidShipments(
            page: $page,
            perPage: (int) config('cmt.unpaid_per_page'),
            search: $search,
            sort: $sort,
            sortDir: $sortDir,
            companyFilter: $company,
        );
        $summary = $this->queries->getSummary($company);
        $topCustomers = $this->user()->role !== 'customer' ? $this->queries->getTopCustomers() : null;

        return view('dashboard', [
            'summary' => $summary,
            'unpaid' => $unpaid,
            'top_customers' => $topCustomers,
            'search' => $search,
            'sort' => $sort,
            'sort_dir' => $sortDir,
            'page_nums' => $this->pageNums($unpaid['page'], $unpaid['pages']),
            'active_page' => 'home',
        ]);
    }

    /** Python shipments() — GET /shipments. */
    public function shipments(Request $request): View
    {
        $page = $this->intParam($request, 'page', 1);
        $search = (string) $request->query('q', '');
        $sort = (string) $request->query('sort', 'date');
        $sortDir = (string) $request->query('dir', 'desc');
        $type = (string) $request->query('type', '');
        $invoice = (string) $request->query('invoice', '');

        $result = $this->queries->getShipments(
            page: $page,
            perPage: (int) config('cmt.shipments_per_page'),
            search: $search,
            sort: $sort,
            sortDir: $sortDir,
            companyFilter: $this->user()->customerCompany,
            typeFilter: $type,
            invoiceFilter: $invoice,
        );

        return view('shipments', [
            'shipments' => $result,
            'search' => $search,
            'sort' => $sort,
            'sort_dir' => $sortDir,
            'type_filter' => $type,
            'invoice_filter' => $invoice,
            'page_nums' => $this->pageNums($result['page'], $result['pages']),
            'active_page' => 'shipments',
        ]);
    }

    /** Python shipment_detail() — GET /shipments/detail?ref=. */
    public function shipmentDetail(Request $request): mixed
    {
        $ref = (string) $request->query('ref', '');
        if ($ref === '') {
            // Python: ref is a required query param -> FastAPI 422.
            return response()->json(['detail' => [[
                'type' => 'missing', 'loc' => ['query', 'ref'],
                'msg' => 'Field required', 'input' => null,
            ]]], 422);
        }

        $detail = $this->queries->getShipmentDetail($ref);
        if ($detail === null) {
            return redirect()->to('/shipments', 303);
        }
        // Customer-role users may only view shipments linked to their company
        // (KG-1 fix: cross-tenant record read). Mirrors invoiceDetail() below.
        if ($this->user()->customerCompany !== '') {
            $header = $detail['header'] ?? null;
            if ($header instanceof \stdClass) {
                $customer = (string) ($header->CUSTOMER ?? '');
            } elseif (is_array($header)) {
                $customer = (string) ($header['CUSTOMER'] ?? '');
            } else {
                $customer = '';
            }
            if (! str_contains(mb_strtoupper($customer), mb_strtoupper($this->user()->customerCompany))) {
                return redirect()->to('/shipments', 303);
            }
        }

        return view('shipment_detail', [
            'detail' => $detail,
            'ref' => $ref,
            'active_page' => 'shipments',
        ]);
    }

    /** Python invoices() — GET /invoices. */
    public function invoices(Request $request): View
    {
        $page = $this->intParam($request, 'page', 1);
        $search = (string) $request->query('q', '');
        $sort = (string) $request->query('sort', 'date');
        $sortDir = (string) $request->query('dir', 'desc');

        $result = $this->queries->getInvoices(
            page: $page,
            perPage: (int) config('cmt.invoices_per_page'),
            search: $search,
            sort: $sort,
            sortDir: $sortDir,
            companyFilter: $this->user()->customerCompany,
        );

        return view('invoices', [
            'invoices' => $result,
            'search' => $search,
            'sort' => $sort,
            'sort_dir' => $sortDir,
            'page_nums' => $this->pageNums($result['page'], $result['pages']),
            'active_page' => 'invoices',
        ]);
    }

    /** Python invoice_detail() — GET /invoices/detail?k=. */
    public function invoiceDetail(Request $request): mixed
    {
        $k = (string) $request->query('k', '');
        if ($k === '') {
            return response()->json(['detail' => [[
                'type' => 'missing', 'loc' => ['query', 'k'],
                'msg' => 'Field required', 'input' => null,
            ]]], 422);
        }

        $detail = $this->queries->getInvoiceDetail($k);
        if ($detail === null) {
            return redirect()->to('/invoices', 303);
        }
        // Customer-role users may only view invoices linked to their company.
        if ($this->user()->customerCompany !== '') {
            $header = $detail['header'] ?? null;
            if ($header instanceof \stdClass) {
                $customer = (string) ($header->CUSTOMER ?? '');
            } elseif (is_array($header)) {
                $customer = (string) ($header['CUSTOMER'] ?? '');
            } else {
                $customer = '';
            }
            if (! str_contains(mb_strtoupper($customer), mb_strtoupper($this->user()->customerCompany))) {
                return redirect()->to('/invoices', 303);
            }
        }

        return view('invoice_detail', [
            'detail' => $detail,
            'k' => $k,
            'active_page' => 'invoices',
        ]);
    }

    /** GET /admin/report/ar-receipt — F-24 A/R receipts. */
    public function arReceipt(Request $request): View
    {
        return $this->report($request, 'receipt');
    }

    /** GET /admin/report/ap-payment — F-25 A/P payments. */
    public function apPayment(Request $request): View
    {
        return $this->report($request, 'payment');
    }

    /** Shared F-24/F-25 page: same table surface, different query + labels. */
    protected function report(Request $request, string $kind): View
    {
        $result = $kind === 'receipt'
            ? $this->queries->getArReceipts(
                page: $this->intParam($request, 'page', 1),
                perPage: (int) config('cmt.receipts_per_page', 50),
                search: (string) $request->query('q', ''),
                sort: (string) $request->query('sort', 'date'),
                sortDir: (string) $request->query('dir', 'desc'),
                companyFilter: $this->user()->customerCompany,
            )
            : $this->queries->getApPayments(
                page: $this->intParam($request, 'page', 1),
                perPage: (int) config('cmt.payments_per_page', 50),
                search: (string) $request->query('q', ''),
                sort: (string) $request->query('sort', 'date'),
                sortDir: (string) $request->query('dir', 'desc'),
                companyFilter: $this->user()->customerCompany,
            );

        return view('report.receipt-payment', [
            'kind' => $kind,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'search' => (string) $request->query('q', ''),
            'sort' => (string) $request->query('sort', 'date'),
            'sort_dir' => (string) $request->query('dir', 'desc'),
            'page_nums' => $this->pageNums($result['page'], $result['pages']),
            'active_page' => $kind === 'receipt' ? 'ar_receipt' : 'ap_payment',
        ]);
    }

    /** Baseline F-22 page: Rate by Destination (static loading placeholder). */
    public function rateByDestination(): View
    {
        return view('rate_by_destination', ['active_page' => 'rate_by_destination']);
    }

    /** Baseline F-23 parity surface: Statement of Account.
     *
     * The Python reference has no `statement_of_account.html` template, so it
     * returns 500 (TemplateNotFound) — captured as-is in the baseline for
     * defect-for-defect parity. The Laravel blade view is therefore
     * intentionally ABSENT so this route also 500s and matches the baseline.
     * Do not restore the view without updating tests/parity/baseline/python.json.
     */
    public function statementOfAccount(): View
    {
        return view('statement_of_account', ['active_page' => 'statement_of_account']);
    }

    /** Python data_quality() — GET /data-quality, superadmin only. */
    public function dataQuality(): View
    {
        // Scope to the company selected in the header DB selector — the page
        // shows only the anomalies of the currently active company DB.
        $activeCompany = mb_strtoupper($this->manager->active());

        return view('data_quality', [
            'anomalies' => $this->anomalies->rollup($activeCompany),
            'active_company' => $activeCompany,
            'active_page' => 'data_quality',
        ]);
    }

    /** Python data_quality_detail() — GET /data-quality/detail, superadmin only. */
    public function dataQualityDetail(Request $request): View|RedirectResponse
    {
        $type = (string) $request->query('type', '');
        if (! array_key_exists($type, CmtAnomalies::SPECS)) {
            return redirect()->to('/data-quality', 303);
        }

        $detail = $this->anomalies->detail($type, $this->intParam($request, 'page', 1));

        return view('data_quality_detail', [
            'detail' => $detail,
            'page_nums' => $this->pageNums($detail['page'], $detail['pages']),
            'active_page' => 'data_quality',
        ]);
    }

    /** base_path() unless the config value is already absolute (tests rebind it). */
    protected function resolvePath(string $path): string
    {
        return preg_match('/^([A-Za-z]:)?[\\\\\/]/', $path) === 1 ? $path : base_path($path);
    }

    /**
     * Port of Python main.py::trigger_sync — POST /sync, management+ only.
     * Runs the real sync (native PHP backend; DBF_SYNC_BACKEND=python shells
     * out to tools/sync_db.py) and returns the same JSON summary contract.
     */
    public function sync(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        if (! $request->cookies->has('csrf_token')) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }
        $dbfDir = $this->resolvePath((string) config('cmt.dbf_dir'));
        $dataDir = $this->resolvePath((string) config('cmt.data_dir'));

        if ((string) config('cmt.sync_backend') === 'python') {
            // Escape hatch (dbf-reading.md §7): delegate to the Python script.
            $exit = \Illuminate\Support\Facades\Artisan::call('dbf:sync');
            $output = explode("\n", trim(\Illuminate\Support\Facades\Artisan::output()));

            return response()->json([
                'ok' => $exit === 0,
                'triggered_by' => $this->user()->username,
                'detail' => $exit === 0 ? 'Sync OK.' : 'Sync script failed.',
                'stdout_tail' => array_slice($output, -10),
                'stderr_tail' => [],
            ]);
        }

        // Release this request's own cached company connections: the sync's
        // final rename/replacement of the live file fails on Windows while a
        // PDO handle to it is open (Python sidesteps this by syncing from a
        // subprocess on a POSIX host).
        foreach (array_keys((array) config('database.connections')) as $name) {
            if (str_starts_with((string) $name, 'cmt_')) {
                \Illuminate\Support\Facades\DB::purge((string) $name);
            }
        }

        $result = $this->sync->sync($dbfDir, $dataDir);

        // Python: detail = stderr or 'Sync script failed with no output.' on
        // failure; stderr_tail = the subprocess's stderr. The native backend
        // has no stderr stream — error/fatal lines stand in for it.
        $errors = array_values(array_filter(
            $result['lines'],
            fn (string $l) => str_contains($l, 'ERROR') || str_contains($l, 'FATAL') || str_contains($l, 'MISMATCH')
        ));
        $detail = $result['ok']
            ? 'Sync OK.'
            : ($errors !== [] ? implode("\n", array_slice($errors, -10)) : 'Sync script failed with no output.');

        return response()->json([
            'ok' => $result['ok'],
            'triggered_by' => $this->user()->username,
            'detail' => $detail,
            'stdout_tail' => array_slice($result['lines'], -10),
            'stderr_tail' => array_slice($errors, -10),
        ]);
    }

    /** Python sales() — GET /sales: summary cards + monthly trend + top customers + top salesmen. */
    /** Python page_nums: page-2 .. page+2 clamped to [1, pages]. */
    protected function pageNums(int $page, int $pages): array
    {
        $from = max(1, $page - 2);
        $to = min($pages, $page + 2);

        return range($from, $to);
    }

    /** Python Query(1, ge=1): non-numeric / <1 falls back to 1 via max(); FastAPI would 422 on garbage — captured by the parity harness. */
    protected function intParam(Request $request, string $name, int $default): int
    {
        $value = $request->query($name, (string) $default);

        return max((int) $value, 1);
    }
}
