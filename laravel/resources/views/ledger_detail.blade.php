{{-- Port of ledger_detail.html — journal detail for one account. Sort/sort_dir
     defaults supplied by LedgerController (date/desc). --}}
@extends('layouts.cmt')

@section('content')
<div class="row mb-3 align-items-center">
    <div class="col">
        <h3 class="mb-0">
            <a href="/ledger" class="text-decoration-none text-body">{{ $t('Chart of Accounts') }}</a>
            <i class="bi bi-chevron-right mx-1 small text-muted"></i>
            <span class="text-body-secondary">{{ $k }}</span>
        </h3>
        <span class="fs-5 fw-semibold">{{ $name }}</span>
        <span class="ms-2">
            <span class="badge bg-info-subtle text-info">{{ $t('Detail') }}</span>
        </span>
    </div>
</div>

<div class="card">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-4 small">
            <div><span class="text-body-secondary">{{ $t('Code') }}:</span> <strong class="font-monospace">{{ $k }}</strong></div>
            <div><span class="text-body-secondary">{{ $t('Normal Bal') }}:</span> <strong>{{ $normal }}</strong></div>
            <div><span class="text-body-secondary">{{ $t('Active') }}:</span>
                <span class="badge bg-danger-subtle text-danger">{{ $t('Inactive') }}</span>
            </div>
            <div><span class="text-body-secondary">{{ $t('Total Entries') }}:</span> <strong>{{ count($rows) }}</strong></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">{{ $t('Journal Entries') }}</span>
        <form method="get" action="/ledger/detail" class="d-flex gap-2 m-0">
            <input type="hidden" name="k" value="{{ $k }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $sort_dir }}">
            <div class="d-flex align-items-center gap-1">
                <label class="text-body-secondary small mb-0">{{ $t('From') }}</label>
                <input type="date" name="from" class="form-control form-control-sm" style="width:150px">
            </div>
            <div class="d-flex align-items-center gap-1">
                <label class="text-body-secondary small mb-0">{{ $t('To') }}</label>
                <input type="date" name="to" class="form-control form-control-sm" style="width:150px">
            </div>
            <div class="input-group input-group-sm" style="max-width:220px">
                <input type="text" name="q" class="form-control" placeholder="{{ $t('Search ref or desc') }}…">
            </div>
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>{{ $t('Date') }}</th>
                    <th>{{ $t('Module') }}</th>
                    <th>{{ $t('Ref No') }}</th>
                    <th>{{ $t('Description') }}</th>
                    <th class="text-end">{{ $t('Debit (Rp)') }}</th>
                    <th class="text-end">{{ $t('Credit (Rp)') }}</th>
                    <th class="text-center">{{ $t('Ccy') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                <tr>
                    <td class="text-nowrap">{{ $row[0] ?: '-' }}</td>
                    <td>
                        <span class="badge bg-secondary-subtle text-secondary">{{ $row[1] ?: '-' }}</span>
                    </td>
                    <td class="font-monospace small">{{ $row[2] ?: '-' }}</td>
                    <td>{{ $row[3] ?: '-' }}</td>
                    <td class="text-end text-nowrap">{{ $row[4] ?: '-' }}@if(!empty($row[5])) <span class="text-body-secondary small">{{ $row[5] }}</span>@endif</td>
                    <td class="text-end text-nowrap">{{ $row[6] ?? '' }}</td>
                    <td class="text-center">{{ $row[7] ?? '' }}</td>
                </tr>
                @endforeach
                @if(count($rows) === 0)
                <tr><td colspan="7" class="text-center text-body-secondary py-4">{{ $t('No journal entries found.') }}</td></tr>
                @endif
            </tbody>
            @if(count($rows) > 0)
            <tfoot>
                <tr class="fw-bold border-top">
                    <td colspan="4" class="text-end">{{ $t('Page Total') }}</td>
                    <td class="text-end text-nowrap">Rp 6.500.000</td>
                    <td class="text-end text-nowrap">Rp 0</td>
                    <td></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
