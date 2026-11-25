{{-- Port of shipments.html — filter selects + search, role-conditional
     columns, dual money cells, pagination footer (when pages > 1). --}}
@extends('layouts.cmt')

@section('content')
<div class="row mb-4 align-items-center">
    <div class="col">
        <h3>{{ $t('Shipments') }}</h3>
        <p class="text-muted-custom">{{ $t('All shipment data (Import & Export)') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>{{ $t('Shipments') }}</span>
        <form method="get" action="/shipments" class="d-flex gap-2 m-0">
            <select name="type" class="form-select form-select-sm" style="width: auto" onchange="this.form.submit()">
                <option value="" {{ $type_filter === '' ? 'selected' : '' }}>{{ $t('All Types') }}</option>
                <option value="import" {{ $type_filter === 'import' ? 'selected' : '' }}>{{ $t('Import') }}</option>
                <option value="export" {{ $type_filter === 'export' ? 'selected' : '' }}>{{ $t('Export') }}</option>
                <option value="other" {{ $type_filter === 'other' ? 'selected' : '' }}>{{ $t('Other') }}</option>
            </select>
            <select name="invoice" class="form-select form-select-sm" style="width: auto" onchange="this.form.submit()">
                <option value="" {{ $invoice_filter === '' ? 'selected' : '' }}>{{ $t('All Invoices') }}</option>
                <option value="unissued" {{ $invoice_filter === 'unissued' ? 'selected' : '' }}>{{ $t('Invoice Un-issued') }}</option>
                <option value="issued" {{ $invoice_filter === 'issued' ? 'selected' : '' }}>{{ $t('Invoice Issued') }}</option>
                <option value="issued_unpaid" {{ $invoice_filter === 'issued_unpaid' ? 'selected' : '' }}>{{ $t('Invoice Issued & Unpaid') }}</option>
                <option value="paid" {{ $invoice_filter === 'paid' ? 'selected' : '' }}>{{ $t('Invoice Paid') }}</option>
            </select>
            <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" style="width: 14rem" placeholder="{{ $t('Search') }}..." aria-label="{{ $t('Search') }}">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    @php
                        $extra = ($type_filter !== '' ? '&type='.urlencode($type_filter) : '')
                            .($invoice_filter !== '' ? '&invoice='.urlencode($invoice_filter) : '');
                        $q = urlencode($search);
                    @endphp
                    <th><a href="?page=1&q={{ $q }}&sort=reference&dir={{ ($sort === 'reference' && $sort_dir === 'desc') ? 'asc' : 'desc' }}{{ $extra }}" class="text-decoration-none text-body">{{ $t('Reference') }}@if($sort === 'reference')<i class="bi bi-caret-{{ $sort_dir === 'asc' ? 'up' : 'down' }}-fill"></i>@endif</a></th>
                    <th>{{ $t('Kind') }}</th>
                    <th><a href="?page=1&q={{ $q }}&sort=customer&dir={{ ($sort === 'customer' && $sort_dir === 'desc') ? 'asc' : 'desc' }}{{ $extra }}" class="text-decoration-none text-body">{{ $t('Customer') }}@if($sort === 'customer')<i class="bi bi-caret-{{ $sort_dir === 'asc' ? 'up' : 'down' }}-fill"></i>@endif</a></th>
                    @if($user->role === 'customer')<th>{{ $t('Invoice No') }}</th>@endif
                    <th><a href="?page=1&q={{ $q }}&sort=date&dir={{ ($sort === 'date' && $sort_dir === 'desc') ? 'asc' : 'desc' }}{{ $extra }}" class="text-decoration-none text-body">{{ $t('Date') }}@if($sort === 'date')<i class="bi bi-caret-{{ $sort_dir === 'asc' ? 'up' : 'down' }}-fill"></i>@endif</a></th>
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
                @forelse($shipments['rows'] as $s)
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
                    <td class="text-nowrap">{{ $s->DATE ?: '-' }}</td>
                    @if($user->role !== 'customer')<td class="text-end">{!! \App\Support\CmtDual::cell($s->OPR_USD, $s->OPR_RP) !!}</td>@endif
                    <td class="text-end">{!! \App\Support\CmtDual::cell($s->INV_USD, $s->INV_RP) !!}</td>
                    <td class="text-end">{!! \App\Support\CmtDual::cell($s->INV_PAY_USD, $s->INV_PAY_IDR) !!}</td>
                    @if($user->role !== 'customer')
                    <td class="text-end">{!! \App\Support\CmtDual::cell($s->DNCN_USD, $s->DNCN_IDR) !!}</td>
                    <td class="text-end">{!! \App\Support\CmtDual::cell($s->DNCN_PAY_USD, $s->DNCN_PAY_IDR) !!}</td>
                    @endif
                </tr>
                @empty
                <tr><td colspan="{{ $user->role !== 'customer' ? 9 : 7 }}" class="text-center text-body-secondary py-4">{{ $t('No shipments found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        @if($shipments['pages'] > 1)
        <nav aria-label="Shipments pagination">
            <ul class="pagination pagination-sm justify-content-center my-2">
                <li class="page-item {{ $shipments['page'] <= 1 ? 'disabled' : '' }}">
                    <a class="page-link" href="?page={{ $shipments['page'] - 1 }}&q={{ $q }}&sort={{ $sort }}&dir={{ $sort_dir }}{{ $extra }}">{{ $t('Previous') }}</a>
                </li>
                @foreach($page_nums as $p)
                <li class="page-item {{ $p === $shipments['page'] ? 'active' : '' }}">
                    <a class="page-link" href="?page={{ $p }}&q={{ $q }}&sort={{ $sort }}&dir={{ $sort_dir }}{{ $extra }}">{{ $p }}</a>
                </li>
                @endforeach
                <li class="page-item {{ $shipments['page'] >= $shipments['pages'] ? 'disabled' : '' }}">
                    <a class="page-link" href="?page={{ $shipments['page'] + 1 }}&q={{ $q }}&sort={{ $sort }}&dir={{ $sort_dir }}{{ $extra }}">{{ $t('Next') }}</a>
                </li>
            </ul>
        </nav>
        @endif
        <div class="px-3 pb-2 small text-body-secondary">
            {{ $t('Page') }} {{ $shipments['page'] }} / {{ $shipments['pages'] }} &middot; {{ $shipments['total'] }} {{ $t('shipments') }}
        </div>
    </div>
</div>
@endsection
