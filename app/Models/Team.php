<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A workspace: the tenant of the /app panel. Owns properties, credits and the plan. */
class Team extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'credit_balance' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'credits_granted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Team $team) {
            if (blank($team->slug)) {
                $base = Str::slug($team->name) ?: 'team';
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $team->slug = $slug;
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function bqQueries(): HasMany
    {
        return $this->hasMany(BqQuery::class);
    }

    public function seoActions(): HasMany
    {
        return $this->hasMany(SeoAction::class);
    }

    public function hasUnlimitedCredits(): bool
    {
        return (bool) $this->plan?->unlimited_credits;
    }

    public function roleOf(?User $user): ?string
    {
        return $user ? $this->users()->whereKey($user->id)->first()?->pivot?->role : null;
    }
}
