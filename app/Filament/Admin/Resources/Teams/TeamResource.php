<?php

namespace App\Filament\Admin\Resources\Teams;

use App\Credits\Credits;
use App\Google\WorkspaceAccounts;
use App\Models\AuditLog;
use App\Models\Team;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $modelLabel = 'workspace';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Select::make('plan_id')->relationship('plan', 'name')->required(),
            Select::make('subscription_status')->options(array_combine($s = ['trialing', 'active', 'past_due', 'paused', 'canceled'], array_map('ucfirst', $s)))->required(),
            Toggle::make('may_share_properties')->label('May add sites that another workspace already has')
                ->helperText('Off: a site belongs to one workspace. Turn on for an agency and its client both using the tool.'),
            Toggle::make('uses_app_account')->label('Reads Google data with the app\'s own account')
                ->helperText('Only for your own workspace. Others get their own Google account, so a grant reaches only them.'),
            Textarea::make('admin_notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('plan.name')->badge(),
            TextColumn::make('subscription_status')->label('Status')->badge(),
            TextColumn::make('properties_count')->counts('properties')->label('Properties'),
            TextColumn::make('credit_balance')->label('Credits')->numeric(),
            TextColumn::make('google_account_email')->label('Google account')->placeholder(fn (Team $r) => $r->uses_app_account ? 'App account' : ($r->google_account_status ?? '—'))
                ->tooltip(fn (Team $r) => $r->google_account_error)->copyable()->toggleable(),
            TextColumn::make('created_at')->since(),
        ])->recordActions([
            Action::make('credits')->label('Add credits')->icon(Heroicon::OutlinedPlusCircle)->color('gray')
                ->schema([
                    TextInput::make('amount')->numeric()->required()->helperText('Negative to take credits away.'),
                    TextInput::make('note')->maxLength(100),
                ])
                ->action(function (Team $record, array $data) {
                    app(Credits::class)->add($record, (int) $data['amount'], 'admin_grant', auth()->user(), $data['note'] ?: null);
                    AuditLog::record('team.credits', $record, ['amount' => (int) $data['amount'], 'note' => $data['note']]);
                    Notification::make()->title('Credits updated')->success()->send();
                }),
            Action::make('googleAccount')->label('Create Google account')->icon(Heroicon::OutlinedKey)->color('gray')
                ->visible(fn (Team $r) => WorkspaceAccounts::appliesTo($r) && $r->google_account_status !== 'ready')
                ->action(function (Team $r) {
                    try {
                        $email = app(WorkspaceAccounts::class)->ensure($r);
                        AuditLog::record('team.google_account', $r, ['email' => $email]);
                        Notification::make()->title('Google account created')->body($email)->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Could not create it')->body($e->getMessage())->danger()->send();
                    }
                }),
            EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeams::route('/'),
            'create' => Pages\CreateTeam::route('/create'),
            'edit' => Pages\EditTeam::route('/{record}/edit'),
        ];
    }
}
