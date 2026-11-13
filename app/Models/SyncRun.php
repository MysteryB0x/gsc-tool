<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_from' => DateOnly::class, 'date_to' => DateOnly::class, 'rows' => 'integer', 'property_id' => 'integer'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
