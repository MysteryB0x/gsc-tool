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

        // GA4-only properties (no Search Console access yet) still get their GA4 sync.
        Property::where('is_active', true)->where('has_access', false)->whereNotNull('ga4_property_id')
            ->when($this->option('property'), fn ($q, $id) => $q->whereKey($id))->each(fn ($p) => $planner->ga4($p));

        foreach ($properties as $property) {
            $this->option('backfill') ? $planner->backfill($property) : $planner->daily($property);
            $this->line("Queued: {$property->name}");
        }

        return self::SUCCESS;
    }
}
