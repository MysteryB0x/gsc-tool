<?php

namespace App\Providers\Filament;

use App\Support\Branding;
use App\Support\TwoFactor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** /admin — the owner's panel: users, workspaces, plans, credits and the BigQuery cost log. */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return TwoFactor::configure($panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->globalSearch(false)
            ->brandName(fn () => Branding::name().' Admin')
            ->brandLogo(fn () => Branding::logoUrl())
            ->darkModeBrandLogo(fn () => Branding::assetUrl('logo_dark') ?? Branding::assetUrl('logo'))
            ->brandLogoHeight(fn () => Branding::get('logo_height') ?: '2rem')
            ->favicon(fn () => Branding::assetUrl('favicon'))
            ->colors(fn () => Branding::colors())
            ->font(fn () => Branding::font())
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => new HtmlString('<style>'.Branding::css().'</style>'))
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Customers'),
                NavigationGroup::make('Costs'),
                NavigationGroup::make('Settings'),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
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
            ->authMiddleware([Authenticate::class]));
    }
}
