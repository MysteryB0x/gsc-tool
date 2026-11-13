<?php

namespace App\Jobs;

use App\Analytics\Ga4Sync;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncGa4 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public function __construct(public Property $property) {}

    public function handle(Ga4Sync $sync): void
    {
        $sync->sync($this->property);
    }
}
