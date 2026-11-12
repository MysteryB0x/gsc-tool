<?php

namespace App\Filament\Admin\Widgets;

use App\Models\BqQuery;
use App\Models\Team;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/** This month's BigQuery spend against credits charged: the number that sets plan prices. */
class CostStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $month = BqQuery::where('created_at', '>=', now()->startOfMonth());
        $bytes = (int) (clone $month)->sum('bytes_billed');
        $credits = (int) (clone $month)->sum('credits_charged');

        return [
            Stat::make('BigQuery billed this month', Number::fileSize($bytes))->description('$'.number_format($bytes / 1024 ** 4 * 6.25, 2).' at on-demand price'),
            Stat::make('Credits charged this month', number_format($credits)),
            Stat::make('Queries this month', number_format((clone $month)->count()))->description((clone $month)->where('status', 'failed')->count().' failed'),
            Stat::make('Workspaces', Team::count()),
        ];
    }
}
