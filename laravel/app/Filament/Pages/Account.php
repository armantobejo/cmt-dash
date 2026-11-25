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

/** Account listing (F-28) — Listing Reference page. */
class Account extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.account';
    protected static ?string $slug = 'account';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    private const SORT_KEYS = [
        'code' => 'code',
        'name' => 'name',
        'type' => 'type',
        'group' => 'group',
    ];

    public function getTitle(): string
    {
        return self::translate('Accounts');
    }

    public static function getNavigationLabel(): string
    {
        return self::translate('Accounts');
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

                $result = app(CmtQueries::class)->getAccounts(
                    page: (int) $page,
                    perPage: (int) $recordsPerPage,
                    search: (string) $search,
                    sort: self::SORT_KEYS[$sortColumn ?? 'code'] ?? 'code',
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
                TextColumn::make('KD_ACCOUNT')
                    ->label($this->t('Account Code'))
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('NM_ACCOUNT')
                    ->label($this->t('Account Name'))
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('TYPE')
                    ->label($this->t('Account Type'))
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn ($state): string => CmtQueries::ACCOUNT_TYPE_LABELS[$state] ?? (filled($state) ? (string) $state : '-'))
                    ->color('gray'),

                TextColumn::make('GROUP')
                    ->label($this->t('Group'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('SLD_NORMAL')
                    ->label($this->t('Normal Balance'))
                    ->formatStateUsing(fn ($state): string => CmtQueries::ACCOUNT_SLD_NORMAL_LABELS[$state] ?? (filled($state) ? (string) $state : '-')),

                TextColumn::make('ENTRY')
                    ->label($this->t('Entry Allowed'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => CmtQueries::ACCOUNT_ENTRY_LABELS[$state] ?? (filled($state) ? (string) $state : '-'))
                    ->color(fn ($state): string => in_array((string) $state, ['1', 'Yes'], true) ? 'success' : 'gray'),

                TextColumn::make('ACTIVE')
                    ->label($this->t('Active From'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('NOACTIVE')
                    ->label($this->t('Inactive From'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),
            ])
            ->paginated([(int) config('cmt.accounts_per_page', 25), 50, 100])
            ->deferLoading(false)
            ->emptyStateHeading($this->t('No accounts found.'));
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