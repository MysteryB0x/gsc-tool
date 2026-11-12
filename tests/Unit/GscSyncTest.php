<?php

namespace Tests\Unit;

use App\Models\Property;
use App\Search\GscSync;
use Tests\TestCase;

class GscSyncTest extends TestCase
{
    public function test_api_rows_become_bulk_export_shaped_json_lines(): void
    {
        $property = new Property(['site_url' => 'sc-domain:example.nl']);
        $ndjson = app(GscSync::class)->toNdjson($property, '2026-11-01', [
            ['query' => 'bureau', 'page' => 'https://example.nl/b', 'country' => 'nld', 'device' => 'MOBILE', 'clicks' => 3, 'impressions' => 40, 'position' => 2.5],
        ]);

        $row = json_decode($ndjson, true);
        $this->assertSame('2026-11-01', $row['data_date']);
        $this->assertSame('WEB', $row['search_type']);
        // Average position 2.5 over 40 impressions → 0-based sum 60, so SUM(sum_position)/SUM(impressions)+1 = 2.5 again.
        $this->assertEquals(60, $row['sum_position']);
    }
}
