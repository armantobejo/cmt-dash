<?php

namespace App\Http\Controllers;

use App\Support\CmtQueries;
use App\Support\CmtUser;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function __construct(private CmtQueries $queries)
    {
    }

    public function sales(Request $request): View
    {
        $period = strtolower((string) $request->query('p', 'all'));
        if (! in_array($period, ['month', 'year', 'round', 'all'], true)) {
            $period = 'all';
        }
        $company = app(CmtUser::class)->customerCompany;

        // DB::select() returns stdClass rows; blade uses array access ($row['x']).
        $rowsToArrays = fn (array $rows): array => array_map(
            fn ($row) => is_object($row) ? (array) $row : $row,
            $rows,
        );

        return view('sales', [
            'active_page' => 'sales',
            'period' => $period,
            'company' => $company,
            'summary' => $this->queries->getSalesSummary($company),
            'trend' => $rowsToArrays($this->queries->getSalesMonthlyTrend($company)),
            'top_customers' => $rowsToArrays($this->queries->getSalesTopCustomers($company)),
            'top_salesman' => $rowsToArrays($this->queries->getSalesTopSalesman($company, $period)),
        ]);
    }
}
