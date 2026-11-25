{{-- Port of data_quality_detail.html — paginated anomaly rows for one type,
     column-driven table, page-total footers (book currency or IDR-converted),
     and the page±2 pagination window. --}}
@extends('layouts.cmt')

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="/data-quality" class="text-decoration-none text-body-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <div>
        <h4 class="m-0">
            {{ $t(\App\Support\CmtAnomalies::LABEL[$detail['type']] ?? $detail['type']) }} &mdash; {{ $detail['company'] }}
        </h4>
        <small class="text-body-secondary">
            {{ $detail['total'] }} {{ $detail['total'] != 1 ? $t('rows affected') : $t('row affected') }}
            @if($detail['pages'] > 1)
            &middot; {{ $t('Page') }} {{ $detail['page'] }} / {{ $detail['pages'] }}
            @endif
        </small>
    </div>
</div>

@if(count($detail['rows']) > 0)
<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-striped table-hover table-sm align-middle mb-0">
            <thead>
                <tr>
                    @foreach($detail['columns'] as $col)
                    <th>{{ $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($detail['rows'] as $row)
                <tr>
                    @foreach($detail['columns'] as $col)
                    @php
                        $numCol = in_array($col, ['JUMLAH', 'TOT_DR', 'TOT_CR', 'GAP']);
                        $cellCls = $numCol ? 'text-end text-nowrap'
                            : ($col === 'URAIAN' ? 'text-break text-truncate' : 'text-nowrap');
                        $cellAttr = $col === 'URAIAN'
                            ? ' style="max-width: 320px;" title="'.e((string) ($row[$col] ?? '')).'"'
                            : '';
                    @endphp
                    <td class="{{ $cellCls }}"{{ $cellAttr }}>
                        @if($numCol)
                            @if(in_array($col, ['TOT_DR', 'TOT_CR', 'GAP']))
                                {{-- JE header totals: book currency (no symbol). --}}
                                @num($row[$col] ?? null)
                            @elseif(in_array($detail['type'], ['orphan', 'bad_dk']))
                                {{-- JE_D.JUMLAH is stored in the row's own currency. --}}
                                @if(in_array(($row['CURRENCY'] ?? ''), ['RP', 'IDR', 'RP.']))
                                    @money($row['JUMLAH'] ?? null, 'idr')
                                @else
                                    @money($row['JUMLAH'] ?? null, 'usd')
                                @endif
                            @else
                                @num($row['JUMLAH'] ?? null)
                            @endif
                        @else
                            {!! ($row[$col] ?? null) !== null && ($row[$col] ?? '') !== '' ? e((string) $row[$col]) : '<span class="text-body-secondary">&mdash;</span>' !!}
                        @endif
                    </td>
                    @endforeach
                </tr>
                @endforeach

                @if(in_array($detail['type'], ['unbalanced', 'bad_date']))
                {{-- Page total over the current page's rows. TOT_DR/TOT_CR share one
                     book currency, so a symbol-free sum keeps the unit honest; the
                     gap column sums to the page's DR/CR difference. --}}
                <tr class="dq-total-row">
                    <td colspan="4">{{ $t('Page total') }}</td>
                    <td class="text-end text-nowrap">@num($detail['sums']['dr'] ?? 0)</td>
                    <td class="text-end text-nowrap">@num($detail['sums']['cr'] ?? 0)</td>
                    <td class="text-end text-nowrap {{ ($detail['sums']['gap'] ?? 0) ? 'text-warning' : '' }}">@num($detail['sums']['gap'] ?? 0)</td>
                </tr>
                @elseif(in_array($detail['type'], ['orphan', 'bad_dk']))
                {{-- JUMLAH rows are mixed-currency; the footer converts foreign rows
                     via EXRATE and sums to IDR (same rule as get_account_detail). --}}
                <tr class="dq-total-row">
                    <td colspan="2">{{ $t('Page total (converted to IDR)') }}</td>
                    <td class="text-end text-nowrap">@money($detail['sums']['amount_idr'] ?? 0, 'idr')</td>
                    <td class="text-end text-nowrap">D: @money($detail['sums']['dr_idr'] ?? 0, 'idr')</td>
                    <td class="text-end text-nowrap">K: @money($detail['sums']['cr_idr'] ?? 0, 'idr')</td>
                    <td colspan="2"></td>
                </tr>
                @endif
            </tbody>
        </table>
        @if($detail['pages'] > 1)
        <nav aria-label="Anomaly rows pagination">
            <ul class="pagination pagination-sm justify-content-center my-2">
                <li class="page-item {{ $detail['page'] <= 1 ? 'disabled' : '' }}">
                    <a class="page-link" href="?type={{ $detail['type'] }}&page={{ $detail['page'] - 1 }}">{{ $t('Previous') }}</a>
                </li>
                @foreach($page_nums as $p)
                <li class="page-item {{ $p === $detail['page'] ? 'active' : '' }}">
                    <a class="page-link" href="?type={{ $detail['type'] }}&page={{ $p }}">{{ $p }}</a>
                </li>
                @endforeach
                <li class="page-item {{ $detail['page'] >= $detail['pages'] ? 'disabled' : '' }}">
                    <a class="page-link" href="?type={{ $detail['type'] }}&page={{ $detail['page'] + 1 }}">{{ $t('Next') }}</a>
                </li>
            </ul>
        </nav>
        @endif
        <div class="px-3 pb-2 small text-body-secondary">
            {{ $t('Page') }} {{ $detail['page'] }} / {{ $detail['pages'] }} &middot; {{ $detail['total'] }} {{ $t('rows') }}
        </div>
    </div>
</div>
@else
<div class="card border-0 py-5 text-center">
    <i class="bi bi-check-circle text-success fs-1 d-block mb-2"></i>
    <h5 class="mb-1">{{ $t('No rows found') }}</h5>
    <p class="text-body-secondary mb-0">{{ $t('This anomaly type has no matching records.') }}</p>
</div>
@endif

<div class="mt-3">
    <a href="/data-quality" class="text-decoration-none">
        <i class="bi bi-arrow-left"></i> {{ $t('Back to Data Quality') }}
    </a>
</div>
@endsection
