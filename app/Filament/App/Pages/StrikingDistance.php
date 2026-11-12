<?php

namespace App\Filament\App\Pages;

use App\Models\Reports\StrikingRow;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StrikingDistance extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Striking distance';

    protected static string $source = 'striking';

    public function table(Table $table): Table
    {
        $s = config('gsctool.reports.striking');

        return $table
            ->query(StrikingRow::query()->where('property_id', $this->propertyId()))
            ->description("Queries at positions {$s['min_position']}–{$s['max_position']}. Potential = extra clicks in ".config('gsctool.reports.window_days').' days at position 3.')
            ->defaultSort('potential_clicks', 'desc')
            ->columns([
                TextColumn::make('query')->searchable()->wrap(),
                TextColumn::make('url')->label('Page')->searchable()->limit(50)->tooltip(fn ($record) => $record->url),
                TextColumn::make('position')->numeric(1)->sortable()->alignEnd(),
                TextColumn::make('impressions')->numeric()->sortable()->alignEnd(),
                TextColumn::make('clicks')->numeric()->sortable()->alignEnd(),
                TextColumn::make('potential_clicks')->label('Potential')->numeric()->sortable()->alignEnd()->weight('bold')->color('success'),
            ])
            ->filters([
                Filter::make('page_one')->label('Page 1 only (4–10)')->query(fn (Builder $q) => $q->where('position', '<=', 10.5)),
            ])
            ->recordActions([$this->addActionAction(fn ($r) => 'Push "'.$r->query.'" into the top 3')]);
    }
}
