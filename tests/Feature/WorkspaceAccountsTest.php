<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Properties\Pages\CreateProperty;
use App\Google\Credentials;
use App\Google\SearchConsole;
use App\Google\WorkspaceAccounts;
use App\Models\Property;
use App\Search\SiteKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesGoogle;
use Tests\TestCase;

class WorkspaceAccountsTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private function fakeIam(): void
    {
        $this->fakeServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'app-token']),
            'iam.googleapis.com/*' => Http::response(['email' => 'ws-1-abcdef@test-project.iam.gserviceaccount.com']),
            'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'workspace-token']),
            'www.googleapis.com/webmasters/*' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:example.nl', 'permissionLevel' => 'siteRestrictedUser']]]),
        ]);
    }

    public function test_a_workspace_gets_its_own_account_and_google_is_read_as_that_account(): void
    {
        config(['gsctool.google.workspace_accounts' => true]);
        $this->fakeIam();
        [, $team] = $this->workspace('starter');

        $email = app(WorkspaceAccounts::class)->ensure($team);
        $this->assertSame('ws-1-abcdef@test-project.iam.gserviceaccount.com', $email);
        $this->assertSame($email, app(Credentials::class)->identityFor($team->fresh()));

        (new SearchConsole(app(Credentials::class)->forTeam($team->fresh())))->sites();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'iam.googleapis.com/v1/projects/test-project/serviceAccounts') && str_starts_with($r['accountId'], 'ws-'));
        Http::assertSent(fn ($r) => str_contains($r->url(), rawurlencode($email).':generateAccessToken'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'webmasters/v3/sites') && $r->header('Authorization')[0] === 'Bearer workspace-token');
    }

    public function test_without_its_account_a_workspace_cannot_read_google_data_through_the_shared_one(): void
    {
        config(['gsctool.google.workspace_accounts' => true]);
        $this->fakeServiceAccount();
        [, $team] = $this->workspace('starter');

        $this->expectExceptionMessage('not set up yet');
        app(Credentials::class)->forTeam($team);
    }

    public function test_the_owners_workspace_and_the_switched_off_mode_use_the_app_account(): void
    {
        $this->fakeServiceAccount();
        [, $team] = $this->workspace('owner');
        $this->assertSame('gsc-tool@test-project.iam.gserviceaccount.com', app(Credentials::class)->identityFor($team));

        config(['gsctool.google.workspace_accounts' => true]);
        $team->forceFill(['uses_app_account' => true])->save();
        $this->assertSame('gsc-tool@test-project.iam.gserviceaccount.com', app(Credentials::class)->forTeam($team)->identity());
    }

    public function test_a_site_belongs_to_one_workspace_unless_admin_allows_sharing(): void
    {
        $this->fakeIam();
        [, $teamA] = $this->workspace('starter');            // already has sc-domain:example.nl
        [$userB, $teamB] = $this->workspace('starter');
        Property::where('team_id', $teamB->id)->delete();

        $this->assertSame('example.nl', SiteKey::of('https://www.example.nl/shop/'));
        $this->assertSame($teamA->id, SiteKey::takenBy('https://www.example.nl/', $teamB)?->id);

        $this->actingAs($userB);
        Filament::setCurrentPanel('app');
        Filament::setTenant($teamB);
        Livewire::test(CreateProperty::class)
            ->fillForm(['name' => 'Mine', 'site_url' => 'https://www.example.nl/', 'source' => 'api'])
            ->call('create')
            ->assertHasFormErrors(['site_url']);

        $teamB->forceFill(['may_share_properties' => true])->save();
        $this->assertNull(SiteKey::takenBy('https://www.example.nl/', $teamB->fresh()));
    }
}
