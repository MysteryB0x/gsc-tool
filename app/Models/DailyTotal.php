<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

class DailyTotal extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => DateOnly::class, 'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'float', 'position' => 'float', 'property_id' => 'integer'];
    }
}
