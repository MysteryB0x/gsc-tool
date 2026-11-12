<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\PicksProperty;
use App\Models\SeoAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;

/**
 * A report page: one pre-computed table for the picked property. Reads MariaDB only (no credits).
 * Every row can become an action, which then shows as a marker on the dashboard trend.
 */
abstract class ReportPage extends Page implements HasTable
{
    use InteractsWithTable;
    use PicksProperty;

    protected static string|\UnitEnum|null $navigationGroup = 'Opportunities';

    /** decay, cannibalisation, striking, ctr — stored on the action. */
    protected static string $source = 'manual';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function propertyId(): int
    {
        return $this->property()?->id ?? 0;
    }

    /** "Add as action", prefilled from the row. */
    protected function addActionAction(callable $title): Action
    {
        return Action::make('addAction')
            ->label('Add as action')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('gray')
            ->fillForm(fn (Model $record) => [
                'title' => $title($record),
                'url' => $record->url ?? null,
                'query' => $record->query ?? null,
            ])
            ->schema([
                TextInput::make('title')->required()->maxLength(255),
                TextInput::make('url')->label('Page')->maxLength(2048),
                TextInput::make('query')->maxLength(1024),
                Textarea::make('notes')->rows(3),
            ])
            ->action(function (array $data) {
                SeoAction::create($data + ['property_id' => $this->propertyId(), 'source' => static::$source, 'status' => 'open']);
                Notification::make()->title('Action added')->success()->send();
            });
    }
}
