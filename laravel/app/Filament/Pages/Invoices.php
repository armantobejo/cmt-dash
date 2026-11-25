<?php

namespace App\Filament\Pages;

use App\Support\CmtI18n;
use App\Support\CmtQueries;
use App\Support\CmtUser;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/** Invoices listing (F-07) — Filament page mirroring the Blade /invoices surface. */
class Invoices extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.invoices';
    protected static ?string $slug = 'invoices';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-invoice';

    public function getTitle(): string
    {
        return self::translate('Invoices');
    }

    public static function getNavigationLabel(): string
    {
        return self::translate('Invoices');
    }

    public function getSubheading(): ?string
    {
        $records = $this->getTableRecords();
        $total = $records instanceof LengthAwarePaginator ? $records->total() : $records->count();

        return $total.' '.$this->t('results');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (
                int|string $page,
                int|string $recordsPerPage,
                ?string $search = null,
                ?string $sortColumn = null,
                ?string $sortDirection = null,
            ): LengthAwarePaginator {
                $sortColumn = $sortColumn ?: $this->getTableSortColumn();
                $sortDirection = $sortDirection ?: $this->getTableSortDirection();

                $result = app(CmtQueries::class)->getInvoices(
                    page: (int) $page,
                    perPage: (int) $recordsPerPage,
                    search: (string) $search,
                    sort: $sortColumn ?? 'DATEOFISSU',
                    sortDir: $sortDirection === 'asc' ? 'asc' : 'desc',
                    companyFilter: app(CmtUser::class)->customerCompany,
                );

                return new Paginator(
                    array_map(static fn ($row): array => (array) $row, $result['rows']),
                    $result['total'],
                    $result['per_page'],
                    $result['page'],
                );
            })
            ->columns([
                TextColumn::make('REFSHITMEN')
                    ->label($this->t('Ref.'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('EI')
                    ->label($this->t('Kind'))
                    ->sortable(),

                TextColumn::make('KD_CONSIG')
                    ->label($this->t('Customer'))
                    ->sortable(),

                TextColumn::make('SHIPPER')
                    ->label($this->t('Shipper'))
                    ->sortable(),

                TextColumn::make('DATEOFISSU')
                    ->label($this->t('Date'))
                    ->sortable(),

                TextColumn::make('NOINVOICE')
                    ->label($this->t('No. Invoice'))
                    ->sortable(),

                TextColumn::make('GRAND_USD')
                    ->label($this->t('Amount (USD)'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2)),

                TextColumn::make('GRAND_RP')
                    ->label($this->t('Amount (IDR)'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 0, ',', '.')),

                TextColumn::make('BYR_USD')
                    ->label($this->t('Paid (USD)'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2)),

                TextColumn::make('BYR_RP')
                    ->label($this->t('Paid (IDR)'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 0, ',', '.')),

                TextColumn::make('LUNAS')
                    ->label($this->t('Status'))
                    ->formatStateUsing(fn ($state): string => $state ? $this->t('paid') : $this->t('unpaid')),
            ])
            ->paginated([(int) config('cmt.invoices_per_page', 25), 50, 100])
            ->deferLoading(false)
            ->emptyStateHeading($this->t('No invoices found.'));
    }

    protected static function translate(string $key): string
    {
        $i18n = app(CmtI18n::class);

        return $i18n->t($i18n->langFromCookie(request()->cookie('cmt_lang')), $key);
    }

    public function t(string $key): string
    {
        return self::translate($key);
    }
}
