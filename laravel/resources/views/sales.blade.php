{{-- Port of sales.html — sales dashboard: summary cards, monthly trend,
     top customers, top salesmen. Sort/sort_dir defaults supplied by
     SalesController (date/desc); period defaults to 'all'. --}}
@extends('layouts.cmt')

@section('content')
<h3 class="mb-4">{{ $t('Sales Dashboard') }}</h3>

<div class="row g-3 mb-4">
    @foreach([
        ['label' => $t('Total Invoices'), 'key' => 'total_invoices', 'unit' => 'invoices', 'cls' => ''],
        ['label' => $t('Outstanding'), 'key' => 'outstanding', 'unit' => 'unpaid', 'cls' => 'text-warning'],
        ['label' => $t('Import'), 'key' => 'import', 'unit' => 'invoices', 'cls' => 'text-info'],
        ['label' => $t('Export'), 'key' => 'export', 'unit' => 'invoices', 'cls' => 'text-success'],
    ] as $card)
    @php
        $card['value'] = match ($card['key']) {
            'total_invoices' => $summary['total_invoices'] ?? 0,
            'outstanding' => $summary['unpaid_count'] ?? 0,
            'import' => $summary['import_count'] ?? 0,
            'export' => $summary['export_count'] ?? 0,
            default => 0,
        };
    @endphp
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="small text-body-secondary">{{ $card['label'] }}</div>
                @if($card['label'] === $t('Total Invoices'))
                    <div class="fs-4 fw-semibold">{{ $card['value'] }}</div>
                @else
                    <div class="fs-6 fw-semibold {{ $card['cls'] }}">@money($summary[$card['key'] . '_usd'] ?? null, 'usd')</div>
                    <div class="small text-body-secondary">@money($summary[$card['key'] . '_idr'] ?? null, 'idr')</div>
                    <div class="small text-body-secondary mt-1">{{ $card['value'] }} {{ $t($card['unit']) }}</div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>

@if(!empty($trend))
<div class="card mb-4">
    <div class="card-header fw-semibold">{{ $t('Monthly Revenue') }}</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>{{ $t('Month') }}</th>
                    <th class="text-end">{{ $t('Invoices') }}</th>
                    <th class="text-end">{{ $t('Revenue (USD)') }}</th>
                    <th class="text-end">{{ $t('Revenue (IDR)') }}</th>
                    <th class="text-end">{{ $t('Paid (USD)') }}</th>
                    <th class="text-end">{{ $t('Paid (IDR)') }}</th>
                    <th class="text-end">{{ $t('Unpaid (USD)') }}</th>
                    <th class="text-end">{{ $t('Unpaid (IDR)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($trend as $row)
                <tr>
                    <td class="fw-semibold">{{ $row['month'] ?? '-' }}</td>
                    <td class="text-end">{{ $row['invoices'] ?? 0 }}</td>
                    <td class="text-end">@money($row['usd'] ?? '-', 'usd')</td>
                    <td class="text-end">@money($row['idr'] ?? '-', 'idr')</td>
                    <td class="text-end">@money($row['paid_usd'] ?? '-', 'usd')</td>
                    <td class="text-end">@money($row['paid_idr'] ?? '-', 'idr')</td>
                    <td class="text-end text-danger">@money($row['unpaid_usd'] ?? '-', 'usd')</td>
                    <td class="text-end text-danger">@money($row['unpaid_idr'] ?? '-', 'idr')</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@if(!empty($top_customers))
<div class="card mb-4">
    <div class="card-header fw-semibold">{{ $t('Top Customers') }}</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>{{ $t('Customer') }}</th>
                    <th>{{ $t('Type') }}</th>
                    <th>{{ $t('Salesman') }}</th>
                    <th class="text-end">{{ $t('Invoices') }}</th>
                    <th class="text-end">{{ $t('Total (USD)') }}</th>
                    <th class="text-end">{{ $t('Total (IDR)') }}</th>
                    <th class="text-end">{{ $t('Paid (USD)') }}</th>
                    <th class="text-end">{{ $t('Paid (IDR)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($top_customers as $row)
                <tr>
                    <td>{{ $row['customer'] ?? '-' }}</td>
                    <td>
                        @if($row['type'] === 'I')
                            <span class="badge text-bg-primary">{{ $t('Import') }}</span>
                        @else
                            <span class="badge text-bg-success">{{ $t('Export') }}</span>
                        @endif
                    </td>
                    <td>{{ $row['salesman_name'] ?? ($row['salesman'] ?? '-') }}</td>
                    <td class="text-end">{{ $row['invoices'] ?? 0 }}</td>
                    <td class="text-end">@money($row['usd'] ?? null, 'usd')</td>
                    <td class="text-end">@money($row['idr'] ?? null, 'idr')</td>
                    <td class="text-end">@money($row['paid_usd'] ?? null, 'usd')</td>
                    <td class="text-end">@money($row['paid_idr'] ?? null, 'idr')</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">{{ $t('Top Salesman') }}</span>
        <div class="btn-group btn-group-sm" role="group">
            <a href="/sales?p=month" class="btn btn-outline-secondary {{ $period === 'month' ? 'active' : '' }}">{{ $t('Month') }}</a>
            <a href="/sales?p=year" class="btn btn-outline-secondary {{ $period === 'year' ? 'active' : '' }}">{{ $t('Year') }}</a>
            <a href="/sales?p=round" class="btn btn-outline-secondary {{ $period === 'round' ? 'active' : '' }}">{{ $t('Year Round') }}</a>
            <a href="/sales?p=all" class="btn btn-outline-secondary {{ $period === 'all' ? 'active' : '' }}">{{ $t('All Time') }}</a>
        </div>
    </div>
    <div class="card-body p-0">
        @if(!empty($top_salesman))
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ $t('Salesman') }}</th>
                    <th class="text-end">{{ $t('Shipments') }}</th>
                    <th class="text-end">{{ $t('Invoices') }}</th>
                    <th class="text-end">{{ $t('Total (USD)') }}</th>
                    <th class="text-end">{{ $t('Total (IDR)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($top_salesman as $i => $row)
                <tr>
                    <td class="text-body-secondary">{{ $i + 1 }}</td>
                    <td class="fw-semibold">{{ $row['salesman_name'] ?? ($row['salesman'] ?? '-') }}</td>
                    <td class="text-end">{{ $row['shipments'] ?? 0 }}</td>
                    <td class="text-end">{{ $row['invoices'] ?? 0 }}</td>
                    <td class="text-end">@money($row['usd'] ?? null, 'usd')</td>
                    <td class="text-end">@money($row['idr'] ?? null, 'idr')</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="text-center p-4 text-muted-custom">{{ $t('No sales data available.') }}</div>
        @endif
    </div>
</div>

@if(empty($trend) && empty($top_customers))
<div class="card mb-4">
    <div class="card-body text-center p-4 text-muted-custom">{{ $t('No sales data available.') }}</div>
</div>
@endif
@endsection
