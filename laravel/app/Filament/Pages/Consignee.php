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

/** Consignee / Notify listing (F-28) — Listing Reference page. */
class Consignee extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.consignee';
    protected static ?string $slug = 'consignee';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    private const SORT_KEYS = [
        'code' => 'code',
        'name' => 'name',
    ];

    public function getTitle(): string
    {
        return self::translate('Consignee / Notify');
    }

    public static function getNavigationLabel(): string
    {
        return self::translate('Consignee / Notify');
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

                $result = app(CmtQueries::class)->getConsignees(
                    page: (int) $page,
                    perPage: (int) $recordsPerPage,
                    search: (string) $search,
                    sort: self::SORT_KEYS[$sortColumn ?? 'name'] ?? 'name',
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
                TextColumn::make('KD_CONSIG')
                    ->label($this->t('Consignee Code'))
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('NAME')
                    ->label($this->t('Name'))
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('ADDRESS')
                    ->label($this->t('Address'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-')
                    ->wrap(),

                TextColumn::make('TELP')
                    ->label($this->t('Phone'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('FAX')
                    ->label($this->t('Fax'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),
            ])
            ->paginated([(int) config('cmt.consignees_per_page', 25), 50, 100])
            ->deferLoading(false)
            ->emptyStateHeading($this->t('No consignees found.'));
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