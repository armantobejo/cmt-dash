<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function accounts(Request $request): \Illuminate\View\View
    {
        $db = (string) $request->cookie('cmt_db', 'CMT');
        $accounts = $db === 'CME'
            ? [['code' => '1101', 'name' => 'CME CASH', 'type' => 'Detail', 'normal' => 'D', 'active' => 'Inactive']]
            : [
                ['code' => '1101', 'name' => 'CASH IN BANK', 'type' => 'Detail', 'normal' => 'D', 'active' => 'Inactive'],
                ['code' => '4000', 'name' => 'REVENUE', 'type' => 'Group', 'normal' => 'K', 'active' => 'Inactive'],
                ['code' => '9999', 'name' => 'SUSPENSE', 'type' => 'Detail', 'normal' => 'D', 'active' => 'Inactive'],
            ];
        $sort = (string) $request->query('sort', 'code');
        $dir = (string) $request->query('dir', 'asc');

        return view('ledger', [
            'accounts' => $accounts,
            'active_page' => 'ledger',
            'sort' => $sort,
            'sort_dir' => $dir,
        ]);
    }

    public function detail(Request $request): \Illuminate\View\View
    {
        $k = (string) $request->query('k', '1101');
        $rows = [
            ['2026-02-02', 'GL', 'JV-002', 'Dr side', '$100.00', '@15000', '', 'USD'],
            ['2026-02-01', 'GL', 'JV-001', 'Deposit', 'Rp 5.000.000', '', '', 'RP'],
        ];
        $sort = (string) $request->query('sort', 'date');
        $dir = (string) $request->query('dir', 'desc');

        return view('ledger_detail', [
            'k' => $k, 'name' => 'CASH IN BANK', 'normal' => 'D', 'active' => 'Inactive',
            'rows' => $rows, 'active_page' => 'ledger',
            'sort' => $sort, 'sort_dir' => $dir,
        ]);
    }

    public function entriesStore(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function entriesIndex(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function entriesShow(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
