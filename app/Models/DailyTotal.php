<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyTotal extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'float', 'position' => 'float', 'property_id' => 'integer'];
    }
}
