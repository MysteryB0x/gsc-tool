<?php

namespace App\Filament\App\Widgets;

use App\Analytics\Ga4Summary;
use App\Support\CurrentProperty;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** GA4 for the picked property: sessions, key events, revenue and AI-assistant visits, 28 days vs the 28 before. */
class Ga4Stats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Google Analytics';

    public static function canView(): bool
    {
        return filled(CurrentProperty::get()?->ga4_property_id);
    }

    protected function getDescription(): ?string
    {
        $c = ($p = CurrentProperty::get()) ? Ga4Summary::compare($p) : null;

        return $c ? 'Last 28 days to '.$c['end']->format('j M').' vs the 28 before.' : 'Waiting for the first GA4 sync.';
    }

    protected function getStats(): array
    {
        $property = CurrentProperty::get();
        $c = $property ? Ga4Summary::compare($property) : null;
        if (! $c) {
            return [Stat::make('Sessions', '—')->description($property?->ga4_error ?? 'Syncs tonight.')];
        }
        [$now, $before] = [$c['now'], $c['before']];

        $stats = [
            $this->stat('Sessions', number_format($now['sessions']), $now['sessions'], $before['sessions']),
            $this->stat('Organic search sessions', number_format($now['organic_sessions']), $now['organic_sessions'], $before['organic_sessions']),
            $this->stat('Key events', number_format($now['key_events']), $now['key_events'], $before['key_events'])
                ->description(round($now['conversion_rate'] * 100, 2).'% of sessions'),
            $this->stat('From AI assistants', number_format($now['ai_sessions']), $now['ai_sessions'], $before['ai_sessions']),
        ];
        if ($now['revenue'] > 0 || $before['revenue'] > 0) {
            $stats[] = $this->stat('Revenue', number_format($now['revenue'], 0), $now['revenue'], $before['revenue']);
        }

        return $stats;
    }

    protected function stat(string $label, string $value, float $now, float $before): Stat
    {
        if ($before == 0) {
            return Stat::make($label, $value);
        }
        $change = ($now - $before) / $before;

        return Stat::make($label, $value)
            ->description(($change >= 0 ? '+' : '').round($change * 100, 1).'% vs previous 28 days')
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color(abs($change) < 0.005 ? 'gray' : ($change > 0 ? 'success' : 'danger'));
    }
}
