{{-- Port of invoices.html — search + sort links; the row links to
     /invoices/detail?k={{ inv.K_INVOICE }} which is ALWAYS EMPTY (Python
     selects rowid_ as rowid_, never K_INVOICE) — reproduced defect-for-defect. --}}
@extends('layouts.cmt')

@section('content')
<div class="row mb-3 align-items-center">
    <div class="col">
        <h3 class="mb-0">{{ $t('Invoices') }}</h3>
        <span class="text-body-secondary small">{{ $invoices['total'] }} {{ $t('results') }}</span>
    </div>
</div>

<div class="card">
    <div class="card-header px-3 py-2">
        <form method="get" action="/invoices" class="d-flex gap-2">
            <div class="input-group input-group-sm" style="max-width:320px">
                <input type="text" name="q" class="form-control" placeholder="{{ $t('Search') }}…" value="{{ $search }}">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </div>
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $sort_dir }}">
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>
                        <a href="/invoices?q={{ $search }}&sort=reference&dir={{ ($sort === 'reference' && $sort_dir === 'desc') ? 'asc' : 'desc' }}" class="text-decoration-none text-body">
                            {{ $t('Ref.') }} @if($sort === 'reference')<i class="bi bi-chevron-{{ $sort_dir === 'desc' ? 'down' : 'up' }} small"></i>@endif
                        </a>
                    </th>
                    <th>{{ $t('Kind') }}</th>
                    <th>{{ $t('Customer') }}</th>
                    <th>{{ $t('Shipper') }}</th>
                    <th>
                        <a href="/invoices?q={{ $search }}&sort=date&dir={{ ($sort === 'date' && $sort_dir === 'desc') ? 'asc' : 'desc' }}" class="text-decoration-none text-body">
                            {{ $t('Date') }} @if($sort === 'date')<i class="bi bi-chevron-{{ $sort_dir === 'desc' ? 'down' : 'up' }} small"></i>@endif
                        </a>
                    </th>
                    <th>{{ $t('No. Invoice') }}</th>
                    <th class="text-end">{{ $t('Amount (USD)') }}</th>
                    <th class="text-end">{{ $t('Amount (IDR)') }}</th>
                    <th class="text-end">{{ $t('Paid (USD)') }}</th>
                    <th class="text-end">{{ $t('Paid (IDR)') }}</th>
                    <th>{{ $t('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($invoices['rows'] as $inv)
                <tr>
                    <td><a href="/shipments/detail?ref={{ urlencode($inv->REFSHITMEN) }}" class="text-decoration-none">{{ $inv->REFSHITMEN ?: '-' }}</a></td>
                    <td>
                        @if($inv->EI === 'I')
                            <span class="badge bg-info-subtle text-info">{{ $t('Import') }}</span>
                        @elseif($inv->EI === 'E')
                            <span class="badge bg-success-subtle text-success">{{ $t('Export') }}</span>
                        @else
                            {{ $inv->EI ?: '-' }}
                        @endif
                    </td>
                    <td>{{ $inv->CUSTOMER ?: '-' }}</td>
                    <td>@if($inv->SHIPPER_CODE ?? null){{ $inv->SHIPPER_CODE }}@if(!empty($inv->SHIPPER_NAME)) · {{ $inv->SHIPPER_NAME }}@endif@else-@endif</td>
                    <td class="text-nowrap">{{ $inv->DATEOFISSU ?: '-' }}</td>
                    <td><a href="/invoices/detail?k={{ $inv->K_INVOICE ?? '' }}" class="fw-semibold text-primary text-decoration-none">{{ $inv->NOINVOICE }}</a></td>
                    <td class="text-end">{{ \App\Support\CmtMoney::format($inv->GRAND_USD) }}</td>
                    <td class="text-end">{{ \App\Support\CmtMoney::format($inv->GRAND_RP, 'idr') }}</td>
                    <td class="text-end">{{ \App\Support\CmtMoney::format($inv->BYR_USD) }}</td>
                    <td class="text-end">{{ \App\Support\CmtMoney::format($inv->BYR_RP, 'idr') }}</td>
                    <td>
                        @php $paidUsd = $inv->BYR_USD ?: 0; $paidIdr = $inv->BYR_RP ?: 0; @endphp
                        @if($paidUsd > 0 || $paidIdr > 0)
                            <span class="badge bg-success-subtle text-success">{{ $t('Paid') }}</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning">{{ $t('Unpaid') }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="11" class="text-center p-4 text-muted-custom">{{ $t('No invoices.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($invoices['pages'] > 1)
    <div class="card-footer d-flex justify-content-between align-items-center py-2">
        <span class="small text-body-secondary">
            {{ $t('Page') }} {{ $invoices['page'] }} / {{ $invoices['pages'] }}
        </span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                @if($invoices['page'] > 1)
                <li class="page-item">
                    <a class="page-link" href="/invoices?q={{ $search }}&sort={{ $sort }}&dir={{ $sort_dir }}&page={{ $invoices['page'] - 1 }}">&laquo;</a>
                </li>
                @endif
                @foreach($page_nums as $pn)
                <li class="page-item {{ $pn === $invoices['page'] ? 'active' : '' }}">
                    <a class="page-link" href="/invoices?q={{ $search }}&sort={{ $sort }}&dir={{ $sort_dir }}&page={{ $pn }}">{{ $pn }}</a>
                </li>
                @endforeach
                @if($invoices['page'] < $invoices['pages'])
                <li class="page-item">
                    <a class="page-link" href="/invoices?q={{ $search }}&sort={{ $sort }}&dir={{ $sort_dir }}&page={{ $invoices['page'] + 1 }}">&raquo;</a>
                </li>
                @endif
            </ul>
        </nav>
    </div>
    @endif
</div>
@endsection
