<?php

namespace App\Filament\App\Pages;

use App\Models\Reports\CtrRow;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CtrBenchmark extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'CTR benchmark';

    protected static string $source = 'ctr';

    public function table(Table $table): Table
    {
        $pct = fn ($state) => round($state * 100, 1).'%';

        return $table
            ->query(CtrRow::query()->where('property_id', $this->propertyId()))
            ->description('Query + page pairs whose click-through rate is below what their position usually gets. Usually a title or meta description to rewrite.')
            ->defaultSort('missed_clicks', 'desc')
            ->columns([
                TextColumn::make('query')->searchable()->wrap(),
                TextColumn::make('url')->label('Page')->searchable()->limit(50)->tooltip(fn ($record) => $record->url),
                TextColumn::make('position')->numeric(1)->sortable()->alignEnd(),
                TextColumn::make('impressions')->numeric()->sortable()->alignEnd(),
                TextColumn::make('ctr')->label('CTR')->formatStateUsing($pct)->sortable()->alignEnd(),
                TextColumn::make('expected_ctr')->label('Expected')->formatStateUsing($pct)->alignEnd()->color('gray'),
                TextColumn::make('missed_clicks')->label('Missed clicks')->numeric()->sortable()->alignEnd()->weight('bold')->color('warning'),
            ])
            ->recordActions([$this->addActionAction(fn ($r) => 'Rewrite title/meta for "'.$r->query.'"')]);
    }
}
