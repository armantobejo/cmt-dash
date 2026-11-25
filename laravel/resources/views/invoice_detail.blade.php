{{-- Port of invoice_detail.html — header badges, info card (shipper as
     "CODE · NAME"), line items with the Satuan column, payment summary
     (IDR derived from EXRATE when derivable). --}}
@extends('layouts.cmt')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <a href="/invoices" class="text-body-secondary text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>{{ $t('Invoices') }}</a>
        <h3 class="mb-0 mt-1">{{ $detail['header']->NOINVOICE }}</h3>
    </div>
    <div class="text-end">
        @if($detail['header']->EI === 'I')
            <span class="badge badge-import">{{ $t('Import') }}</span>
        @elseif($detail['header']->EI === 'E')
            <span class="badge badge-export">{{ $t('Export') }}</span>
        @else
            <span class="badge">{{ $detail['header']->EI ?: '?' }}</span>
        @endif
        @php $paidUsd = $detail['header']->BYR_USD ?: 0; $paidIdr = $detail['header']->BYR_RP ?: 0; @endphp
        @if($paidUsd > 0 || $paidIdr > 0)
            <span class="badge bg-success-subtle text-success ms-1">{{ $t('Paid') }}</span>
        @else
            <span class="badge bg-warning-subtle text-warning ms-1">{{ $t('Unpaid') }}</span>
        @endif
    </div>
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold">{{ $t('Invoice Info') }}</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Customer') }}</div>
                <div>{{ $detail['header']->CUSTOMER ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Shipper') }}</div>
                <div>@if($detail['header']->SHIPPER_CODE){{ $detail['header']->SHIPPER_CODE }}@if($detail['header']->SHIPPER_NAME) · {{ $detail['header']->SHIPPER_NAME }}@endif@else-@endif</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Date') }}</div>
                <div>{{ $detail['header']->DATEOFISSU ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Ref.') }}</div>
                <div><a href="/shipments/detail?ref={{ urlencode($detail['header']->REFSHITMEN) }}">{{ $detail['header']->REFSHITMEN ?: '-' }}</a></div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('BL No') }}</div>
                <div>{{ $detail['header']->BLNO ?: $detail['header']->BLNO3 ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Container') }}</div>
                <div>{{ ($detail['header']->CONTAINERN ?? null) ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Vessel') }}</div>
                <div>{{ $detail['header']->VESSELNAME ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Port of Load') }}</div>
                <div>{{ $detail['header']->PORTOFLOAD ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Port of Discharge') }}</div>
                <div>{{ $detail['header']->PORTOFDISC ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('ETD') }}</div>
                <div>{{ $detail['header']->ETDDATE ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('ETA') }}</div>
                <div>{{ $detail['header']->ETADATE ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Exchange Rate') }}</div>
                <div>{{ $detail['header']->EXRATE ? \App\Support\CmtMoney::format($detail['header']->EXRATE, 'idr') : '-' }}</div>
            </div>
            @if($detail['header']->REMARK)
            <div class="col-12">
                <div class="small text-body-secondary">{{ $t('Remark') }}</div>
                <div>{{ $detail['header']->REMARK }}</div>
            </div>
            @endif
            @if($detail['header']->NOTEINV)
            <div class="col-12">
                <div class="small text-body-secondary">{{ $t('Note') }}</div>
                <div>{{ $detail['header']->NOTEINV }}</div>
            </div>
            @endif
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold">{{ $t('Line Items') }}</div>
    @if(count($detail['line_items']))
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>{{ $t('Cost Type') }}</th>
                    <th>{{ $t('Description') }}</th>
                    <th>{{ $t('Currency') }}</th>
                    <th class="text-end">{{ $t('Qty') }}</th>
                    <th>{{ $t('Unit') }}</th>
                    <th class="text-end">{{ $t('Rate') }}</th>
                    <th class="text-end">{{ $t('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($detail['line_items'] as $item)
                <tr>
                    <td>{{ $item->JENISCOST ?: '-' }}</td>
                    <td>{{ $item->DESCRIPTIO ?: $item->NOTE ?: '-' }}</td>
                    <td>{{ $item->CURRENCY ?: '-' }}</td>
                    <td class="text-end">{{ $item->QTY !== null ? \App\Support\CmtPy::scalar($item->QTY) : '-' }}</td>
                    <td>{{ $item->SATUAN_NAME ?: $item->KD_SATUAN ?: '-' }}</td>
                    <td class="text-end">{{ \App\Support\CmtMoney::format($item->SELLING, $item->CURRENCY !== 'RP' ? 'usd' : 'idr') }}</td>
                    <td class="text-end">{{ \App\Support\CmtMoney::format($item->JUMLAH, $item->CURRENCY !== 'RP' ? 'usd' : 'idr') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="card-body text-center p-4 text-muted-custom">{{ $t('No line items.') }}</div>
    @endif
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold">{{ $t('Payment') }}</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="small text-body-secondary">{{ $t('Amount (USD)') }}</div>
                <div class="num">{{ \App\Support\CmtMoney::format($detail['header']->GRAND_USD) }}</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">{{ $t('Amount (IDR)') }}</div>
                @php
                $invRp = ($detail['header']->GRAND_USD !== null && $detail['header']->GRAND_USD != 0 && $detail['header']->EXRATE)
                    ? ($detail['header']->GRAND_USD * $detail['header']->EXRATE)
                    : $detail['header']->GRAND_RP;
                @endphp
                <div class="num">{{ \App\Support\CmtMoney::format($invRp, 'idr') }}</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">{{ $t('Paid (USD)') }}</div>
                <div class="num">{{ \App\Support\CmtMoney::format($detail['header']->BYR_USD ?: 0) }}@if($detail['header']->DIRPKAN) + {{ \App\Support\CmtMoney::format($detail['header']->DIRPKAN) }}@endif</div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">{{ $t('Paid (IDR)') }}</div>
                <div class="num">{{ \App\Support\CmtMoney::format($detail['header']->BYR_RP ?: 0, 'idr') }}@if($detail['header']->DIUSDKAN) + {{ \App\Support\CmtMoney::format($detail['header']->DIUSDKAN, 'idr') }}@endif</div>
            </div>
        </div>
        @if($detail['header']->SELISIH_US || $detail['header']->SELISIH_ID)
        <hr class="my-3">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="small text-body-secondary">{{ $t('Difference (USD)') }}</div>
                <div class="num">{{ \App\Support\CmtMoney::format($detail['header']->SELISIH_US) }}</div>
            </div>
            <div class="col-md-6">
                <div class="small text-body-secondary">{{ $t('Difference (IDR)') }}</div>
                <div class="num">{{ \App\Support\CmtMoney::format($detail['header']->SELISIH_ID, 'idr') }}</div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
