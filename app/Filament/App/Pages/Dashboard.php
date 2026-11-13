<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\PicksProperty;
use App\Filament\App\Widgets\ClicksTrend;
use App\Filament\App\Widgets\Ga4Stats;
use App\Filament\App\Widgets\KpiStats;
use App\Filament\App\Widgets\TopOpportunities;
use App\Support\Branding;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use PicksProperty;

    protected static ?string $title = 'Dashboard';

    public function mount(): void
    {
        // Admin → Branding → Home screen: the inbox. ?dashboard=1 still opens this page.
        if (Branding::home() === 'inbox' && ! request()->boolean('dashboard')) {
            $this->redirect(Inbox::getUrl());
        }
    }

    public static function getNavigationUrl(): string
    {
        return static::getUrl(Branding::home() === 'inbox' ? ['dashboard' => 1] : []);
    }

    public function getWidgets(): array
    {
        return [KpiStats::class, Ga4Stats::class, ClicksTrend::class, TopOpportunities::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
