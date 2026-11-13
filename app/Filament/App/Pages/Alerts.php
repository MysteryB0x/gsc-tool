<?php

namespace App\Filament\App\Pages;

use App\Alerts\DigestSender;
use App\Models\AlertDigest;
use App\Models\AlertPreference;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Your daily/weekly change alerts for this workspace, a preview, and what was sent. */
class Alerts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Alerts';

    public ?array $data = [];

    public ?array $previewItems = null;

    public function getSubheading(): ?string
    {
        return 'A short email and a bell notification when something big changes. Nothing is sent on quiet days.';
    }

    protected function pref(): AlertPreference
    {
        return AlertPreference::for(Filament::getTenant(), auth()->user());
    }

    public function mount(): void
    {
        $p = $this->pref();
        $this->form->fill([
            'daily' => $p->daily ?? false, 'weekly' => $p->weekly ?? true, 'email' => $p->email ?? true, 'in_app' => $p->in_app ?? true,
            'min_change' => $p->min_change ?? 20, 'min_clicks' => $p->min_clicks ?? 50, 'property_ids' => $p->property_ids ?? [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Grid::make(2)->schema([
                Section::make('When')->schema([
                    Toggle::make('daily')->label('Daily, around 07:30')->helperText('The newest day against an average day of the week before.'),
                    Toggle::make('weekly')->label('Weekly, Monday morning')->helperText('Last 7 days against the 7 before, pages that moved, and how actions you did 4 weeks ago worked out.'),
                ]),
                Section::make('How')->schema([
                    Toggle::make('email')->label('Email to '.auth()->user()->email),
                    Toggle::make('in_app')->label('In the app (the bell)'),
                ]),
            ]),
            Section::make('What counts as big')->columns(3)->schema([
                TextInput::make('min_change')->label('Change of at least')->numeric()->minValue(5)->maxValue(90)->suffix('%')->required(),
                TextInput::make('min_clicks')->label('Ignore baselines under')->numeric()->minValue(0)->maxValue(100000)->suffix('clicks / sessions')->required()
                    ->helperText('Per week. Keeps small pages from alerting on noise.'),
                CheckboxList::make('property_ids')->label('Properties (none ticked = all)')
                    ->options(fn () => Filament::getTenant()->properties()->orderBy('name')->pluck('name', 'id')),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([
                Actions::make([
                    Action::make('save')->label('Save alerts')->submit('save'),
                    Action::make('preview')->label('Preview this week')->color('gray')->icon(Heroicon::OutlinedEye)->action(fn () => $this->preview()),
                ]),
            ]),
            Section::make('Preview')->visible(fn () => $this->previewItems !== null)->schema([
                View::make('filament.app.alert-items')->viewData(fn () => ['items' => $this->previewItems ?? []]),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['property_ids'] = array_values(array_map('intval', $data['property_ids'] ?? [])) ?: null;
        $pref = $this->pref();
        $pref->fill($data)->save();
        Notification::make()->title('Alerts saved')->success()->send();
    }

    public function preview(): void
    {
        $pref = $this->pref()->fill($this->form->getState());
        $pref->property_ids = array_values(array_map('intval', $pref->property_ids ?? [])) ?: null;
        $this->previewItems = app(DigestSender::class)->preview(Filament::getTenant(), $pref, 'weekly');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sent')
            ->query(AlertDigest::query()->where('team_id', Filament::getTenant()->id)->where('user_id', auth()->id()))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M Y H:i'),
                TextColumn::make('period')->badge(),
                TextColumn::make('items')->label('Changes')->state(fn (AlertDigest $r) => collect($r->items)->take(2)->map(fn ($i) => $i['property'].': '.$i['title'])->implode(' · ').(count($r->items) > 2 ? ' …' : ''))->wrap(),
                IconColumn::make('emailed')->boolean(),
            ])
            ->emptyStateHeading('Nothing sent yet');
    }
}
