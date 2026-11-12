<?php

namespace App\Credits;

use App\Models\CreditTransaction;
use App\Models\Team;
use App\Models\User;

/** The credit ledger: grants, spending and the BigQuery bytes → credits conversion. */
class Credits
{
    /** Credits for a number of billed bytes: rounded up, at least the per-query minimum. 0 bytes = 0 credits. */
    public static function forBytes(int $bytes): int
    {
        if ($bytes <= 0) {
            return 0;
        }
        $bytes = max($bytes, (int) config('gsctool.bigquery.min_bytes_billed'));

        return max((int) config('gsctool.credits.min_per_query', 1), (int) ceil($bytes / config('gsctool.credits.bytes_per_credit')));
    }

    public static function bytesFor(int $credits): int
    {
        return $credits * (int) config('gsctool.credits.bytes_per_credit');
    }

    public function add(Team $team, int $amount, string $reason, ?User $user = null, ?string $reference = null): CreditTransaction
    {
        return CreditTransaction::create([
            'team_id' => $team->id,
            'amount' => $amount,
            'reason' => $reason,
            'user_id' => $user?->id ?? auth()->id(),
            'reference' => $reference,
        ]);
    }

    public function balance(Team $team): int
    {
        return (int) Team::whereKey($team->id)->value('credit_balance');
    }

    /** Credits spent today (reservations minus releases). */
    public function spentToday(Team $team): int
    {
        return -1 * (int) $team->creditTransactions()
            ->whereIn('reason', ['bq_reserve', 'bq_release', 'ai_brief', 'serp_context'])
            ->where('created_at', '>=', today())
            ->sum('amount');
    }

    /** Throws when the team can't spend $credits now (balance or daily cap). Unlimited plans always can. */
    public function assertCanSpend(Team $team, int $credits): void
    {
        if ($team->hasUnlimitedCredits() || $credits === 0) {
            return;
        }

        $balance = $this->balance($team);
        if ($balance < $credits) {
            throw new InsufficientCredits($credits, $balance);
        }

        $cap = config('gsctool.credits.daily_cap');
        if ($cap !== null && $this->spentToday($team) + $credits > $cap) {
            throw new InsufficientCredits($credits, $balance, "daily limit of {$cap} credits reached");
        }
    }

    /** The plan's monthly credits, once per period. Called daily by the scheduler. */
    public function grantMonthly(Team $team): bool
    {
        $credits = (int) ($team->plan?->monthly_credits ?? 0);
        if ($credits <= 0 || ($team->credits_granted_at && $team->credits_granted_at->gt(now()->subMonth()))) {
            return false;
        }

        $this->add($team, $credits, 'plan_grant', reference: $team->plan->key.':'.now()->format('Y-m'));
        $team->forceFill(['credits_granted_at' => now()])->save();

        return true;
    }
}
