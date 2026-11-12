<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of the credit ledger. Creating it moves the team's balance (same as WhoCitedMe). */
class CreditTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'team_id' => 'integer', 'user_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(function (CreditTransaction $tx) {
            Team::whereKey($tx->team_id)->increment('credit_balance', $tx->amount);
        });
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
