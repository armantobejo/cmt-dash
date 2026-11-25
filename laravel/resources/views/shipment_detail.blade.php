{{-- Port of shipment_detail.html — header card with Gross Profit (comma
     grouping), Operational Cost card (non-customer only; role-conditioned by
     the CONTROLLER exactly like Python), Invoices card whose line-item
     lookup never matches (Python doesn't select K_INVOICE), DN/CN card whose
     items never render (Python doesn't select UNIK), empty state. --}}
@extends('layouts.cmt')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <a href="/shipments" class="text-body-secondary text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>{{ $t('Shipments') }}</a>
        <h3 class="mb-0 mt-1">{{ $ref }}</h3>
    </div>
    <div class="text-end">
        @if($detail['header']->KIND === 'I')
            <span class="badge badge-import">{{ $t('Import') }}</span>
        @elseif($detail['header']->KIND === 'E')
            <span class="badge badge-export">{{ $t('Export') }}</span>
        @elseif($detail['header']->KIND === 'D')
            <span class="badge badge-domestic">{{ $t('Domestic') }}</span>
        @else
            <span class="badge">{{ $detail['header']->KIND }}</span>
        @endif
    </div>
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>{{ $t('Shipment Info') }}</span>
        @if($detail['header']->GP_RP !== null)
        <span class="fs-6 {{ $detail['header']->GP_RP >= 0 ? 'text-success' : 'text-danger' }}">
            {{ $t('Gross Profit') }}: Rp {{ number_format((float) $detail['header']->GP_RP, 0, '.', ',') }}
        </span>
        @endif
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Customer') }}</div>
                <div>{{ $detail['header']->CUSTOMER ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Consignee') }}</div>
                <div>{{ $detail['header']->CONSIGNEE_NAME ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Shipper') }}</div>
                <div>{{ $detail['header']->SHIPPER_NAME ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Date') }}</div>
                <div>{{ $detail['header']->DATE ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('BL No') }}</div>
                <div>{{ $detail['header']->BLNO3 ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('House BL') }}</div>
                <div>{{ $detail['header']->BL_NO ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Master BL') }}</div>
                <div>{{ $detail['header']->MBL_NO ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Container') }}</div>
                <div>{{ $detail['header']->CONTAINERN ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Said to Contain') }}</div>
                <div>{{ $detail['header']->SAIDTOCONT ?: '-' }}</div>
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
                <div>{{ $detail['header']->ETD ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('ETA') }}</div>
                <div>{{ $detail['header']->ETA ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('ATA') }}</div>
                <div>{{ $detail['header']->ATA ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Vessel') }}</div>
                <div>@if($detail['header']->VESSEL_NAME){{ $detail['header']->VESSEL_NAME }}@if($detail['header']->EXVOYAGE) ({{ $detail['header']->EXVOYAGE }})@endif@else-@endif</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Agent') }}</div>
                <div>{{ $detail['header']->AGENT_NAME ?: '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-body-secondary">{{ $t('Shipping Line') }}</div>
                <div>{{ $detail['header']->SHIPPINGMA ?: '-' }}</div>
            </div>
            @if($detail['header']->DESCRIPTION)
            <div class="col-12">
                <div class="small text-body-secondary">{{ $t('Description') }}</div>
                <div>{{ $detail['header']->DESCRIPTION }}</div>
            </div>
            @endif
        </div>
    </div>
</div>

@if(count($detail['opr_headers']))
<div class="card mb-4">
    <div class="card-header fw-semibold">{{ $t('Operational Cost') }}</div>
    <div class="card-body p-0">
        @foreach($detail['opr_headers'] as $opr)
        <div class="border-bottom px-3 py-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <span class="fw-semibold">{{ $opr->NOMOR ?: $opr->UOPRCOST }}</span>
                    <span class="small text-body-secondary ms-2">{{ $opr->TANGGAL ?? '' }}</span>
                </div>
                <div class="text-end">
                    @if($opr->GRAND_USD !== null)<span class="me-3">{{ \App\Support\CmtMoney::format($opr->GRAND_USD) }}</span>@endif
                    @php
                        $oprRp = ($opr->GRAND_USD !== null && $opr->GRAND_USD != 0 && $opr->EXRATE)
                            ? ($opr->GRAND_USD * $opr->EXRATE)
                            : $opr->GRAND_RP;
                    @endphp
                    @if($oprRp !== null){{ \App\Support\CmtMoney::format($oprRp, 'idr') }}@endif
                </div>
            </div>
            @php $items = $detail['opr_items'][$opr->UOPRCOST] ?? []; @endphp
            @if(count($items))
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>{{ $t('Cost Type') }}</th>
                        <th>{{ $t('Description') }}</th>
                        <th>{{ $t('Currency') }}</th>
                        <th class="text-end">{{ $t('Qty') }}</th>
                        <th class="text-end">{{ $t('Rate') }}</th>
                        <th class="text-end">{{ $t('Amount') }}</th>
                        <th>{{ $t('Invoice No') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                    <tr>
                        <td>{{ $item->JENISCOST ?: '-' }}</td>
                        <td>{{ $item->DESCRIPTIO ?: '-' }}</td>
                        <td>{{ $item->CURRENCY ?: '-' }}</td>
                        <td class="text-end">{{ \App\Support\CmtPy::scalar($item->QTY) }}</td>
                        <td class="text-end">{{ \App\Support\CmtMoney::format($item->TSRATE, $item->CURRENCY !== 'RP' ? 'usd' : 'idr') }}</td>
                        <td class="text-end">{{ \App\Support\CmtMoney::format($item->JUMLAH, $item->CURRENCY !== 'RP' ? 'usd' : 'idr') }}</td>
                        <td>{{ $item->INVOICE_NO ?: '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif

@if(count($detail['invoices']))
<div class="card mb-4">
    <div class="card-header fw-semibold">{{ $t('Invoices') }}</div>
    <div class="card-body p-0">
        @foreach($detail['invoices'] as $inv)
        <div class="border-bottom px-3 py-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <span class="fw-semibold fs-6 text-primary" style="letter-spacing: 0.02em;">{{ $inv->NOINVOICE }}</span>
                    <span class="small text-body-secondary ms-2">{{ $inv->DATEOFISSU ?? '' }}</span>
                    @if($inv->SHIPPER_CODE)<span class="small text-body-secondary ms-2">{{ $inv->SHIPPER_CODE }}@if($inv->SHIPPER_NAME ?? null) · {{ $inv->SHIPPER_NAME }}@endif</span>@endif
                    @if($inv->REMARK)<span class="small text-body-secondary ms-2">{{ $inv->REMARK }}</span>@endif
                </div>
                <div class="text-end">
                    @if($inv->GRAND_USD !== null)<span class="me-3">{{ \App\Support\CmtMoney::format($inv->GRAND_USD) }}</span>@endif
                    @php
                        $invRp = ($inv->GRAND_USD !== null && $inv->GRAND_USD != 0 && $inv->EXRATE)
                            ? ($inv->GRAND_USD * $inv->EXRATE)
                            : $inv->GRAND_RP;
                    @endphp
                    @if($invRp !== null){{ \App\Support\CmtMoney::format($invRp, 'idr') }}@endif
                    @php $paidUsd = $inv->BYR_USD ?: 0; $paidIdr = $inv->BYR_RP ?: 0; @endphp
                    @if($paidUsd > 0 || $paidIdr > 0)
                        <span class="badge bg-success-subtle text-success ms-2">{{ $t('Paid') }}</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning ms-2">{{ $t('Unpaid') }}</span>
                    @endif
                </div>
            </div>
            @if(false)
            @php
                // Python never selects K_INVOICE on this page, so this lookup
                // always misses and line items never render. Kept as a
                // documented dead branch (defect-for-defect parity).
            @endphp
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif

@if(count($detail['debit_notes']) || count($detail['credit_notes']))
<div class="card mb-4">
    <div class="card-header fw-semibold">{{ $t('Debit Notes and Credit Notes') }}</div>
    <div class="card-body p-0">
        @foreach($detail['debit_notes'] as $dn)
        <div class="dn-row border-bottom px-3 py-2">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div>
                    <span class="small badge bg-success-subtle text-success me-1">DN</span>
                    <span class="fw-semibold">{{ $dn->NO_REF ?: '-' }}</span>
                    @if($dn->BLNO)<span class="small text-body-secondary ms-2">BL: {{ $dn->BLNO }}</span>@endif
                    <span class="small text-body-secondary ms-2">{{ $dn->TANGGAL ?? '' }}</span>
                </div>
                <div class="text-end">
                    @if($dn->TOTAL !== null)<span class="me-3">{{ \App\Support\CmtMoney::format($dn->TOTAL) }}</span>@endif
                    @php
                        $dnRp = ($dn->TOTAL !== null && $dn->TOTAL != 0 && ($dn->EXRATE ?? null))
                            ? ($dn->TOTAL * $dn->EXRATE)
                            : $dn->TOTAL_IDR;
                    @endphp
                    @if($dnRp !== null){{ \App\Support\CmtMoney::format($dnRp, 'idr') }}@endif
                    @php $paid = ($dn->BAYAR ?: 0) + ($dn->DIRPKAN ?: 0); $paidIdr = ($dn->BAYAR_IDR ?: 0) + ($dn->DIUSDKAN ?: 0); @endphp
                    @if($paid > 0 || $paidIdr > 0)
                        <span class="badge bg-success-subtle text-success ms-2">{{ $t('Received') }}</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning ms-2">{{ $t('Pending') }}</span>
                    @endif
                </div>
            </div>
        </div>
        @endforeach

        @foreach($detail['credit_notes'] as $cn)
        <div class="cn-row border-bottom px-3 py-2">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div>
                    <span class="small badge bg-danger-subtle text-danger me-1">CN</span>
                    <span class="fw-semibold">{{ $cn->NO_REF ?: '-' }}</span>
                    @if($cn->BLNO)<span class="small text-body-secondary ms-2">BL: {{ $cn->BLNO }}</span>@endif
                    <span class="small text-body-secondary ms-2">{{ $cn->TANGGAL ?? '' }}</span>
                </div>
                <div class="text-end">
                    @if($cn->TOTAL !== null)<span class="me-3">{{ \App\Support\CmtMoney::format($cn->TOTAL) }}</span>@endif
                    @php
                        $cnRp = ($cn->TOTAL !== null && $cn->TOTAL != 0 && ($cn->EXRATE ?? null))
                            ? ($cn->TOTAL * (float)($cn->EXRATE))
                            : $cn->TOTAL_IDR;
                    @endphp
                    @if($cnRp !== null){{ \App\Support\CmtMoney::format($cnRp, 'idr') }}@endif
                    @php $paid = ((int) ($cn->BAYAR ?: 0)) + ((int) ($cn->DIRPKAN ?: 0)); $paidIdr = ((int) ($cn->BAYAR_IDR ?: 0)) + ((int) ($cn->DIUSDKAN ?: 0)); @endphp
                    @if($paid > 0 || $paidIdr > 0)
                        <span class="badge bg-success-subtle text-success ms-2">{{ $t('Paid') }}</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning ms-2">{{ $t('Unpaid') }}</span>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

@if(!count($detail['opr_headers']) && !count($detail['invoices']) && !count($detail['debit_notes']) && !count($detail['credit_notes']))
<div class="card mb-4">
    <div class="card-body text-center p-4 text-muted-custom">
        {{ $t('No detail data available.') }}
    </div>
</div>
@endif
@endsection
