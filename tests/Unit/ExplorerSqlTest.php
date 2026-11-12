<?php

namespace Tests\Unit;

use App\Models\Property;
use App\Reports\Explorer;
use App\Reports\Sql;
use Tests\TestCase;

class ExplorerSqlTest extends TestCase
{
    private function property(string $source = 'api'): Property
    {
        config(['gsctool.bigquery.project' => 'proj']);

        return (new Property(['source' => $source, 'bq_dataset' => 'searchconsole']))->forceFill(['id' => 7]);
    }

    public function test_filter_values_cannot_break_out_of_the_string_literal(): void
    {
        $sql = Explorer::sql($this->property(), ['query'], '2026-10-01', '2026-10-28', ['query_contains' => "x') OR 1=1 --"]);

        $this->assertStringContainsString("LOWER('x\\') OR 1=1 --')", $sql);
        $this->assertSame("'a\\\\b\\'c'", Sql::str("a\\b'c"));
    }

    public function test_unknown_dimensions_and_sorts_are_ignored(): void
    {
        $sql = Explorer::sql($this->property(), ['query', 'DROP TABLE x'], '2026-10-01', '2026-10-28', [], 'clicks; DELETE');

        $this->assertStringNotContainsString('DROP', $sql);
        $this->assertStringContainsString('ORDER BY clicks DESC', $sql);
    }

    public function test_it_always_filters_on_the_partition_and_picks_the_right_table(): void
    {
        $api = Explorer::sql($this->property('api'), ['url'], '2026-10-01', '2026-10-28');
        $bulk = Explorer::sql($this->property('bulk'), ['url'], '2026-10-01', '2026-10-28');

        $this->assertStringContainsString("data_date BETWEEN DATE '2026-10-01' AND DATE '2026-10-28'", $api);
        $this->assertStringContainsString('`proj.gsc_api.url_impression_p7`', $api);
        $this->assertStringContainsString('`proj.searchconsole.searchdata_url_impression`', $bulk);
    }

    public function test_dates_must_be_dates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Explorer::sql($this->property(), ['query'], "2026-10-01' OR '1", '2026-10-28');
    }
}
