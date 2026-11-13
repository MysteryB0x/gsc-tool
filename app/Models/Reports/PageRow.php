<?php

namespace App\Models\Reports;

use Illuminate\Database\Eloquent\Model;

/** Search Console totals per page: last 7 vs 7 before, last 28 vs 28 before. Rebuilt nightly. */
class PageRow extends Model
{
    protected $table = 'report_pages';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'property_id' => 'integer', 'clicks_7' => 'integer', 'clicks_prev_7' => 'integer', 'clicks_28' => 'integer',
            'clicks_prev_28' => 'integer', 'impressions_28' => 'integer', 'position_28' => 'float',
        ];
    }
}
