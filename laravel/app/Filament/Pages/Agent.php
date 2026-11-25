<?php

namespace App\Filament\Pages;

use App\Support\CmtI18n;
use App\Support\CmtMoney;
use App\Support\CmtQueries;
use App\Support\CmtUser;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * Agent listing (F-28) — Listing Reference page.
 * Fed from CmtQueries::getAgents() with company filter.
 * Cross-links: Agent → Shipments detail via REFSHITMEN (not implemented here; reserved).
 */
class Agent extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.agent';
    protected static ?string $slug = 'agents';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    private const SORT_KEYS = [
        'agent' => 'agent',
        'code' => 'code',
        'name' => 'name',
        'rate' => 'rate',
    ];

    public function getTitle(): string
    {
        return self::translate('Agents');
    }

    public static function getNavigationLabel(): string
    {
        return self::translate('Agents');
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

                $result = app(CmtQueries::class)->getAgents(
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
                TextColumn::make('AGENT')
                    ->label($this->t('Agent Code'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('CODE')
                    ->label($this->t('Sub Code'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('NAME')
                    ->label($this->t('Name'))
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('AGENTINV')
                    ->label($this->t('Invoice Agent'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('RATE')
                    ->label($this->t('Rate'))
                    ->sortable()
                    ->alignment(Alignment::End)
                    ->formatStateUsing(fn ($state): string => CmtMoney::format($state)),

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

                TextColumn::make('PIC')
                    ->label($this->t('PIC'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('KD_ACCOUNT')
                    ->label($this->t('Account Code'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),

                TextColumn::make('ACCT_CR')
                    ->label($this->t('Credit Account'))
                    ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '-'),
            ])
            ->paginated([(int) config('cmt.agents_per_page', 25), 50, 100])
            ->deferLoading(false)
            ->emptyStateHeading($this->t('No agents found.'))
            ->headerActions([
                Action::make('export')
                    ->label($this->t('Export CSV'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function () {
                        // TODO: implement CSV export
                    }),
            ]);
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