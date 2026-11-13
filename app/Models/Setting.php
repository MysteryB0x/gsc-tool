<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Key/value settings, cached so pages don't query them on every request. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_()[$key] ?? $default;
    }

    /** Every setting as key => value. Empty (defaults everywhere) before the first migration. */
    public static function all_(): array
    {
        try {
            return Cache::rememberForever('settings', fn () => static::query()->pluck('value', 'key')->all());
        } catch (\Throwable) {
            return [];
        }
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings');
    }
}
