<?php

namespace App\Filament\App\Widgets;

use App\Models\DailyTotal;
use App\Models\Property;
use App\Support\CurrentProperty;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Clicks, impressions, CTR and position: last 28 days against the 28 before. */
class KpiStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $property = CurrentProperty::get();
        if (! $property || ! ($end = $property->dailyTotals()->max('date'))) {
            return [Stat::make('No data yet', '—')->description('Add a property; the first sync and reports run in the background.')];
        }

        $end = CarbonImmutable::parse($end);
        $now = $this->totals($property, $end->subDays(27), $end);
        $before = $this->totals($property, $end->subDays(55), $end->subDays(28));

        return [
            $this->stat('Clicks', number_format($now['clicks']), $now['clicks'], $before['clicks']),
            $this->stat('Impressions', number_format($now['impressions']), $now['impressions'], $before['impressions']),
            $this->stat('CTR', round($now['ctr'] * 100, 2).'%', $now['ctr'], $before['ctr']),
            $this->stat('Avg. position', number_format($now['position'], 1), $now['position'], $before['position'], lowerIsBetter: true),
        ];
    }

    protected function totals(Property $property, $from, $to): array
    {
        $row = DailyTotal::where('property_id', $property->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) pw')->first();
        $c = (int) $row->c;
        $i = (int) $row->i;

        return ['clicks' => $c, 'impressions' => $i, 'ctr' => $i ? $c / $i : 0, 'position' => $i ? $row->pw / $i : 0];
    }

    protected function stat(string $label, string $value, float $now, float $before, bool $lowerIsBetter = false): Stat
    {
        if ($before == 0) {
            return Stat::make($label, $value)->description('Last 28 days');
        }
        $change = ($now - $before) / $before;
        $good = $lowerIsBetter ? $change < 0 : $change > 0;

        return Stat::make($label, $value)
            ->description(($change >= 0 ? '+' : '').round($change * 100, 1).'% vs previous 28 days')
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color(abs($change) < 0.005 ? 'gray' : ($good ? 'success' : 'danger'));
    }
}
