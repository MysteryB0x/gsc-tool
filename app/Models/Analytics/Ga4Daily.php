<?php

namespace App\Models\Analytics;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

class Ga4Daily extends Model
{
    protected $table = 'ga4_daily';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'property_id' => 'integer', 'date' => DateOnly::class, 'revenue' => 'float',
            'sessions' => 'integer', 'sessions_prev' => 'integer', 'engaged_sessions' => 'integer', 'active_users' => 'integer',
            'key_events' => 'integer', 'organic_sessions' => 'integer',
        ];
    }
}
