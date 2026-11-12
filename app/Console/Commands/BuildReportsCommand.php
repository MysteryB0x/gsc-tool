<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Reports\ReportBuilder;
use Illuminate\Console\Command;

class BuildReportsCommand extends Command
{
    protected $signature = 'reports:build {--property= : Only this property id}';

    protected $description = 'Rebuild the report tables now (runs the BigQuery SQL inline, not queued)';

    public function handle(ReportBuilder $builder): int
    {
        $properties = Property::where('is_active', true)->when($this->option('property'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($properties as $property) {
            $builder->build($property);
            $this->info("Built: {$property->name}");
        }

        return self::SUCCESS;
    }
}
