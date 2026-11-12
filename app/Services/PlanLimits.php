<?php

namespace App\Services;

use App\Models\Team;

/** Every plan limit, enforced server-side (same place as in WhoCitedMe). null = unlimited. */
class PlanLimits
{
    public function maxProperties(Team $team): ?int
    {
        return $team->plan ? $team->plan->max_properties : 0;
    }

    public function canAddProperty(Team $team): bool
    {
        $max = $this->maxProperties($team);

        return $max === null || $team->properties()->count() < $max;
    }

    public function canUseBulk(Team $team): bool
    {
        $max = $team->plan?->max_bulk_properties;

        return $max === null || $team->properties()->where('source', 'bulk')->count() < $max;
    }

    public function isActive(Team $team): bool
    {
        return in_array($team->subscription_status, ['active', 'trialing'], true)
            && ! ($team->subscription_status === 'trialing' && $team->trial_ends_at?->isPast());
    }
}
