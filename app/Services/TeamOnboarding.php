<?php

namespace App\Services;

use App\Credits\Credits;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TeamOnboarding
{
    /** A workspace with the user as owner: admins get the owner plan (unlimited credits), others the sign-up plan. */
    public function createTeam(User $owner, string $name): Team
    {
        return DB::transaction(function () use ($owner, $name) {
            $plan = Plan::where('key', $owner->is_admin ? config('gsctool.owner_plan') : config('gsctool.signup_plan'))->first();

            $team = Team::create([
                'name' => $name,
                'plan_id' => $plan?->id,
                'subscription_status' => $plan?->trial_days ? 'trialing' : 'active',
                'trial_ends_at' => $plan?->trial_days ? now()->addDays($plan->trial_days) : null,
            ]);
            $team->users()->attach($owner, ['role' => 'owner']);
            app(Credits::class)->grantMonthly($team->setRelation('plan', $plan));

            return $team;
        });
    }
}
