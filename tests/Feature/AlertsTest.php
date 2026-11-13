<?php

namespace Tests\Feature;

use App\Alerts\ChangeDetector;
use App\Alerts\DigestSender;
use App\Filament\App\Pages\Alerts;
use App\Mail\ChangeDigest;
use App\Models\AlertDigest;
use App\Models\AlertPreference;
use App\Models\Analytics\Ga4AiReferral;
use App\Models\Analytics\Ga4Daily;
use App\Models\DailyTotal;
use App\Models\Reports\PageRow;
use App\Models\SeoAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\FakesGoogle;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    /** 14 days of clicks: $before per day for the first 7, $now per day for the last 7. */
    private function clicks($property, int $before, int $now): void
    {
        foreach (range(13, 0) as $i) {
            $c = $i >= 7 ? $before : $now;
            DailyTotal::create(['property_id' => $property->id, 'date' => today()->subDays(3 + $i)->toDateString(), 'clicks' => $c, 'impressions' => $c * 40, 'ctr' => 0.025, 'position' => 8]);
        }
    }

    public function test_a_big_weekly_drop_and_moving_pages_are_found(): void
    {
        [, , $property] = $this->workspace('owner');
        $this->clicks($property, 100, 60);
        PageRow::create(['property_id' => $property->id, 'url' => 'https://example.nl/a', 'path' => '/a', 'clicks_7' => 40, 'clicks_prev_7' => 120, 'clicks_28' => 300, 'clicks_prev_28' => 400]);

        $titles = array_map(fn ($c) => $c->title, (new ChangeDetector(20, 50))->forProperty($property, 'weekly'));

        $this->assertContains('Clicks (search) −40%', $titles);
        $this->assertContains('/a lost 80 clicks', $titles);
    }

    public function test_small_changes_and_small_sites_stay_quiet(): void
    {
        [, , $property] = $this->workspace('owner');
        $this->clicks($property, 100, 92);                       // −8%: under the 20% threshold
        $this->assertSame([], (new ChangeDetector(20, 50))->forProperty($property, 'weekly'));

        DailyTotal::query()->delete();
        $this->clicks($property, 3, 1);                          // −67% but 21 clicks a week: under the baseline
        $this->assertSame([], (new ChangeDetector(20, 50))->forProperty($property, 'weekly'));
    }

    public function test_daily_compares_the_newest_day_with_an_average_day_and_spots_ai_visits(): void
    {
        [, , $property] = $this->workspace('owner');
        $property->update(['ga4_property_id' => '123']);
        $this->clicks($property, 100, 100);
        DailyTotal::where('date', today()->subDays(3)->toDateString())->update(['clicks' => 30]);
        Ga4Daily::create(['property_id' => $property->id, 'date' => today()->subDays(3)->toDateString(), 'sessions' => 10]);
        Ga4AiReferral::create(['property_id' => $property->id, 'date' => today()->subDays(3)->toDateString(), 'source' => 'chatgpt.com', 'sessions' => 12]);

        $titles = array_map(fn ($c) => $c->title, (new ChangeDetector(20, 50))->forProperty($property, 'daily'));

        $this->assertContains('Clicks (search) −70%', $titles);
        $this->assertContains('Visits from AI assistants up', $titles);
    }

    public function test_actions_done_four_weeks_ago_report_their_result(): void
    {
        [, , $property] = $this->workspace('owner');
        SeoAction::create(['property_id' => $property->id, 'title' => 'Title rewrite', 'url' => 'https://example.nl/a', 'status' => 'done', 'done_on' => today()->subDays(30)]);
        PageRow::create(['property_id' => $property->id, 'url' => 'https://example.nl/a', 'path' => '/a', 'clicks_7' => 30, 'clicks_prev_7' => 30, 'clicks_28' => 130, 'clicks_prev_28' => 100]);

        $changes = (new ChangeDetector(20, 50))->forProperty($property, 'weekly');

        $this->assertSame('Result of "Title rewrite"', $changes[0]->title);
        $this->assertStringContainsString('+30%', $changes[0]->detail);
    }

    public function test_digests_go_out_once_by_email_and_in_app_and_only_when_something_changed(): void
    {
        Mail::fake();
        [$user, $team, $property] = $this->workspace('owner');
        AlertPreference::create(['team_id' => $team->id, 'user_id' => $user->id, 'daily' => false, 'weekly' => true]);

        $this->assertSame(0, app(DigestSender::class)->send('weekly'));   // no data, nothing to say
        Mail::assertNothingSent();

        $this->clicks($property, 100, 50);
        $this->assertSame(1, app(DigestSender::class)->send('weekly'));
        $this->assertSame(0, app(DigestSender::class)->send('weekly'));   // not twice for the same period

        Mail::assertSent(ChangeDigest::class, fn ($m) => $m->hasTo($user->email) && str_contains($m->heading, 'Weekly changes'));
        $this->assertSame(1, AlertDigest::count());
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, app(DigestSender::class)->send('daily'));    // daily is off
    }

    public function test_the_alerts_page_saves_and_previews(): void
    {
        [$user, $team, $property] = $this->workspace('owner');
        $this->clicks($property, 100, 50);

        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        Filament::setTenant($team);
        Livewire::test(Alerts::class)
            ->fillForm(['daily' => true, 'min_change' => 30])
            ->call('save')
            ->call('preview')
            ->assertSee('Clicks (search) −50%');

        $this->assertTrue(AlertPreference::first()->daily);
        $this->assertSame(30, AlertPreference::first()->min_change);
    }
}
