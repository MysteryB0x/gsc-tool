<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\StrikingDistance;
use App\Models\Reports\StrikingRow;
use App\Support\CurrentProperty;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TopOpportunities extends TableWidget
{
    protected static ?int $sort = 3;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top opportunities')
            ->description('Striking-distance queries with the most extra clicks at position 3.')
            ->query(StrikingRow::query()->where('property_id', CurrentProperty::get()?->id ?? 0)->orderByDesc('potential_clicks')->limit(10))
            ->paginated(false)
            ->columns([
                TextColumn::make('query')->wrap(),
                TextColumn::make('url')->label('Page')->limit(50)->tooltip(fn ($record) => $record->url),
                TextColumn::make('position')->numeric(1)->alignEnd(),
                TextColumn::make('impressions')->numeric()->alignEnd(),
                TextColumn::make('potential_clicks')->label('Potential')->numeric()->alignEnd()->weight('bold')->color('success'),
            ])
            ->headerActions([Action::make('all')->label('All opportunities')->url(StrikingDistance::getUrl())->color('gray')]);
    }
}
