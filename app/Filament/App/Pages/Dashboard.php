<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\PicksProperty;
use App\Filament\App\Widgets\ClicksTrend;
use App\Filament\App\Widgets\KpiStats;
use App\Filament\App\Widgets\TopOpportunities;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use PicksProperty;

    protected static ?string $title = 'Dashboard';

    public function getWidgets(): array
    {
        return [KpiStats::class, ClicksTrend::class, TopOpportunities::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
