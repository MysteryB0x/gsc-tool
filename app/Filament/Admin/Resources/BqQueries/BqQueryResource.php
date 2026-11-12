<?php

namespace App\Filament\Admin\Resources\BqQueries;

use App\Models\BqQuery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/** Every BigQuery job across all workspaces: what it was for, what it billed, what it cost. */
class BqQueryResource extends Resource
{
    protected static ?string $model = BqQuery::class;

    protected static ?string $modelLabel = 'BigQuery query';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Costs';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->dateTime('j M H:i')->sortable(),
            TextColumn::make('team.name')->label('Workspace')->searchable(),
            TextColumn::make('property.name')->label('Property'),
            TextColumn::make('purpose')->searchable(),
            TextColumn::make('bytes_estimated')->label('Estimated')->formatStateUsing(fn ($state) => Number::fileSize($state))->alignEnd(),
            TextColumn::make('bytes_billed')->label('Billed')->formatStateUsing(fn ($state) => Number::fileSize($state))->alignEnd()->sortable(),
            TextColumn::make('cost')->label('Cost')->state(fn (BqQuery $r) => '$'.number_format($r->costUsd(), 4))->alignEnd(),
            TextColumn::make('credits_charged')->label('Credits')->alignEnd(),
            IconColumn::make('charged')->boolean(),
            IconColumn::make('from_cache')->boolean()->label('Cache'),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'done' => 'success', 'failed', 'refused' => 'danger', default => 'gray'
            }),
            TextColumn::make('error')->limit(40)->tooltip(fn ($record) => $record->error)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status')->options(['done' => 'Done', 'failed' => 'Failed', 'refused' => 'Refused']),
            TernaryFilter::make('charged'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBqQueries::route('/')];
    }
}
