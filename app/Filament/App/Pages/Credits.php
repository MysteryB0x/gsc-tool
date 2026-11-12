<?php

namespace App\Filament\App\Pages;

use App\Models\BqQuery;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/** Balance and every BigQuery query of this workspace, with bytes and credits. */
class Credits extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Credits & usage';

    public static function getNavigationBadge(): ?string
    {
        $team = Filament::getTenant();

        return $team && ! $team->hasUnlimitedCredits() ? (string) $team->credit_balance : null;
    }

    public function getSubheading(): ?string
    {
        $team = Filament::getTenant();
        if ($team->hasUnlimitedCredits()) {
            return 'Your plan has unlimited credits. Every query is still logged below.';
        }

        return "Balance: {$team->credit_balance} credits. 1 credit = ".Number::fileSize(config('gsctool.credits.bytes_per_credit')).' scanned by BigQuery. Nightly reports are included in your plan.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(BqQuery::query()->where('team_id', Filament::getTenant()->id))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M H:i')->sortable(),
                TextColumn::make('purpose'),
                TextColumn::make('property.name')->label('Property'),
                TextColumn::make('bytes_billed')->label('Billed')->formatStateUsing(fn ($state) => Number::fileSize($state))->alignEnd(),
                TextColumn::make('credits_charged')->label('Credits')->alignEnd(),
                IconColumn::make('from_cache')->label('Cache')->boolean(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'done' => 'success', 'failed', 'refused' => 'danger', default => 'gray'
                }),
            ]);
    }
}
