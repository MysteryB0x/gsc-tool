<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Explorer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesGoogle;
use Tests\TestCase;

class ExplorerPageTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    public function test_running_a_query_charges_credits_and_shows_the_rows(): void
    {
        [$user, $team] = $this->workspace('starter', 5);
        $this->fakeBigQuery(1024 ** 3, 1024 ** 3, [['query' => 'bureaustoel', 'clicks' => 12, 'impressions' => 300, 'ctr' => 0.04, 'position' => 5.1]], [
            ['name' => 'query', 'type' => 'STRING'], ['name' => 'clicks', 'type' => 'INTEGER'], ['name' => 'impressions', 'type' => 'INTEGER'],
            ['name' => 'ctr', 'type' => 'FLOAT'], ['name' => 'position', 'type' => 'FLOAT'],
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        Filament::setTenant($team);

        Livewire::test(Explorer::class)
            ->call('estimateCost')
            ->assertSet('estimate', 'About 1 credit. You have 5.')
            ->call('runQuery')
            ->assertSee('bureaustoel');

        $this->assertSame(4, $team->fresh()->credit_balance);
    }
}
