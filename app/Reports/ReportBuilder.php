<?php

namespace App\Reports;

use App\Models\DailyTotal;
use App\Models\Property;
use App\Models\Reports\CannibalisationRow;
use App\Models\Reports\CtrRow;
use App\Models\Reports\DecayRow;
use App\Models\Reports\StrikingRow;
use App\Search\QueryRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Runs the nightly report SQL in BigQuery and writes the small results to MariaDB, which is all the
 * dashboards read. These queries are paid by the plan (charge: false) but logged in bq_queries.
 */
class ReportBuilder
{
    public function __construct(protected QueryRunner $runner) {}

    public function build(Property $property): void
    {
        // Search Console data is final after ~3 days; reports end on the newest complete day.
        $end = CarbonImmutable::today()->subDays(3);
        if ($property->synced_until && $property->synced_until->lt($end)) {
            $end = CarbonImmutable::parse($property->synced_until);
        }

        $this->dailyTotals($property, $end);
        $this->decay($property, $end);
        $this->cannibalisationAndOpportunities($property, $end);

        $property->update(['reports_built_at' => now()]);
    }

    protected function run(Property $property, string $sql, string $purpose): array
    {
        return $this->runner->run($property->team, $sql, 'report:'.$purpose, property: $property, charge: false, cacheable: false)->rows;
    }

    public function dailyTotals(Property $property, CarbonImmutable $end): void
    {
        // First build: the whole history. After that: the last 30 days (late data is refreshed).
        $from = $property->dailyTotals()->exists()
            ? $end->subDays(30)
            : CarbonImmutable::today()->subDays((int) config('gsctool.sync.backfill_days'));

        $rows = $this->run($property, Sql::dailyTotals($property, $from->toDateString(), $end->toDateString()), 'daily_totals');

        DB::transaction(function () use ($property, $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DailyTotal::upsert(array_map(fn ($r) => [
                    'property_id' => $property->id,
                    'date' => $r['date'],
                    'clicks' => (int) $r['clicks'],
                    'impressions' => (int) $r['impressions'],
                    'ctr' => $r['impressions'] ? round($r['clicks'] / $r['impressions'], 6) : 0,
                    'position' => round((float) $r['position'], 3),
                ], $chunk), ['property_id', 'date'], ['clicks', 'impressions', 'ctr', 'position']);
            }
        });
    }

    public function decay(Property $property, CarbonImmutable $end): void
    {
        $cfg = config('gsctool.reports.decay');
        $lastMonthEnd = $end->startOfMonth()->subDay();                       // last complete month
        $from = $lastMonthEnd->startOfMonth()->subMonths($cfg['months'] - 1);

        $rows = $this->run($property, Sql::monthlyUrlClicks($property, $from->toDateString(), $lastMonthEnd->toDateString(), $cfg['min_clicks']), 'decay');

        $months = [];
        for ($m = $from; $m->lte($lastMonthEnd); $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = 0;
        }

        $records = [];
        foreach (collect($rows)->groupBy('url') as $url => $urlRows) {
            $series = array_merge($months, $urlRows->pluck('clicks', 'month')->map(fn ($c) => (int) $c)->all());
            $peak = max($series);
            $last = end($series);
            $change = $peak > 0 ? ($last - $peak) / $peak : 0;
            if ($peak >= $cfg['min_clicks'] && $change <= -$cfg['drop']) {
                $records[] = [
                    'property_id' => $property->id,
                    'url' => mb_substr((string) $url, 0, 2048),
                    'monthly_clicks' => json_encode($series),
                    'peak_clicks' => $peak,
                    'last_clicks' => $last,
                    'change' => round($change, 4),
                ];
            }
        }

        $this->replace(DecayRow::class, $property, $records);
    }

    public function cannibalisationAndOpportunities(Property $property, CarbonImmutable $end): void
    {
        $cfg = config('gsctool.reports');
        $from = $end->subDays($cfg['window_days'] - 1)->toDateString();
        $to = $end->toDateString();

        // Cannibalisation
        $rows = $this->run($property, Sql::cannibalisation($property, $from, $to, $cfg['cannibalisation']['min_share'], $cfg['cannibalisation']['min_impressions']), 'cannibalisation');
        $records = collect($rows)->groupBy('query')->map(fn ($urls, $query) => [
            'property_id' => $property->id,
            'query' => mb_substr((string) $query, 0, 1024),
            'impressions' => (int) $urls->first()['q_impressions'],
            'clicks' => (int) $urls->first()['q_clicks'],
            'url_count' => $urls->count(),
            'urls' => json_encode($urls->map(fn ($u) => [
                'url' => $u['url'], 'impressions' => (int) $u['impressions'], 'clicks' => (int) $u['clicks'],
                'position' => round((float) $u['position'], 1), 'share' => round((float) $u['share'], 3),
            ])->values()),
        ])->values()->all();
        $this->replace(CannibalisationRow::class, $property, $records);

        // Striking distance + CTR benchmark share one query.
        $pairs = $this->run($property, Sql::queryUrlWindow($property, $from, $to, $cfg['striking']['min_impressions']), 'query_url_window');

        $striking = [];
        $ctr = [];
        foreach ($pairs as $r) {
            $imp = (int) $r['impressions'];
            $clk = (int) $r['clicks'];
            $pos = (float) $r['position'];
            $base = ['property_id' => $property->id, 'query' => mb_substr((string) $r['query'], 0, 1024), 'url' => mb_substr((string) $r['url'], 0, 2048), 'impressions' => $imp, 'clicks' => $clk, 'position' => round($pos, 3)];

            if ($pos >= $cfg['striking']['min_position'] && $pos <= $cfg['striking']['max_position']) {
                $striking[] = $base + ['potential_clicks' => CtrCurve::potentialAtThree($imp, $clk)];
            }

            $expected = CtrCurve::expected($pos);
            $missed = (int) round($imp * $expected - $clk);
            if ($imp >= 100 && $missed > 0) {
                $ctr[] = $base + ['ctr' => round($imp ? $clk / $imp : 0, 6), 'expected_ctr' => $expected, 'missed_clicks' => $missed];
            }
        }

        usort($striking, fn ($a, $b) => $b['potential_clicks'] <=> $a['potential_clicks']);
        usort($ctr, fn ($a, $b) => $b['missed_clicks'] <=> $a['missed_clicks']);
        $this->replace(StrikingRow::class, $property, array_slice($striking, 0, 2000));
        $this->replace(CtrRow::class, $property, array_slice($ctr, 0, 2000));
    }

    /** @param class-string<Model> $model */
    protected function replace(string $model, Property $property, array $records): void
    {
        DB::transaction(function () use ($model, $property, $records) {
            $model::where('property_id', $property->id)->delete();
            foreach (array_chunk($records, 500) as $chunk) {
                $model::insert($chunk);
            }
        });
    }
}
