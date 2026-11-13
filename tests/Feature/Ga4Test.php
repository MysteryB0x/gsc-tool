<?php

namespace Tests\Feature;

use App\Analytics\Ga4Summary;
use App\Analytics\Ga4Sync;
use App\Models\Analytics\Ga4AiReferral;
use App\Models\Analytics\Ga4LandingPage;
use App\Models\Reports\PageRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGoogle;
use Tests\TestCase;

class Ga4Test extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private function report(array $dims, array $mets, array $rows): array
    {
        return [
            'dimensionHeaders' => array_map(fn ($d) => ['name' => $d], $dims),
            'metricHeaders' => array_map(fn ($m) => ['name' => $m, 'type' => 'TYPE_INTEGER'], $mets),
            'rows' => array_map(fn ($r) => [
                'dimensionValues' => array_map(fn ($v) => ['value' => $v], array_slice($r, 0, count($dims))),
                'metricValues' => array_map(fn ($v) => ['value' => (string) $v], array_slice($r, count($dims))),
            ], $rows),
            'rowCount' => count($rows),
        ];
    }

    public function test_it_syncs_totals_ai_referrals_and_landing_pages(): void
    {
        $this->fakeServiceAccount();
        [$user, $team, $property] = $this->workspace('owner');
        $property->update(['ga4_property_id' => '123456789']);
        $day = now()->subDay()->format('Ymd');

        Http::fake(function ($request) use ($day) {
            if (str_contains($request->url(), 'oauth2')) {
                return Http::response(['access_token' => 't']);
            }
            $dims = array_column($request['dimensions'] ?? [], 'name');
            $filter = $request['dimensionFilter']['filter']['fieldName'] ?? null;

            return Http::response(match (true) {
                $dims === ['landingPage'] => $this->report(['landingPage', 'dateRange'], ['sessions', 'engagedSessions', 'keyEvents', 'totalRevenue'], [
                    ['/bureaustoelen', 'now', 400, 300, 12, 1500], ['/bureaustoelen', 'prev', 320, 0, 0, 0],
                ]),
                $dims === ['date', 'sessionSource'] => $this->report($dims, ['sessions', 'keyEvents'], [[$day, 'chatgpt.com', 14, 2], [$day, 'perplexity.ai', 3, 0]]),
                $filter === 'sessionDefaultChannelGroup' => $this->report(['date'], ['sessions'], [[$day, 250]]),
                default => $this->report(['date'], ['sessions', 'engagedSessions', 'activeUsers', 'keyEvents', 'totalRevenue'], [[$day, 600, 420, 500, 18, 2100.5]]),
            });
        });

        app(Ga4Sync::class)->sync($property);

        $totals = Ga4Summary::compare($property->fresh(), 7)['now'];
        $this->assertSame(600, $totals['sessions']);
        $this->assertSame(250, $totals['organic_sessions']);
        $this->assertSame(17, $totals['ai_sessions']);
        $this->assertSame(2, Ga4AiReferral::count());
        $page = Ga4LandingPage::first();
        $this->assertSame([400, 320, 12], [$page->sessions, $page->sessions_prev, $page->key_events]);
        $this->assertTrue($property->fresh()->ga4_has_access);

        // The Analytics page shows it, with the page's Search Console clicks next to it.
        PageRow::create(['property_id' => $property->id, 'url' => 'https://example.nl/bureaustoelen', 'path' => '/bureaustoelen', 'clicks_28' => 231]);
        $this->actingAs($user)->get("/app/{$team->slug}/analytics")->assertOk()->assertSee('/bureaustoelen')->assertSee('231')->assertSee('chatgpt.com');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'properties/123456789:runReport'));
    }

    public function test_a_ga4_error_is_kept_on_the_property(): void
    {
        $this->fakeServiceAccount();
        [, , $property] = $this->workspace('owner');
        $property->update(['ga4_property_id' => '999']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 't']),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'User does not have sufficient permissions']], 403),
        ]);

        try {
            app(Ga4Sync::class)->sync($property);
        } catch (\RuntimeException) {
        }

        $this->assertStringContainsString('sufficient permissions', $property->fresh()->ga4_error);
    }
}
