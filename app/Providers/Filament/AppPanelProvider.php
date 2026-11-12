<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\Tenancy\RegisterTeam;
use App\Models\Team;
use App\Support\TwoFactor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** /app — the tool itself, one workspace (team) per URL slug. */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            ->globalSearch(false)
            ->brandName(fn () => config('gsctool.name'))
            ->colors(['primary' => Color::hex(config('gsctool.primary_color')), 'gray' => Color::Slate])
            ->font('Inter')
            ->sidebarCollapsibleOnDesktop()
            ->tenant(Team::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterTeam::class)
            ->tenantMenu(fn () => (auth()->user()?->teams()->count() ?? 0) > 1)
            ->navigationGroups([
                NavigationGroup::make('Opportunities'),
                NavigationGroup::make('Analyse'),
                NavigationGroup::make('Settings')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
            ->pages([Dashboard::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);

        // Sign-ups only when they're switched on (GSCT_REGISTRATION=true): until then it's the owner's tool.
        $panel = TwoFactor::configure($panel);

        return config('gsctool.registration') ? $panel->registration() : $panel;
    }
}
