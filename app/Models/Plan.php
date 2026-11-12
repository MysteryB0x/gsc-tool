<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_monthly_cents' => 'integer', 'price_yearly_cents' => 'integer',
            'max_properties' => 'integer', 'max_bulk_properties' => 'integer', 'max_members' => 'integer',
            'monthly_credits' => 'integer', 'trial_days' => 'integer', 'sort' => 'integer',
            'unlimited_credits' => 'boolean', 'is_public' => 'boolean',
        ];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}
