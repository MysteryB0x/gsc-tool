<?php

namespace App\Filament\App\Pages;

use App\Credits\InsufficientCredits;
use App\Filament\App\Concerns\PicksProperty;
use App\Models\BqQuery;
use App\Reports\Explorer as ExplorerSql;
use App\Search\QueryRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Ad-hoc queries on the raw BigQuery data. The only page that spends credits: it shows the estimate
 * first, and a repeat of the same query within the cache window is free.
 */
class Explorer extends Page implements HasTable
{
    use InteractsWithTable;
    use PicksProperty;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|\UnitEnum|null $navigationGroup = 'Analyse';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Explorer';

    public ?array $data = [];

    public ?int $resultId = null;

    public ?string $estimate = null;

    public function mount(): void
    {
        $end = now()->subDays(3);
        $this->form->fill([
            'dimensions' => ['query'],
            'from' => $end->copy()->subDays(27)->toDateString(),
            'to' => $end->toDateString(),
            'sort' => 'clicks',
            'limit' => 1000,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Grid::make(4)->schema([
                CheckboxList::make('dimensions')->options(ExplorerSql::DIMENSIONS)->columns(3)->required()->columnSpan(2)
                    ->live()->afterStateUpdated(fn () => $this->estimate = null),
                DatePicker::make('from')->required()->native(false)->live()->afterStateUpdated(fn () => $this->estimate = null),
                DatePicker::make('to')->required()->native(false)->live()->afterStateUpdated(fn () => $this->estimate = null),
            ]),
            Grid::make(4)->schema([
                TextInput::make('query_contains')->label('Query contains'),
                TextInput::make('query_regex')->label('Query regex')->placeholder('^(how|what|why)\b'),
                TextInput::make('url_contains')->label('Page contains'),
                Select::make('device')->options(['DESKTOP' => 'Desktop', 'MOBILE' => 'Mobile', 'TABLET' => 'Tablet']),
                TextInput::make('country')->label('Country (ISO-3, e.g. nld)')->maxLength(3),
                Select::make('sort')->options(array_combine(ExplorerSql::SORTS, array_map('ucfirst', ExplorerSql::SORTS)))->required(),
                TextInput::make('limit')->numeric()->minValue(1)->maxValue(10000)->required(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('runQuery')->footer([
                    Actions::make([
                        Action::make('estimateCost')->label('Estimate credits')->color('gray')->action(fn () => $this->estimateCost()),
                        Action::make('runQuery')->label('Run query')->icon(Heroicon::OutlinedPlay)->submit('runQuery'),
                    ]),
                ]),
            ])->description(fn () => $this->estimate ?? 'Queries the raw data in BigQuery. Credits are charged on the bytes BigQuery bills; you see the estimate first.'),
            EmbeddedTable::make(),
        ]);
    }

    protected function sql(): string
    {
        $d = $this->form->getState();

        return ExplorerSql::sql($this->property(), $d['dimensions'] ?? [], $d['from'], $d['to'], $d, $d['sort'], (int) $d['limit']);
    }

    public function estimateCost(): void
    {
        if (! $this->property()) {
            return;
        }
        try {
            $credits = app(QueryRunner::class)->estimate($this->sql());
            $team = Filament::getTenant();
            $label = $credits.' '.str('credit')->plural($credits);
            $this->estimate = $team->hasUnlimitedCredits()
                ? "About {$label} (not charged on your plan)."
                : "About {$label}. You have {$team->fresh()->credit_balance}.";
        } catch (Throwable $e) {
            Notification::make()->title('Could not estimate')->body($e->getMessage())->danger()->send();
        }
    }

    public function runQuery(): void
    {
        if (! $this->property()) {
            return;
        }
        try {
            $result = app(QueryRunner::class)->run(Filament::getTenant(), $this->sql(), 'explorer', auth()->user(), $this->property());
            $this->resultId = $result->fromCache
                ? BqQuery::where('team_id', Filament::getTenant()->id)->where('sql_hash', hash('sha256', $this->sql()))->where('from_cache', false)->whereNotNull('result_json')->latest('id')->value('id')
                : $result->queryId;
            $this->resetTable();
            Notification::make()->title(count($result->rows).' rows')
                ->body($result->fromCache ? 'From cache: 0 credits.' : "{$result->credits} credits used.")->success()->send();
        } catch (InsufficientCredits $e) {
            Notification::make()->title('Not enough credits')->body($e->getMessage())->warning()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Query failed')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return list<array<string, mixed>> */
    protected function rows(): array
    {
        $json = $this->resultId ? BqQuery::where('team_id', Filament::getTenant()->id)->whereKey($this->resultId)->value('result_json') : null;

        return $json ? json_decode($json, true) : [];
    }

    public function table(Table $table): Table
    {
        $rows = $this->rows();
        $dimensions = $rows ? array_values(array_diff(array_keys($rows[0]), ['clicks', 'impressions', 'ctr', 'position'])) : [];

        return $table
            ->records(fn () => collect($rows)->mapWithKeys(fn ($r, $i) => [(string) $i => $r])->all())
            ->columns([
                ...array_map(fn ($d) => TextColumn::make($d)->label(ExplorerSql::DIMENSIONS[$d] ?? $d)->wrap(), $dimensions),
                TextColumn::make('clicks')->numeric()->alignEnd(),
                TextColumn::make('impressions')->numeric()->alignEnd(),
                TextColumn::make('ctr')->label('CTR')->formatStateUsing(fn ($state) => round($state * 100, 2).'%')->alignEnd(),
                TextColumn::make('position')->numeric(1)->alignEnd(),
            ])
            ->headerActions([
                Action::make('csv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn () => $rows !== [])
                    ->action(fn () => $this->downloadCsv($rows)),
            ])
            ->paginated([25, 100, 500])
            ->emptyStateHeading('No results yet')
            ->emptyStateDescription('Pick dimensions and dates, then run the query.');
    }

    protected function downloadCsv(array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'explorer-'.now()->format('Ymd-His').'.csv');
    }
}
