<?php

namespace App\Search;

use App\Credits\Credits;
use App\Google\BigQuery;
use App\Models\BqQuery;
use App\Models\Property;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every BigQuery query goes through here, so no query can cost more than was paid for:
 *
 * 1. Same query in the last cache_hours → answered from MariaDB, 0 credits.
 * 2. Dry run (free) → estimated bytes → credits.
 * 3. Balance and daily cap checked; the credits are reserved.
 * 4. Run with maximumBytesBilled = the reserved credits: BigQuery itself refuses anything bigger.
 * 5. Settle on the bytes actually billed; the rest of the reservation goes back.
 *
 * Nightly reports run with $charge = false: they're paid by the plan, but still logged in bq_queries.
 */
class QueryRunner
{
    public function __construct(protected BigQuery $bigQuery, protected Credits $credits) {}

    /** Credits the query would cost now (dry run; free). */
    public function estimate(string $sql): int
    {
        return Credits::forBytes($this->bigQuery->dryRun($sql));
    }

    public function run(
        Team $team,
        string $sql,
        string $purpose,
        ?User $user = null,
        ?Property $property = null,
        bool $charge = true,
        bool $cacheable = true,
    ): QueryResult {
        $hash = hash('sha256', $sql);
        $base = ['team_id' => $team->id, 'user_id' => $user?->id, 'property_id' => $property?->id, 'purpose' => $purpose, 'sql_hash' => $hash, 'sql' => $sql];

        if ($cacheable && ($cached = $this->cached($team, $hash))) {
            $log = BqQuery::create($base + ['from_cache' => true, 'status' => 'done']);

            return new QueryResult(json_decode($cached->result_json, true), 0, 0, true, $log->id);
        }

        $started = microtime(true);
        $estimated = $this->bigQuery->dryRun($sql);
        $metered = $charge && ! $team->hasUnlimitedCredits();
        $maxPerQuery = (int) config('gsctool.bigquery.max_bytes_per_query');

        if ($estimated > $maxPerQuery) {
            BqQuery::create($base + ['bytes_estimated' => $estimated, 'status' => 'refused', 'error' => 'Over the per-query limit.']);
            throw new \RuntimeException('This query would scan '.round($estimated / 1024 ** 3, 1).' GiB, over the per-query limit. Narrow the date range or filters.');
        }

        $reserved = $metered ? Credits::forBytes($estimated) : 0;
        $log = DB::transaction(function () use ($team, $reserved, $user, $base, $estimated, $metered) {
            $this->credits->assertCanSpend($team, $reserved);
            $log = BqQuery::create($base + ['bytes_estimated' => $estimated, 'credits_reserved' => $reserved, 'charged' => $metered, 'status' => 'running']);
            if ($reserved > 0) {
                $this->credits->add($team, -$reserved, 'bq_reserve', $user, 'bq:'.$log->id);
            }

            return $log;
        });

        // Cap = what was reserved (at least BigQuery's 10 MB minimum), never above the per-query limit.
        $maxBytes = $metered
            ? min($maxPerQuery, max(Credits::bytesFor($reserved), (int) config('gsctool.bigquery.min_bytes_billed')))
            : $maxPerQuery;

        try {
            $result = $this->bigQuery->query($sql, $maxBytes, ['app' => config('gsctool.slug'), 'purpose' => str($purpose)->slug('_')->limit(60, '')->value()]);
        } catch (Throwable $e) {
            if ($reserved > 0) {
                $this->credits->add($team, $reserved, 'bq_release', $user, 'bq:'.$log->id);
            }
            $log->update(['status' => 'failed', 'error' => $e->getMessage(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            throw $e;
        }

        $charged = $metered ? min($reserved, Credits::forBytes($result['bytes_billed'])) : 0;
        if ($reserved - $charged > 0) {
            $this->credits->add($team, $reserved - $charged, 'bq_release', $user, 'bq:'.$log->id);
        }

        $log->update([
            'bytes_billed' => $result['bytes_billed'],
            'credits_charged' => $charged,
            'job_id' => $result['job_id'],
            'status' => 'done',
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'result_json' => $cacheable ? json_encode($result['rows']) : null,
        ]);

        return new QueryResult($result['rows'], $charged, $result['bytes_billed'], false, $log->id);
    }

    protected function cached(Team $team, string $hash): ?BqQuery
    {
        return BqQuery::where('team_id', $team->id)
            ->where('sql_hash', $hash)
            ->where('status', 'done')
            ->where('from_cache', false)
            ->whereNotNull('result_json')
            ->where('created_at', '>=', now()->subHours((int) config('gsctool.credits.cache_hours')))
            ->latest('id')
            ->first();
    }
}
