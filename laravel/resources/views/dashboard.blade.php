{{-- Port of index.html — dashboard: stat cards, unpaid-shipments card with
     search + role-conditional columns, top-customer cards (non-customer). --}}
@extends('layouts.cmt')

@section('content')
<div class="row mb-4 align-items-center">
    <div class="col">
        <h3>{{ $t('Dashboard') }}</h3>
        <p class="text-muted-custom">{{ $t('Overview of system data') }}</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-value">{{ $summary['shipments'] }}</span>
            <span class="stat-label">{{ $t('Total Shipments') }}</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-value">{{ $summary['imports'] }}</span>
            <span class="stat-label">{{ $t('Imports') }}</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-value">{{ $summary['exports'] }}</span>
            <span class="stat-label">{{ $t('Exports & Others') }}</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-value">{{ $summary['invoices'] }}</span>
            <span class="stat-label">{{ $t('Invoices') }}</span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-value">{{ $unpaid['total'] }}</span>
            <span class="stat-label">{{ $t('Total Unpaid') }}</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-value">{{ \App\Support\CmtMoney::format($unpaid['total_usd'], 'usd', 'abbr') }}</span>
            <span class="stat-label">{{ $t('Total Unpaid USD') }}</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <span class="stat-value">{{ \App\Support\CmtMoney::format($unpaid['total_idr'], 'idr', 'abbr') }}</span>
            <span class="stat-label">{{ $t('Total Unpaid IDR') }}</span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>{{ $t('Unpaid Shipments') }}</span>
        <div class="d-flex align-items-center gap-2">
            <form method="get" action="/" class="d-flex gap-2 m-0">
                <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" style="width: 14rem" placeholder="{{ $t('Search') }}..." aria-label="{{ $t('Search') }}">
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            </form>
            <a href="/shipments" class="btn btn-sm btn-link">{{ $t('View All') }} <i class="bi bi-arrow-right"></i></a>
        </div>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    @php
                        $nextDir = ($sort === 'reference' && $sort_dir === 'desc') ? 'asc' : 'desc';
                        $q = urlencode($search);
                    @endphp
                    <th><a href="?page=1&q={{ $q }}&sort=reference&dir={{ $nextDir }}" class="text-decoration-none text-body">{{ $t('Reference') }}@if($sort === 'reference')<i class="bi bi-caret-{{ $sort_dir === 'asc' ? 'up' : 'down' }}-fill"></i>@endif</a></th>
                    <th>{{ $t('Kind') }}</th>
                    @php $nextDir = ($sort === 'customer' && $sort_dir === 'desc') ? 'asc' : 'desc'; @endphp
                    <th><a href="?page=1&q={{ $q }}&sort=customer&dir={{ $nextDir }}" class="text-decoration-none text-body">{{ $t('Customer') }}@if($sort === 'customer')<i class="bi bi-caret-{{ $sort_dir === 'asc' ? 'up' : 'down' }}-fill"></i>@endif</a></th>
                    @if($user->role === 'customer')<th>{{ $t('Invoice No') }}</th>@endif
                    @php $nextDir = ($sort === 'date' && $sort_dir === 'desc') ? 'asc' : 'desc'; @endphp
                    <th><a href="?page=1&q={{ $q }}&sort=date&dir={{ $nextDir }}" class="text-decoration-none text-body">{{ $t('Date') }}@if($sort === 'date')<i class="bi bi-caret-{{ $sort_dir === 'asc' ? 'up' : 'down' }}-fill"></i>@endif</a></th>
                    @if($user->role !== 'customer')<th class="text-end">{{ $t('Opr Cost') }}</th>@endif
                    <th class="text-end">{{ $t('Invoice') }}</th>
                    <th class="text-end">{{ $t('Inv Payment') }}</th>
                    @if($user->role !== 'customer')
                    <th class="text-end">DNCN</th>
                    <th class="text-end">{{ $t('DNCN Settlement') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($unpaid['rows'] as $s)
                <tr>
                    <td>@if($user->role !== 'customer')<a href="/shipments/detail?ref={{ urlencode($s->REFSHITMEN) }}" class="text-decoration-none">{{ $s->REFSHITMEN ?: '-' }}</a>@else{{ $s->REFSHITMEN ?: '-' }}@endif</td>
                    <td>
                        @if($s->KIND === 'I')<span class="badge badge-import">{{ $t('Import') }}</span>
                        @elseif($s->KIND === 'E')<span class="badge badge-export">{{ $t('Export') }}</span>
                        @elseif($s->KIND === 'D')<span class="badge badge-domestic">{{ $t('Domestic') }}</span>
                        @elseif($s->KIND === 'T')<span class="badge badge-trucking">{{ $t('Trucking') }}</span>
                        @elseif($s->KIND === 'B')<span class="badge badge-breakbulk">{{ $t('Breakbulk') }}</span>
                        @else<span class="badge">{{ $s->KIND }}</span>
                        @endif
                    </td>
                    <td>{{ $s->CUSTOMER ?: '-' }}</td>
                    @if($user->role === 'customer')<td>{{ $s->NOINVOICE ?: '-' }}</td>@endif
                    <td>{{ $s->DATE ?: '-' }}</td>
                    @if($user->role !== 'customer')<td class="text-end">{!! \App\Support\CmtDual::cell($s->OPR_USD, $s->OPR_RP) !!}</td>@endif
                    <td class="text-end">{!! \App\Support\CmtDual::cell($s->INV_USD, $s->INV_RP) !!}</td>
                    <td class="text-end">{!! \App\Support\CmtDual::cell($s->INV_PAY_USD, $s->INV_PAY_IDR) !!}</td>
                    @if($user->role !== 'customer')
                    <td class="text-end">{!! \App\Support\CmtDual::cell($s->DNCN_USD, $s->DNCN_IDR) !!}</td>
                    <td class="text-end">{!! \App\Support\CmtDual::cell($s->DNCN_PAY_USD, $s->DNCN_PAY_IDR) !!}</td>
                    @endif
                </tr>
                @empty
                <tr><td colspan="{{ $user->role !== 'customer' ? 9 : 7 }}" class="text-center text-body-secondary py-4">{{ $t('No unpaid shipments found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-3 pb-2 small text-body-secondary">
            {{ $t('Page') }} {{ $unpaid['page'] }} / {{ $unpaid['pages'] }} &middot; {{ $unpaid['total'] }} {{ $t('shipments') }}
        </div>
    </div>
</div>
@if($top_customers)
<div class="row g-3 mt-2 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">{{ $t('Top Import Customers') }}</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>#</th><th>{{ $t('Customer') }}</th><th class="text-end">{{ $t('Shipments') }}</th></tr></thead>
                    <tbody>
                    @forelse($top_customers['import'] as $row)
                        <tr><td>{{ $loop->iteration }}</td><td>{{ $row->name }}</td><td class="text-end">{{ $row->cnt }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-body-secondary py-3">{{ $t('No data.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">{{ $t('Top Export Customers') }}</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>#</th><th>{{ $t('Customer') }}</th><th class="text-end">{{ $t('Shipments') }}</th></tr></thead>
                    <tbody>
                    @forelse($top_customers['export'] as $row)
                        <tr><td>{{ $loop->iteration }}</td><td>{{ $row->name }}</td><td class="text-end">{{ $row->cnt }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-body-secondary py-3">{{ $t('No data.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
