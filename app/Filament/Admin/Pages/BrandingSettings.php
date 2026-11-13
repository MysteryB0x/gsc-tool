<?php

namespace App\Filament\Admin\Pages;

use App\Models\AuditLog;
use App\Support\Branding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Admin → Branding: name, logos, colours, font and home screen for both panels. */
class BrandingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Branding';

    protected static ?string $title = 'Branding';

    protected static ?string $slug = 'branding';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(Branding::all());
    }

    public function form(Schema $schema): Schema
    {
        $hexRule = ['regex:/^#[0-9a-fA-F]{6}$/'];

        return $schema->statePath('data')->components([
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('name')->label('Tool name')->required()->maxLength(40)
                    ->helperText('Shown in the panels, page titles and alert emails.'),
                TextInput::make('logo_height')->label('Logo height')->placeholder('2rem')->regex('/^\d+(\.\d+)?(rem|px)$/'),
                FileUpload::make('logo')->label('Logo (light background)')->disk('local')->directory('branding')->image()
                    ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp'])->maxSize(1024)
                    ->helperText('SVG or PNG. Without a logo the name is shown as text.'),
                FileUpload::make('logo_dark')->label('Logo (dark background)')->disk('local')->directory('branding')->image()
                    ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp'])->maxSize(1024)
                    ->helperText('Used in dark mode, and on the dark sidebar.'),
                FileUpload::make('favicon')->disk('local')->directory('branding')
                    ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon'])->maxSize(256),
            ]),
            Section::make('Colours')->columns(2)->schema([
                ColorPicker::make('primary_color')->label('Primary (buttons, active states)')->hex()->required()->rules($hexRule)->live(),
                ColorPicker::make('background_color')->label('Page background')->hex()->required()->rules($hexRule)->live(),
                ToggleButtons::make('sidebar_style')->label('Sidebar')->options(['light' => 'Light', 'dark' => 'Coloured'])->inline()->required()->live(),
                ColorPicker::make('sidebar_color')->label('Sidebar colour')->hex()->rules($hexRule)
                    ->visible(fn (Get $get) => $get('sidebar_style') === 'dark')->live(),
                Text::make(fn (Get $get) => $this->contrastNote($get('primary_color'), $get('background_color')))->columnSpanFull(),
            ]),
            Section::make('Layout')->columns(2)->schema([
                Select::make('font')->options(array_combine(Branding::FONTS, Branding::FONTS))->required()->native(false),
                Radio::make('home')->label('Home screen')->options(Branding::HOMES)->required(),
            ]),
        ]);
    }

    protected function contrastNote(?string $primary, ?string $background): string
    {
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $primary) || ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $background)) {
            return '';
        }
        $onButton = round(Branding::contrast($primary, '#FFFFFF'), 1);
        $onPage = round(Branding::contrast($primary, $background), 1);
        $ok = $onButton >= 4.5;

        return ($ok ? 'Readable: ' : 'Hard to read: ')."white text on the primary colour is {$onButton}:1".($ok ? '' : ' (aim for 4.5:1 or more; pick a darker shade)')
            ."; the primary colour on the page background is {$onPage}:1.";
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([
                Actions::make([
                    Action::make('save')->label('Save branding')->submit('save'),
                    Action::make('reset')->label('Reset to defaults')->color('gray')->requiresConfirmation()
                        ->action(fn () => $this->resetToDefaults()),
                ]),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        Branding::save($data);
        AuditLog::record('branding.updated', null, array_diff_key($data, array_flip(['logo', 'logo_dark', 'favicon'])));
        Notification::make()->title('Branding saved')->body('Reload a page to see it everywhere.')->success()->send();
    }

    public function resetToDefaults(): void
    {
        Branding::save(array_map(fn () => null, Branding::defaults()));
        AuditLog::record('branding.reset');
        $this->form->fill(Branding::all());
        Notification::make()->title('Branding reset')->success()->send();
    }
}
