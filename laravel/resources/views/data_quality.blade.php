{{-- Port of data_quality.html — GL anomaly rollup for the active company:
     badge table, stat cards, and the "what this means" explainer. --}}
@extends('layouts.cmt')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="m-0">{{ $t('Data Quality') }} &mdash; {{ $active_company }}</h4>
        <small class="text-body-secondary">{{ $t('GL anomalies detected in the synced ledger data') }}</small>
    </div>
</div>

@if(count($anomalies) > 0)
<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-striped table-hover table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>{{ $t('Anomaly') }}</th>
                    <th class="text-end">{{ $t('Count') }}</th>
                    <th class="text-end">{{ $t('Sample Ref') }}</th>
                    <th class="text-end">{{ $t('Sample Date') }}</th>
                    <th class="text-end">{{ $t('Sample Description') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($anomalies as $a)
                <tr class="anomaly-row-{{ $a['type'] }}">
                    <td>
                        <a href="/data-quality/detail?type={{ $a['type'] }}" class="text-decoration-none" aria-label="{{ $t('View') }} {{ $t(\App\Support\CmtAnomalies::LABEL[$a['type']] ?? $a['type']) }} {{ $t('rows') }}">
                            <span class="badge bg-{{ \App\Support\CmtAnomalies::BADGE_CLASS[$a['type']] ?? 'secondary' }} mb-1">{{ $t(\App\Support\CmtAnomalies::LABEL[$a['type']] ?? $a['type']) }}</span>
                        </a>
                    </td>
                    <td class="text-end">
                        <strong>{{ $a['count'] }}</strong>
                        @if($a['count'] > 1)
                        <span class="text-body-secondary small">{{ $t('rows') }}</span>
                        @else
                        <span class="text-body-secondary small">{{ $t('row') }}</span>
                        @endif
                    </td>
                    <td class="text-end text-break">{!! ($a['sample_ref'] ?? '') !== '' ? e($a['sample_ref']) : '<span class="text-body-secondary">—</span>' !!}</td>
                    <td class="text-end text-nowrap">{!! ($a['sample_date'] ?? '') !== '' ? e($a['sample_date']) : '<span class="text-body-secondary">—</span>' !!}</td>
                    <td class="text-break text-truncate" style="max-width: 280px;" title="{{ $a['sample_desc'] ?? '' }}">{!! ($a['sample_desc'] ?? '') !== '' ? e($a['sample_desc']) : '<span class="text-body-secondary">—</span>' !!}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mt-3">
    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card h-100">
            <span class="stat-label">{{ $t('Total anomalies') }}</span>
            <span class="stat-value" style="font-size: 1.5rem; color: var(--color-export);">{{ count($anomalies) }}</span>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card h-100">
            <span class="stat-label">{{ $t('Unbalanced vouchers') }}</span>
            <span class="stat-value" style="font-size: 1.5rem; color: var(--color-warning);">
                {{ collect($anomalies)->where('type', 'unbalanced')->sum('count') }}
            </span>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card h-100">
            <span class="stat-label">{{ $t('Orphan detail rows') }}</span>
            <span class="stat-value" style="font-size: 1.5rem; color: var(--color-warning);">
                {{ collect($anomalies)->where('type', 'orphan')->sum('count') }}
            </span>
        </div>
    </div>
</div>

<div class="alert alert-warning mt-3 mb-0 d-flex align-items-start gap-2">
    <i class="bi bi-info-circle-fill fs-5 mt-1"></i>
    <div>
        <strong class="d-block">{{ $t('What this means') }}</strong>
        <ul class="mb-0 small">
            <li><strong>{{ $t('Unbalanced') }}:</strong> Debit &ne; Credit on a voucher header. The books don't balance.</li>
            <li><strong>{{ $t('Orphan') }}:</strong> JE_D detail rows with no parent JE header. Lost audit trail.</li>
            <li><strong>{{ $t('Bad date') }}:</strong> Journal dates outside 2000-2099 or not in YYYY-MM-DD format. Likely FoxPro entry errors.</li>
            <li><strong>{{ $t('Bad debit/credit') }}:</strong> DK column values other than D/K. Ambiguous posting direction.</li>
        </ul>
        <span class="d-block mt-1 small text-body-secondary">{{ $t('Filter the DBF source or fix at the FoxPro app level. Re-sync after fixes.') }}</span>
    </div>
</div>
@else
<div class="card border-0 py-5 text-center">
    <i class="bi bi-check-circle text-success fs-1 d-block mb-2"></i>
    <h5 class="mb-1">{{ $t('No GL anomalies detected') }}</h5>
    <p class="text-body-secondary mb-0">{{ $t('All synced journals balance, have headers, valid dates, and proper debit/credit flags.') }}</p>
</div>
@endif
@endsection
