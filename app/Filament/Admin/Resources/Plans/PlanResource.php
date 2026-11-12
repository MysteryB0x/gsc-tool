<?php

namespace App\Filament\Admin\Resources\Plans;

use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('key')->required()->unique(ignoreRecord: true),
            TextInput::make('name')->required(),
            TextInput::make('sort')->numeric()->default(0),
            TextInput::make('price_monthly_cents')->label('Price / month (cents, excl. VAT)')->numeric()->required(),
            TextInput::make('price_yearly_cents')->label('Price / year (cents)')->numeric()->required(),
            TextInput::make('trial_days')->numeric()->default(0),
            TextInput::make('max_properties')->numeric()->helperText('Empty = unlimited'),
            TextInput::make('max_bulk_properties')->label('Max full-data properties')->numeric()->helperText('Empty = unlimited'),
            TextInput::make('max_members')->numeric()->helperText('Empty = unlimited'),
            TextInput::make('monthly_credits')->numeric()->required(),
            Toggle::make('unlimited_credits')->label('Unlimited credits (queries logged, never charged)'),
            Toggle::make('is_public')->label('Shown on the pricing page'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('name'),
            TextColumn::make('price_monthly_cents')->label('€ / month')->formatStateUsing(fn ($state) => '€'.number_format($state / 100, 2)),
            TextColumn::make('max_properties')->label('Properties')->placeholder('∞'),
            TextColumn::make('max_bulk_properties')->label('Full data')->placeholder('∞'),
            TextColumn::make('monthly_credits')->label('Credits / month'),
            IconColumn::make('unlimited_credits')->boolean()->label('Unlimited'),
            IconColumn::make('is_public')->boolean()->label('Public'),
            TextColumn::make('teams_count')->counts('teams')->label('Workspaces'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
