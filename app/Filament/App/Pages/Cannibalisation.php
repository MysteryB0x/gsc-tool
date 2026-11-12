<?php

namespace App\Filament\App\Pages;

use App\Models\Reports\CannibalisationRow;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

class Cannibalisation extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Cannibalisation';

    protected static string $source = 'cannibalisation';

    public function table(Table $table): Table
    {
        return $table
            ->query(CannibalisationRow::query()->where('property_id', $this->propertyId()))
            ->description('Queries where two or more of your pages each get at least '.(config('gsctool.reports.cannibalisation.min_share') * 100).'% of the impressions (last '.config('gsctool.reports.window_days').' days).')
            ->defaultSort('impressions', 'desc')
            ->columns([
                TextColumn::make('query')->searchable()->wrap(),
                TextColumn::make('impressions')->numeric()->sortable()->alignEnd(),
                TextColumn::make('clicks')->numeric()->sortable()->alignEnd(),
                TextColumn::make('url_count')->label('Pages')->sortable()->alignEnd(),
                ViewColumn::make('urls')->label('Split')->view('filament.columns.url-split'),
            ])
            ->recordActions([$this->addActionAction(fn ($r) => 'Pick one page for "'.$r->query.'"')]);
    }
}
