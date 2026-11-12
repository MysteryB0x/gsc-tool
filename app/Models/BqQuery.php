<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One BigQuery job, with its estimated and billed bytes and the credits it cost. */
class BqQuery extends Model
{
    protected $guarded = [];

    protected $hidden = ['result_json'];

    protected function casts(): array
    {
        return [
            'team_id' => 'integer', 'user_id' => 'integer', 'property_id' => 'integer',
            'bytes_estimated' => 'integer', 'bytes_billed' => 'integer',
            'credits_reserved' => 'integer', 'credits_charged' => 'integer', 'duration_ms' => 'integer',
            'charged' => 'boolean', 'from_cache' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** What BigQuery charges for the bytes billed, in USD (on-demand list price). */
    public function costUsd(): float
    {
        return $this->bytes_billed / 1024 ** 4 * 6.25;
    }
}
