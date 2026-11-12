<?php

namespace App\Filament\App\Resources\Properties;

use App\Google\Credentials;
use App\Google\SearchConsole;
use App\Models\Property;
use App\Search\SyncPlanner;
use App\Services\PlanLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    public static function serviceAccountHint(): string
    {
        $email = app(Credentials::class)->app()->identity();

        return $email
            ? "In Search Console, add {$email} as a user (Restricted is enough) to each property."
            : 'The service account is not set up yet (GOOGLE_SERVICE_ACCOUNT_JSON).';
    }

    public static function canCreate(): bool
    {
        return app(PlanLimits::class)->canAddProperty(Filament::getTenant());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Property')->description(fn () => static::serviceAccountHint())->schema([
                TextInput::make('name')->required()->maxLength(100)->placeholder('Outlet Kantoormeubels'),
                TextInput::make('site_url')->label('Search Console property')->required()->maxLength(255)
                    ->placeholder('sc-domain:example.nl or https://www.example.nl/')
                    ->helperText('Exactly as Search Console shows it. Domain properties start with sc-domain:.'),
                Radio::make('source')->label('Data source')->default('api')->live()->required()
                    ->options([
                        'api' => 'Search Console API (works with any access, row-limited for big sites)',
                        'bulk' => 'Bulk Export to BigQuery (full data; you must own the property)',
                    ])
                    ->disableOptionWhen(fn (string $value) => $value === 'bulk' && ! app(PlanLimits::class)->canUseBulk(Filament::getTenant())),
                TextInput::make('bq_dataset')->label('Bulk Export dataset')->default('searchconsole')
                    ->visible(fn (Get $get) => $get('source') === 'bulk')->required(fn (Get $get) => $get('source') === 'bulk')
                    ->helperText('Search Console → Settings → Bulk data export, with this app\'s BigQuery project. The dataset name it creates.'),
                Toggle::make('is_active')->label('Active (synced and reported nightly)')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(fn () => static::serviceAccountHint())
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('site_url')->label('Property')->color('gray'),
                TextColumn::make('source')->badge()->formatStateUsing(fn ($state) => $state === 'bulk' ? 'Full data' : 'API'),
                IconColumn::make('has_access')->label('Access')->boolean(),
                TextColumn::make('synced_until')->label('Data until')->date('j M Y')->placeholder('—'),
                TextColumn::make('reports_built_at')->label('Reports')->since()->placeholder('not yet'),
                TextColumn::make('last_error')->label('Last error')->limit(40)->color('danger')->tooltip(fn ($record) => $record->last_error),
            ])
            ->recordActions([
                Action::make('checkAccess')->label('Check access')->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                    ->action(fn (Property $record) => static::checkAccess($record)),
                Action::make('sync')->label('Sync now')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Fetches the latest days (or the full 16 months on the first sync) and rebuilds the reports in the background.')
                    ->action(function (Property $record) {
                        $record->synced_until ? app(SyncPlanner::class)->daily($record) : app(SyncPlanner::class)->backfill($record);
                        Notification::make()->title('Sync queued')->success()->send();
                    }),
                EditAction::make(),
            ]);
    }

    /** Can the service account read this property? Fixes the format (sc-domain ↔ URL) when Google lists the other one. */
    public static function checkAccess(Property $property, bool $notify = true): bool
    {
        try {
            if ($property->isBulk()) {
                $ok = true;
            } else {
                $found = (new SearchConsole(app(Credentials::class)->forProperty($property)))->findProperty($property->site_url);
                $ok = $found !== null;
                if ($found && $found !== $property->site_url) {
                    $property->site_url = $found;
                }
            }
            $property->fill(['has_access' => $ok, 'access_checked_at' => now(), 'last_error' => $ok ? null : 'No access: '.static::serviceAccountHint()])->save();
        } catch (Throwable $e) {
            $ok = false;
            $property->update(['has_access' => false, 'access_checked_at' => now(), 'last_error' => $e->getMessage()]);
        }

        if ($notify) {
            Notification::make()->title($ok ? 'Access confirmed' : 'No access yet')->body($ok ? null : $property->last_error)
                ->color($ok ? 'success' : 'warning')->send();
        }

        return $ok;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProperties::route('/'),
            'create' => Pages\CreateProperty::route('/create'),
            'edit' => Pages\EditProperty::route('/{record}/edit'),
        ];
    }
}
