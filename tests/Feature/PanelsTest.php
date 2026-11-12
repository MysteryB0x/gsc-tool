<?php

namespace Tests\Feature;

use App\Models\DailyTotal;
use App\Models\Reports\StrikingRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\FakesGoogle;
use Tests\TestCase;

class PanelsTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    public function test_every_app_page_renders_for_the_owner(): void
    {
        [$user, $team, $property] = $this->workspace('owner');
        DailyTotal::create(['property_id' => $property->id, 'date' => now()->subDays(5)->toDateString(), 'clicks' => 10, 'impressions' => 200, 'ctr' => 0.05, 'position' => 6.2]);
        StrikingRow::create(['property_id' => $property->id, 'query' => 'desk', 'url' => 'https://example.nl/desk', 'impressions' => 900, 'clicks' => 9, 'position' => 6, 'potential_clicks' => 90]);

        foreach (['', '/striking-distance', '/content-decay', '/cannibalisation', '/ctr-benchmark', '/explorer', '/credits', '/properties', '/properties/create', '/seo-actions', '/seo-actions/create'] as $path) {
            $this->actingAs($user)->get("/app/{$team->slug}{$path}")->assertOk();
        }
    }

    public function test_admin_pages_render_and_are_admin_only(): void
    {
        [$user] = $this->workspace('owner');

        // Admin needs two-factor login set up first (TWO_FACTOR_ADMIN=required by default).
        $this->actingAs($user)->get('/admin/users')->assertRedirect();
        $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        foreach (['/admin', '/admin/users', '/admin/teams', '/admin/plans', '/admin/bq-queries'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }

        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_sign_ups_are_off_until_switched_on(): void
    {
        $this->assertFalse(Route::has('filament.app.auth.register'));

        // A stranger with an account but no workspace can't get in while it's the owner's tool.
        $this->actingAs(User::factory()->create())->get('/app')->assertForbidden();
    }

    public function test_workspaces_cannot_see_each_others_properties(): void
    {
        [$user, $team] = $this->workspace('owner');
        [, $other] = $this->workspace('starter');

        $this->actingAs($user)->get("/app/{$other->slug}")->assertNotFound();
    }
}
