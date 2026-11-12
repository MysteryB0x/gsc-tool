<?php

namespace Tests\Feature;

use App\Models\Reports\CannibalisationRow;
use App\Models\Reports\CtrRow;
use App\Models\Reports\StrikingRow;
use App\Reports\ReportBuilder;
use App\Search\QueryResult;
use App\Search\QueryRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\FakesGoogle;
use Tests\TestCase;

class ReportBuilderTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    public function test_opportunities_are_split_into_striking_distance_and_ctr_and_never_charged(): void
    {
        [, , $property] = $this->workspace('starter', 0);
        config(['gsctool.bigquery.project' => 'p']);

        $runner = Mockery::mock(QueryRunner::class);
        $runner->shouldReceive('run')->withArgs(fn ($team, $sql, $purpose) => $purpose === 'report:cannibalisation')->andReturn(new QueryResult([
            ['query' => 'office chair', 'url' => 'https://example.nl/a', 'impressions' => 600, 'clicks' => 20, 'position' => 4.2, 'share' => 0.6, 'q_impressions' => 1000, 'q_clicks' => 30],
            ['query' => 'office chair', 'url' => 'https://example.nl/b', 'impressions' => 400, 'clicks' => 10, 'position' => 7.9, 'share' => 0.4, 'q_impressions' => 1000, 'q_clicks' => 30],
        ], 0, 0, false, 1));
        $runner->shouldReceive('run')->withArgs(function ($team, $sql, $purpose, $user = null, $p = null, $charge = true) {
            return $purpose === 'report:query_url_window' && $charge === false;
        })->andReturn(new QueryResult([
            ['query' => 'desk', 'url' => 'https://example.nl/desk', 'impressions' => 1000, 'clicks' => 10, 'position' => 6.0],   // striking + poor CTR
            ['query' => 'brand', 'url' => 'https://example.nl/', 'impressions' => 500, 'clicks' => 300, 'position' => 1.1],    // fine
        ], 0, 0, false, 2));
        $this->app->instance(QueryRunner::class, $runner);

        app(ReportBuilder::class)->cannibalisationAndOpportunities($property, CarbonImmutable::parse('2026-11-08'));

        $this->assertSame(2, CannibalisationRow::first()->url_count);
        $this->assertSame(['desk'], StrikingRow::pluck('query')->all());
        $this->assertSame((int) round(1000 * 0.110 - 10), StrikingRow::first()->potential_clicks);
        $this->assertSame(['desk'], CtrRow::pluck('query')->all());
        $this->assertSame((int) round(1000 * 0.049 - 10), CtrRow::first()->missed_clicks);
    }
}
