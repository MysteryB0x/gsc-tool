<?php

namespace App\Models\Reports;

use App\Models\Property;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pre-computed row, rebuilt nightly by App\Reports. */
class CannibalisationRow extends Model
{
    protected $table = 'report_cannibalisation';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'property_id' => 'integer', 'impressions' => 'integer', 'clicks' => 'integer', 'url_count' => 'integer',
            'potential_clicks' => 'integer', 'missed_clicks' => 'integer', 'peak_clicks' => 'integer', 'last_clicks' => 'integer',
            'position' => 'float', 'ctr' => 'float', 'expected_ctr' => 'float', 'change' => 'float',
            'urls' => 'array', 'monthly_clicks' => 'array',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
