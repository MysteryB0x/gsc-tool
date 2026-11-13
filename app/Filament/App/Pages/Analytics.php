<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\PicksProperty;
use App\Filament\App\Widgets\AiReferrals;
use App\Filament\App\Widgets\Ga4Stats;
use App\Filament\App\Widgets\Ga4Trend;
use App\Models\Analytics\Ga4LandingPage;
use App\Models\Reports\PageRow;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** GA4 next to Search Console: what search traffic does once it lands. Reads MariaDB only. */
class Analytics extends Page implements HasTable
{
    use InteractsWithTable;
    use PicksProperty;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Analyse';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Analytics';

    protected function getHeaderWidgets(): array
    {
        return filled($this->property()?->ga4_property_id) ? [Ga4Stats::class, Ga4Trend::class] : [];
    }

    protected function getFooterWidgets(): array
    {
        return filled($this->property()?->ga4_property_id) ? [AiReferrals::class] : [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(filled($this->property()?->ga4_property_id)
            ? [EmbeddedTable::make()]
            : [Text::make('Connect GA4 to this property under Settings → Properties (GA4 property ID) to see sessions, key events and visits from AI assistants next to your Search Console data.')]);
    }

    public function table(Table $table): Table
    {
        $propertyId = $this->property()?->id ?? 0;
        $gsc = PageRow::where('property_id', $propertyId)->pluck('clicks_28', 'path');
        $pct = fn ($v) => $v === null ? '—' : (($v >= 0 ? '+' : '').round($v * 100).'%');

        return $table
            ->heading('Landing pages, last 28 days')
            ->description('GA4 sessions and key events per landing page, next to its Search Console clicks.')
            ->query(Ga4LandingPage::query()->where('property_id', $propertyId))
            ->defaultSort('sessions', 'desc')
            ->searchable()
            ->columns([
                TextColumn::make('path')->label('Landing page')->searchable()->limit(60)->tooltip(fn ($record) => $record->path),
                TextColumn::make('sessions')->numeric()->sortable()->alignEnd(),
                TextColumn::make('change')->label('vs prev.')->alignEnd()
                    ->state(fn ($record) => $record->sessions_prev ? ($record->sessions - $record->sessions_prev) / $record->sessions_prev : null)
                    ->formatStateUsing($pct)
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger')),
                TextColumn::make('key_events')->label('Key events')->numeric()->sortable()->alignEnd(),
                TextColumn::make('conversion')->label('Conv. rate')->alignEnd()
                    ->state(fn ($record) => $record->sessions ? round($record->key_events / $record->sessions * 100, 2).'%' : '—'),
                TextColumn::make('revenue')->numeric(0)->sortable()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gsc_clicks')->label('Search clicks')->alignEnd()
                    ->state(fn ($record) => $gsc[$record->path] ?? null)->numeric()->placeholder('—'),
            ]);
    }
}
