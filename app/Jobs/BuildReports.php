<?php

namespace App\Jobs;

use App\Models\Property;
use App\Reports\ReportBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public array $backoff = [600];

    public function __construct(public Property $property) {}

    public function handle(ReportBuilder $builder): void
    {
        $builder->build($this->property);
    }
}
