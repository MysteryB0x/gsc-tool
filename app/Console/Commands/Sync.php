<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Search\SyncPlanner;
use Illuminate\Console\Command;

class Sync extends Command
{
    protected $signature = 'gsc:sync {--property= : Only this property id} {--backfill : Fetch the full 16 months}';

    protected $description = 'Queue the Search Console → BigQuery sync and the report build for active properties';

    public function handle(SyncPlanner $planner): int
    {
        $properties = Property::where('is_active', true)->where('has_access', true)
            ->when($this->option('property'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($properties as $property) {
            $this->option('backfill') ? $planner->backfill($property) : $planner->daily($property);
            $this->line("Queued: {$property->name}");
        }

        return self::SUCCESS;
    }
}
