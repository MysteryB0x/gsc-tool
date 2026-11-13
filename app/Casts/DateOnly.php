<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar day stored as "Y-m-d" on every database (Laravel's date cast adds " 00:00:00", which breaks
 * where('date', '2026-11-10') and BETWEEN on SQLite). Read back as a Carbon date.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value))->toDateString();
    }
}
