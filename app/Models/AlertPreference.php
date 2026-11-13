<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertPreference extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'team_id' => 'integer', 'user_id' => 'integer', 'daily' => 'boolean', 'weekly' => 'boolean',
            'email' => 'boolean', 'in_app' => 'boolean', 'min_change' => 'integer', 'min_clicks' => 'integer',
            'property_ids' => 'array',
        ];
    }

    /** The person's settings in this workspace, or the defaults (weekly, email + in-app) when never saved. */
    public static function for(Team $team, User $user): self
    {
        return static::firstOrNew(['team_id' => $team->id, 'user_id' => $user->id]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
