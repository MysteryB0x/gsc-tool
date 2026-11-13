<?php

namespace App\Analytics;

use App\Google\AnalyticsData;
use App\Google\Credentials;
use App\Models\Analytics\Ga4AiReferral;
use App\Models\Analytics\Ga4Daily;
use App\Models\Analytics\Ga4LandingPage;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Daily GA4 sync for a property: totals per day (and Organic Search sessions), sessions from AI assistants
 * per day and source, and landing pages for the last 28 days vs the 28 before.
 */
class Ga4Sync
{
    public function __construct(protected Credentials $credentials) {}

    public function sync(Property $property): void
    {
        if (blank($property->ga4_property_id)) {
            return;
        }

        try {
            $ga = new AnalyticsData($this->credentials->forProperty($property));
            $id = $property->ga4_property_id;
            $to = CarbonImmutable::yesterday();
            $from = $property->ga4_synced_until
                ? CarbonImmutable::parse($property->ga4_synced_until)->subDays(3)      // GA4 still settles for a few days
                : $to->subDays((int) config('gsctool.sync.backfill_days'));
            $range = [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]];

            $daily = $ga->report($id, [
                'dateRanges' => $range,
                'dimensions' => [['name' => 'date']],
                'metrics' => array_map(fn ($m) => ['name' => $m], ['sessions', 'engagedSessions', 'activeUsers', 'keyEvents', 'totalRevenue']),
            ]);
            $organic = collect($ga->report($id, [
                'dateRanges' => $range,
                'dimensions' => [['name' => 'date']],
                'metrics' => [['name' => 'sessions']],
                'dimensionFilter' => ['filter' => ['fieldName' => 'sessionDefaultChannelGroup', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'Organic Search']]],
            ]))->pluck('sessions', 'date');

            $ai = $ga->report($id, [
                'dateRanges' => $range,
                'dimensions' => [['name' => 'date'], ['name' => 'sessionSource']],
                'metrics' => [['name' => 'sessions'], ['name' => 'keyEvents']],
                'dimensionFilter' => ['filter' => ['fieldName' => 'sessionSource', 'stringFilter' => ['matchType' => 'PARTIAL_REGEXP', 'value' => config('gsctool.ga4.ai_sources'), 'caseSensitive' => false]]],
            ]);

            $landing = $ga->report($id, [
                'dateRanges' => [
                    ['startDate' => $to->subDays(27)->toDateString(), 'endDate' => $to->toDateString(), 'name' => 'now'],
                    ['startDate' => $to->subDays(55)->toDateString(), 'endDate' => $to->subDays(28)->toDateString(), 'name' => 'prev'],
                ],
                'dimensions' => [['name' => 'landingPage']],
                'metrics' => array_map(fn ($m) => ['name' => $m], ['sessions', 'engagedSessions', 'keyEvents', 'totalRevenue']),
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => 5000,
            ]);

            $this->store($property, $daily, $organic->all(), $ai, $landing);
            $property->update(['ga4_has_access' => true, 'ga4_synced_until' => $to, 'ga4_error' => null]);
        } catch (Throwable $e) {
            $property->update(['ga4_error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function store(Property $property, array $daily, array $organic, array $ai, array $landing): void
    {
        $date = fn (string $d) => substr($d, 0, 4).'-'.substr($d, 4, 2).'-'.substr($d, 6, 2);   // GA4 dates are YYYYMMDD

        DB::transaction(function () use ($property, $daily, $organic, $ai, $landing, $date) {
            foreach (array_chunk($daily, 500) as $chunk) {
                Ga4Daily::upsert(array_map(fn ($r) => [
                    'property_id' => $property->id,
                    'date' => $date($r['date']),
                    'sessions' => (int) $r['sessions'],
                    'engaged_sessions' => (int) $r['engagedSessions'],
                    'active_users' => (int) $r['activeUsers'],
                    'key_events' => (int) $r['keyEvents'],
                    'revenue' => round($r['totalRevenue'], 2),
                    'organic_sessions' => (int) ($organic[$r['date']] ?? 0),
                ], $chunk), ['property_id', 'date'], ['sessions', 'engaged_sessions', 'active_users', 'key_events', 'revenue', 'organic_sessions']);
            }

            foreach (array_chunk($ai, 500) as $chunk) {
                Ga4AiReferral::upsert(array_map(fn ($r) => [
                    'property_id' => $property->id,
                    'date' => $date($r['date']),
                    'source' => mb_substr(strtolower($r['sessionSource']), 0, 255),
                    'sessions' => (int) $r['sessions'],
                    'key_events' => (int) $r['keyEvents'],
                ], $chunk), ['property_id', 'date', 'source'], ['sessions', 'key_events']);
            }

            $pages = [];
            foreach ($landing as $r) {
                $path = mb_substr((string) $r['landingPage'], 0, 1024);
                $pages[$path] ??= ['property_id' => $property->id, 'path' => $path, 'sessions' => 0, 'sessions_prev' => 0, 'engaged_sessions' => 0, 'key_events' => 0, 'revenue' => 0];
                if (($r['dateRange'] ?? 'now') === 'prev') {
                    $pages[$path]['sessions_prev'] += (int) $r['sessions'];
                } else {
                    $pages[$path]['sessions'] += (int) $r['sessions'];
                    $pages[$path]['engaged_sessions'] += (int) $r['engagedSessions'];
                    $pages[$path]['key_events'] += (int) $r['keyEvents'];
                    $pages[$path]['revenue'] += round($r['totalRevenue'], 2);
                }
            }
            Ga4LandingPage::where('property_id', $property->id)->delete();
            foreach (array_chunk(array_values($pages), 500) as $chunk) {
                Ga4LandingPage::insert($chunk);
            }
        });
    }
}
