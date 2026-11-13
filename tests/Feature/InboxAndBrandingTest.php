<?php

namespace Tests\Feature;

use App\Models\Reports\CtrRow;
use App\Models\Reports\DecayRow;
use App\Models\Reports\StrikingRow;
use App\Models\SeoAction;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesGoogle;
use Tests\TestCase;

class InboxAndBrandingTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    public function test_the_inbox_ranks_every_report_together_and_hides_dismissed_items(): void
    {
        [$user, $team, $property] = $this->workspace('owner');
        StrikingRow::create(['property_id' => $property->id, 'query' => 'desk', 'url' => 'https://example.nl/desk', 'impressions' => 900, 'clicks' => 9, 'position' => 6, 'potential_clicks' => 90]);
        CtrRow::create(['property_id' => $property->id, 'query' => 'chair', 'url' => 'https://example.nl/chair', 'impressions' => 5000, 'clicks' => 100, 'position' => 2, 'ctr' => 0.02, 'expected_ctr' => 0.158, 'missed_clicks' => 690]);
        DecayRow::create(['property_id' => $property->id, 'url' => 'https://example.nl/blog/old', 'monthly_clicks' => ['2026-09' => 300, '2026-10' => 100], 'peak_clicks' => 300, 'last_clicks' => 100, 'change' => -0.667]);
        SeoAction::create(['property_id' => $property->id, 'title' => 'x', 'source' => 'striking', 'url' => 'https://example.nl/desk', 'query' => 'desk', 'status' => 'dismissed']);

        $this->actingAs($user)->get("/app/{$team->slug}/inbox")
            ->assertOk()
            ->assertSeeInOrder(['chair', '/blog/old'])
            ->assertDontSee('https://example.nl/desk');
    }

    public function test_branding_settings_change_the_panels(): void
    {
        [$user, $team] = $this->workspace('owner');
        $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->actingAs($user)->get('/admin/branding')->assertOk();

        Branding::save(['name' => 'Search Desk', 'primary_color' => '#0066CC', 'sidebar_style' => 'dark', 'sidebar_color' => '#1A2B3C', 'home' => 'inbox']);

        $this->actingAs($user)->get("/app/{$team->slug}")->assertRedirect("/app/{$team->slug}/inbox");
        $this->actingAs($user)->get("/app/{$team->slug}/inbox")->assertOk()->assertSee('Search Desk')->assertSee('.fi-sidebar,.fi-sidebar-header{background-color:#1A2B3C', false);
    }

    public function test_bad_colours_fall_back_and_contrast_is_measured(): void
    {
        Branding::save(['primary_color' => 'red; } body { display:none']);

        $this->assertSame('#BA5A00', Branding::hex('primary_color', '#BA5A00'));
        $this->assertEqualsWithDelta(21.0, Branding::contrast('#000000', '#FFFFFF'), 0.01);
    }
}
