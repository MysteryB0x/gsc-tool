<?php

namespace App\Filament\App\Pages;

use App\Models\Reports\DecayRow;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContentDecay extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Content decay';

    protected static string $source = 'decay';

    public function table(Table $table): Table
    {
        return $table
            ->query(DecayRow::query()->where('property_id', $this->propertyId()))
            ->description('Pages whose clicks last month fell well below their peak of the last '.config('gsctool.reports.decay.months').' months.')
            ->defaultSort('change')
            ->searchable()
            ->columns([
                TextColumn::make('url')->label('Page')->searchable()->limit(70)->tooltip(fn ($record) => $record->url)
                    ->url(fn ($record) => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('monthly_clicks')->label('Clicks by month')
                    ->state(fn ($record) => implode(' → ', array_values($record->monthly_clicks))),
                TextColumn::make('peak_clicks')->label('Peak')->numeric()->sortable()->alignEnd(),
                TextColumn::make('last_clicks')->label('Last month')->numeric()->sortable()->alignEnd(),
                TextColumn::make('change')->label('Change')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state) => round($state * 100).'%')->color('danger'),
            ])
            ->recordActions([$this->addActionAction(fn ($r) => 'Refresh '.parse_url($r->url, PHP_URL_PATH))])
            ->emptyStateHeading('No decaying pages')
            ->emptyStateDescription('Nothing lost a quarter or more of its clicks, or the reports are still being built.');
    }
}
