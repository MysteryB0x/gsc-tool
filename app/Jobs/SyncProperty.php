<?php

namespace App\Jobs;

use App\Models\Property;
use App\Search\GscSync;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sync a few days of one property. Backfills are split into many of these so each fits a short worker run. */
class SyncProperty implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public function __construct(public Property $property, public string $from, public string $to) {}

    public function handle(GscSync $sync): void
    {
        $sync->syncRange($this->property, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));
    }
}
