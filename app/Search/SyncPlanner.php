<?php

namespace App\Search;

use App\Jobs\BuildReports;
use App\Jobs\SyncGa4;
use App\Jobs\SyncProperty;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

/** Turns "backfill" and "daily" into chains of small queue jobs, ending with a report build. */
class SyncPlanner
{
    public const CHUNK_DAYS = 7;

    /** Search Console's full 16 months, oldest first, in weekly jobs; then the reports. */
    public function backfill(Property $property): void
    {
        $this->ga4($property);

        if ($property->isBulk()) {
            Bus::dispatch(new BuildReports($property));

            return;
        }

        $to = CarbonImmutable::yesterday();
        $from = $to->subDays((int) config('gsctool.sync.backfill_days'));
        $this->chain($property, $from, $to);
    }

    /** The last few days again (Search Console data settles after 2–3 days); then the reports. */
    public function daily(Property $property): void
    {
        $this->ga4($property);

        if ($property->isBulk()) {
            Bus::dispatch(new BuildReports($property));

            return;
        }

        $to = CarbonImmutable::yesterday();
        $from = $property->synced_until
            ? CarbonImmutable::parse($property->synced_until)->subDays((int) config('gsctool.sync.refetch_days') - 1)
            : $to->subDays((int) config('gsctool.sync.backfill_days'));
        $this->chain($property, $from->min($to), $to);
    }

    /** GA4 runs on its own: it doesn't wait for Search Console, and a GA4 problem never blocks it. */
    public function ga4(Property $property): void
    {
        if (filled($property->ga4_property_id)) {
            SyncGa4::dispatch($property);
        }
    }

    protected function chain(Property $property, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $jobs = [];
        for ($start = $from; $start->lte($to); $start = $start->addDays(self::CHUNK_DAYS)) {
            $end = $start->addDays(self::CHUNK_DAYS - 1)->min($to);
            $jobs[] = new SyncProperty($property, $start->toDateString(), $end->toDateString());
        }
        $jobs[] = new BuildReports($property);

        Bus::chain($jobs)->dispatch();
    }
}
