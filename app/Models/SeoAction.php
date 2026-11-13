<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something you did (or plan to do) to a site. Done actions show as markers on the trend chart. */
class SeoAction extends Model
{
    protected $table = 'actions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['done_on' => DateOnly::class, 'team_id' => 'integer', 'property_id' => 'integer', 'user_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (SeoAction $action) {
            $action->team_id ??= Property::whereKey($action->property_id)->value('team_id');
            $action->user_id ??= auth()->id();
        });
        static::saving(function (SeoAction $action) {
            if ($action->status === 'done' && ! $action->done_on) {
                $action->done_on = today();
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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
