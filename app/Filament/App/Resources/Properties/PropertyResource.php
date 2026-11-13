<?php

namespace App\Filament\App\Resources\Properties;

use App\Google\AnalyticsData;
use App\Google\Credentials;
use App\Google\SearchConsole;
use App\Google\WorkspaceAccounts;
use App\Models\Property;
use App\Models\Team;
use App\Search\SiteKey;
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

    public static function serviceAccountHint(?Team $team = null): string
    {
        $team ??= Filament::getTenant();
        $email = $team ? app(Credentials::class)->identityFor($team) : app(Credentials::class)->app()->identity();

        if ($email) {
            return "Add {$email} as a user in Search Console (Restricted is enough) and as a Viewer in GA4. This email belongs to this workspace only.";
        }

        return $team && WorkspaceAccounts::appliesTo($team)
            ? 'This workspace\'s Google account is being created. Its email shows here in a minute.'
            : 'The Google service account is not set up yet (GOOGLE_SERVICE_ACCOUNT_JSON).';
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
                    ->helperText('Exactly as Search Console shows it. Domain properties start with sc-domain:.')
                    ->rule(fn (?Property $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        if ($other = SiteKey::takenBy((string) $value, Filament::getTenant(), $record?->id)) {
                            $fail('This site is already connected to another workspace. Contact support if it should be in both.');
                        }
                    }),
                Radio::make('source')->label('Data source')->default('api')->live()->required()
                    ->options([
                        'api' => 'Search Console API (works with any access, row-limited for big sites)',
                        'bulk' => 'Bulk Export to BigQuery (full data; you must own the property)',
                    ])
                    ->disableOptionWhen(fn (string $value) => $value === 'bulk' && ! app(PlanLimits::class)->canUseBulk(Filament::getTenant())),
                TextInput::make('bq_dataset')->label('Bulk Export dataset')->default('searchconsole')
                    ->visible(fn (Get $get) => $get('source') === 'bulk')->required(fn (Get $get) => $get('source') === 'bulk')
                    ->helperText('Search Console → Settings → Bulk data export, with this app\'s BigQuery project. The dataset name it creates.'),
                TextInput::make('ga4_property_id')->label('GA4 property ID (optional)')->maxLength(20)->regex('/^(properties\/)?\d{6,12}$/')
                    ->dehydrateStateUsing(fn (?string $state) => $state ? AnalyticsData::normalizeId($state) : null)
                    ->placeholder('123456789')
                    ->helperText('GA4 → Admin → Property details → Property ID (numbers only). Add the email above as a Viewer in that property.'),
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
                TextColumn::make('ga4_property_id')->label('GA4')->placeholder('—')
                    ->color(fn (Property $r) => $r->ga4_error ? 'danger' : null)->tooltip(fn (Property $r) => $r->ga4_error),
                TextColumn::make('reports_built_at')->label('Reports')->since()->placeholder('not yet'),
                TextColumn::make('last_error')->label('Last error')->limit(40)->color('danger')->tooltip(fn ($record) => $record->last_error),
            ])
            ->recordActions([
                Action::make('checkAccess')->label('Check access')->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                    ->action(fn (Property $record) => static::checkAccess($record)),
                Action::make('checkGa4')->label('Check GA4')->icon(Heroicon::OutlinedChartBar)->color('gray')
                    ->visible(fn (Property $record) => filled($record->ga4_property_id))
                    ->action(fn (Property $record) => static::checkGa4($record)),
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

    public static function checkGa4(Property $property, bool $notify = true): bool
    {
        try {
            $problem = (new AnalyticsData(app(Credentials::class)->forProperty($property)))->accessProblem($property->ga4_property_id);
        } catch (Throwable $e) {
            $problem = $e->getMessage();
        }
        $property->update(['ga4_has_access' => $problem === null, 'ga4_error' => $problem ? 'GA4: '.$problem : null]);

        if ($notify) {
            Notification::make()->title($problem ? 'No GA4 access yet' : 'GA4 access confirmed')->body($problem)->color($problem ? 'warning' : 'success')->send();
        }

        return $problem === null;
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
            $property->fill(['has_access' => $ok, 'access_checked_at' => now(), 'last_error' => $ok ? null : 'No access: '.static::serviceAccountHint($property->team)])->save();
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
