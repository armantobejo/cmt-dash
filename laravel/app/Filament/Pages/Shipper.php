<?php

namespace App\Filament\Pages;

use App\Support\CmtI18n;
use App\Support\CmtQueries;
use App\Support\CmtUser;
use Filament\Pages\Page;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/** Shipper listing (F-28) — Listing Reference page. */
class Shipper extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.shipper';
    protected static ?string $slug = 'shippers';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    private const SORT_KEYS = [
        'code' => 'code',
        'name' => 'name',
        'bu' => 'bu',
        'type' => 'type',
    ];

    public function getTitle(): string
    {
        return self::translate('Shippers');
    }

    public static function getNavigationLabel(): string
    {
        return self::translate('Shippers');
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

                $result = app(CmtQueries::class)->getShippers(
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
                TextColumn::make('KD_SHIPPER')
                    ->label($this->t('Shipper Code'))
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('NAME')
                    ->label($this->t('Name'))
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('KD_BU')
                    ->label($this->t('BU'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('KD_JNSCUST')
                    ->label($this->t('Customer Type'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('TERM')
                    ->label($this->t('Payment Term'))
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

                TextColumn::make('NPWP')
                    ->label($this->t('NPWP'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('NPWP_ADDRESS')
                    ->label($this->t('NPWP Address'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-')
                    ->wrap(),

                TextColumn::make('NPWP_DATE')
                    ->label($this->t('NPWP Date'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('NO_SIUP')
                    ->label($this->t('SIUP No'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('TGL_SIUP')
                    ->label($this->t('SIUP Date'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('KD_ACCOUNT')
                    ->label($this->t('Account Code'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),
            ])
            ->paginated([(int) config('cmt.shippers_per_page', 25), 50, 100])
            ->deferLoading(false)
            ->emptyStateHeading($this->t('No shippers found.'));
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