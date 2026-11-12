<?php

namespace App\Search;

use App\Google\BigQuery;
use App\Google\Credentials;
use App\Google\SearchConsole;
use App\Models\Property;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Throwable;

/**
 * Copies Search Console API data into the property's BigQuery table, one day per load job.
 * Each load replaces its day's partition, so re-running a day is always safe.
 * Bulk Export properties are skipped: Google writes those tables itself.
 */
class GscSync
{
    public function __construct(protected Credentials $credentials, protected BigQuery $bigQuery) {}

    public function prepare(Property $property): void
    {
        $this->bigQuery->ensureDataset(Tables::apiDataset());
        $this->bigQuery->ensureTable(Tables::apiDataset(), Tables::apiTable($property), Tables::SCHEMA, 'data_date', ['query', 'url', 'country']);
    }

    public function syncRange(Property $property, CarbonImmutable $from, CarbonImmutable $to): SyncRun
    {
        $run = $property->syncRuns()->create(['date_from' => $from, 'date_to' => $to, 'status' => 'running']);

        if ($property->isBulk()) {
            $run->update(['status' => 'done', 'error' => 'Bulk Export property: Google loads this data.']);

            return $run;
        }

        try {
            $this->prepare($property);
            $gsc = new SearchConsole($this->credentials->forProperty($property));
            $total = 0;

            foreach (CarbonPeriod::create($from, $to) as $day) {
                $rows = $gsc->day($property->site_url, $day->toDateString());
                if (! $rows) {
                    continue;
                }
                $this->bigQuery->loadJson(Tables::apiDataset(), Tables::apiTable($property), $this->toNdjson($property, $day->toDateString(), $rows), $day->format('Ymd'));
                $total += count($rows);
            }

            $run->update(['status' => 'done', 'rows' => $total]);
            $property->update([
                'synced_until' => $property->synced_until && $property->synced_until->gt($to) ? $property->synced_until : $to,
                'backfilled_from' => $property->backfilled_from && $property->backfilled_from->lt($from) ? $property->backfilled_from : $from,
                'last_synced_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $property->update(['last_error' => $e->getMessage()]);
            throw $e;
        }

        return $run;
    }

    /** API rows → Bulk Export-shaped JSON lines. The API gives an average 1-based position; Bulk stores a 0-based sum. */
    public function toNdjson(Property $property, string $date, array $rows): string
    {
        return implode("\n", array_map(fn (array $r) => json_encode([
            'data_date' => $date,
            'site_url' => $property->site_url,
            'url' => $r['page'],
            'query' => $r['query'],
            'is_anonymized_query' => false,
            'country' => $r['country'],
            'search_type' => 'WEB',
            'device' => $r['device'],
            'impressions' => $r['impressions'],
            'clicks' => $r['clicks'],
            'sum_position' => max(0, $r['position'] - 1) * $r['impressions'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rows));
    }
}
