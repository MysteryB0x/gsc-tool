<?php

namespace App\Analytics;

use App\Models\Analytics\Ga4AiReferral;
use App\Models\Analytics\Ga4Daily;
use App\Models\Property;
use Carbon\CarbonImmutable;

/** GA4 totals for a window ending on the newest synced day, against the window before. */
class Ga4Summary
{
    public static function newest(Property $property): ?CarbonImmutable
    {
        $d = Ga4Daily::where('property_id', $property->id)->max('date');

        return $d ? CarbonImmutable::parse($d) : null;
    }

    /** @return array{now: array, before: array}|null */
    public static function compare(Property $property, int $days = 28): ?array
    {
        $end = static::newest($property);
        if (! $end) {
            return null;
        }

        return [
            'now' => static::totals($property, $end->subDays($days - 1), $end),
            'before' => static::totals($property, $end->subDays(2 * $days - 1), $end->subDays($days)),
            'end' => $end,
        ];
    }

    public static function totals(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $d = Ga4Daily::where('property_id', $property->id)->whereBetween('date', $range)
            ->selectRaw('SUM(sessions) s, SUM(engaged_sessions) e, SUM(key_events) k, SUM(revenue) r, SUM(organic_sessions) o')->first();
        $ai = (int) Ga4AiReferral::where('property_id', $property->id)->whereBetween('date', $range)->sum('sessions');
        $s = (int) $d->s;

        return [
            'sessions' => $s,
            'engaged_rate' => $s ? $d->e / $s : 0,
            'key_events' => (int) $d->k,
            'conversion_rate' => $s ? $d->k / $s : 0,
            'revenue' => (float) $d->r,
            'organic_sessions' => (int) $d->o,
            'ai_sessions' => $ai,
        ];
    }
}
