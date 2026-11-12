<?php

namespace Tests\Feature;

use App\Credits\InsufficientCredits;
use App\Models\BqQuery;
use App\Search\QueryRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGoogle;
use Tests\TestCase;

class QueryRunnerTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private const GIB = 1024 ** 3;

    public function test_it_reserves_the_estimate_caps_the_query_and_refunds_the_difference(): void
    {
        [$user, $team, $property] = $this->workspace('starter', 10);
        $this->fakeBigQuery(estimatedBytes: (int) (2.5 * self::GIB), billedBytes: (int) (1.2 * self::GIB), rows: [['q' => 'shoes']], schema: [['name' => 'q', 'type' => 'STRING']]);

        $result = app(QueryRunner::class)->run($team, 'SELECT 1', 'explorer', $user, $property);

        // Estimate 2.5 GiB → 3 credits reserved; billed 1.2 GiB → 2 credits charged; 1 released.
        $this->assertSame(2, $result->credits);
        $this->assertSame([['q' => 'shoes']], $result->rows);
        $this->assertSame(8, $team->fresh()->credit_balance);
        $this->assertSame([-3, 1], $team->creditTransactions()->orderBy('id')->pluck('amount')->all());

        // BigQuery is told never to bill more than the 3 reserved credits.
        Http::assertSent(fn ($r) => ($r['maximumBytesBilled'] ?? null) === (string) (3 * self::GIB));
    }

    public function test_a_repeat_within_the_cache_window_is_free(): void
    {
        [$user, $team, $property] = $this->workspace('starter', 10);
        $this->fakeBigQuery(self::GIB, self::GIB, [['n' => 1]], [['name' => 'n', 'type' => 'INTEGER']]);

        app(QueryRunner::class)->run($team, 'SELECT n', 'explorer', $user, $property);
        $again = app(QueryRunner::class)->run($team, 'SELECT n', 'explorer', $user, $property);

        $this->assertTrue($again->fromCache);
        $this->assertSame(0, $again->credits);
        $this->assertSame([['n' => 1]], $again->rows);
        $this->assertSame(9, $team->fresh()->credit_balance);
    }

    public function test_it_refuses_without_enough_credits_and_charges_nothing(): void
    {
        [$user, $team, $property] = $this->workspace('starter', 1);
        $this->fakeBigQuery(5 * self::GIB, 5 * self::GIB);

        $this->expectException(InsufficientCredits::class);
        try {
            app(QueryRunner::class)->run($team, 'SELECT big', 'explorer', $user, $property);
        } finally {
            $this->assertSame(1, $team->fresh()->credit_balance);
            $this->assertSame(0, BqQuery::where('status', 'done')->count());
        }
    }

    public function test_the_owner_plan_is_logged_but_never_charged(): void
    {
        [$user, $team, $property] = $this->workspace('owner', 0);
        $this->fakeBigQuery(4 * self::GIB, 4 * self::GIB);

        $result = app(QueryRunner::class)->run($team, 'SELECT owner', 'explorer', $user, $property);

        $this->assertSame(0, $result->credits);
        $this->assertSame(0, $team->fresh()->credit_balance);
        $this->assertSame(4 * self::GIB, BqQuery::first()->bytes_billed);
    }

    public function test_queries_over_the_hard_limit_are_refused(): void
    {
        [$user, $team, $property] = $this->workspace('owner');
        config(['gsctool.bigquery.max_bytes_per_query' => self::GIB]);
        $this->fakeBigQuery(2 * self::GIB, 0);

        $this->expectExceptionMessage('over the per-query limit');
        app(QueryRunner::class)->run($team, 'SELECT huge', 'explorer', $user, $property);
    }
}
