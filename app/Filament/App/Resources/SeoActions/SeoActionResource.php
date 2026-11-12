<?php

namespace App\Filament\App\Resources\SeoActions;

use App\Models\SeoAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** What you changed (or plan to change). Done actions become markers on the dashboard chart. */
class SeoActionResource extends Resource
{
    protected static ?string $model = SeoAction::class;

    protected static ?string $modelLabel = 'action';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Analyse';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Actions';

    protected static ?string $tenantOwnershipRelationshipName = 'team';

    public static function getNavigationBadge(): ?string
    {
        $n = SeoAction::where('team_id', Filament::getTenant()?->id)->where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('property_id')->label('Property')->required()
                ->options(fn () => Filament::getTenant()->properties()->orderBy('name')->pluck('name', 'id')),
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('url')->label('Page')->maxLength(2048),
            TextInput::make('query')->maxLength(1024),
            Select::make('status')->options(['open' => 'Open', 'done' => 'Done', 'dismissed' => 'Dismissed'])->default('open')->required()->live(),
            DatePicker::make('done_on')->label('Done on')->native(false)->visible(fn ($get) => $get('status') === 'done'),
            Textarea::make('notes')->rows(4)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->wrap()->weight('bold'),
                TextColumn::make('property.name')->label('Property'),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'done' => 'success', 'dismissed' => 'gray', default => 'warning'
                }),
                TextColumn::make('done_on')->date('j M Y')->placeholder('—'),
                TextColumn::make('created_at')->label('Added')->since(),
            ])
            ->filters([SelectFilter::make('status')->options(['open' => 'Open', 'done' => 'Done', 'dismissed' => 'Dismissed'])->default('open')])
            ->recordActions([
                Action::make('done')->label('Mark done')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (SeoAction $record) => $record->status === 'open')
                    ->action(fn (SeoAction $record) => $record->update(['status' => 'done', 'done_on' => today()])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeoActions::route('/'),
            'create' => Pages\CreateSeoAction::route('/create'),
            'edit' => Pages\EditSeoAction::route('/{record}/edit'),
        ];
    }
}
