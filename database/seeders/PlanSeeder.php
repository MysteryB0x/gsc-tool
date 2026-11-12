<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Plans. Prices and credits are first guesses: set them after a month of real cost data
 * (Admin → BigQuery queries). null = unlimited.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['key' => 'owner', 'name' => 'Owner', 'price_monthly_cents' => 0, 'price_yearly_cents' => 0, 'max_properties' => null, 'max_bulk_properties' => null, 'max_members' => null, 'monthly_credits' => 0, 'unlimited_credits' => true, 'is_public' => false, 'sort' => 0],
            ['key' => 'starter', 'name' => 'Starter', 'price_monthly_cents' => 2900, 'price_yearly_cents' => 29000, 'max_properties' => 10, 'max_bulk_properties' => 2, 'max_members' => 2, 'monthly_credits' => 100, 'unlimited_credits' => false, 'trial_days' => 7, 'sort' => 1],
            ['key' => 'pro', 'name' => 'Pro', 'price_monthly_cents' => 7900, 'price_yearly_cents' => 79000, 'max_properties' => 50, 'max_bulk_properties' => 10, 'max_members' => 5, 'monthly_credits' => 500, 'unlimited_credits' => false, 'trial_days' => 7, 'sort' => 2],
            ['key' => 'agency', 'name' => 'Agency', 'price_monthly_cents' => 24900, 'price_yearly_cents' => 249000, 'max_properties' => 300, 'max_bulk_properties' => 50, 'max_members' => 15, 'monthly_credits' => 2500, 'unlimited_credits' => false, 'trial_days' => 7, 'sort' => 3],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['key' => $plan['key']], $plan + ['trial_days' => 0, 'is_public' => true]);
        }
    }
}
