<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDigest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['team_id' => 'integer', 'user_id' => 'integer', 'period_end' => DateOnly::class, 'items' => 'array', 'emailed' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
