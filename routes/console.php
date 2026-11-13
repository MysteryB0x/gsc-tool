<?php

use App\Credits\Credits;
use App\Models\BqQuery;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Shared hosting: one cron line runs `schedule:run` every minute; this starts a short worker that
// handles the queued sync and report jobs, then exits (no long-running process needed).
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3 --timeout=600')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->runInBackground();

// Nightly: the last few days from Search Console into BigQuery, then the reports.
Schedule::command('gsc:sync')->dailyAt('04:30')->timezone(config('gsctool.timezone'))->withoutOverlapping();

// Change alerts, after the nightly sync and reports (only sent when something big changed).
Schedule::command('alerts:send daily')->dailyAt('07:30')->timezone(config('gsctool.timezone'));
Schedule::command('alerts:send weekly')->weeklyOn(1, '07:45')->timezone(config('gsctool.timezone'));

// Plan credits, once per period.
Artisan::command('credits:grant', function (Credits $credits) {
    $n = 0;
    Team::with('plan')->whereIn('subscription_status', ['active', 'trialing'])->each(function (Team $team) use ($credits, &$n) {
        $credits->grantMonthly($team) && $n++;
    });
    $this->info("Granted plan credits to {$n} workspaces.");
})->purpose('Grant each active workspace its monthly plan credits');
Schedule::command('credits:grant')->dailyAt('01:00')->timezone(config('gsctool.timezone'));

// Cached query results are only needed for the cache window; the log line itself stays.
Artisan::command('bq:prune', function () {
    $n = BqQuery::whereNotNull('result_json')->where('created_at', '<', now()->subHours((int) config('gsctool.credits.cache_hours') + 1))->update(['result_json' => null]);
    $this->info("Cleared {$n} cached results.");
})->purpose('Drop expired cached query results');
Schedule::command('bq:prune')->hourly();
