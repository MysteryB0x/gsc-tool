<?php

namespace App\Console\Commands;

use App\Google\BigQuery;
use App\Google\Credentials;
use App\Google\SearchConsole;
use App\Google\WorkspaceAccounts;
use Illuminate\Console\Command;
use Throwable;

/** What's missing before the tool works: key file, BigQuery project, API access. */
class Check extends Command
{
    protected $signature = 'app:check';

    protected $description = 'Check the Google service account, Search Console and BigQuery setup';

    public function handle(Credentials $credentials): int
    {
        $ok = true;
        $sa = $credentials->app();

        if ($problem = $sa->setupProblem()) {
            $this->error("Service account: {$problem}");

            return self::FAILURE;
        }
        $this->info('Service account: '.$sa->identity());

        try {
            $sites = (new SearchConsole($sa))->sites();
            $this->info('Search Console: '.count($sites).' properties readable'.($sites ? ' ('.implode(', ', array_keys($sites)).')' : ''));
        } catch (Throwable $e) {
            $ok = false;
            $this->error('Search Console: '.$e->getMessage());
        }

        if (blank(config('gsctool.bigquery.project'))) {
            $ok = false;
            $this->error('BigQuery: BIGQUERY_PROJECT is not set.');
        } else {
            try {
                $bytes = app(BigQuery::class)->dryRun('SELECT 1');
                $this->info('BigQuery: project '.config('gsctool.bigquery.project').' reachable (dry run OK, '.$bytes.' bytes).');
            } catch (Throwable $e) {
                $ok = false;
                $this->error('BigQuery: '.$e->getMessage());
            }
        }

        if (config('gsctool.registration') && ! WorkspaceAccounts::enabled()) {
            $ok = false;
            $this->error('Sign-ups are on but GOOGLE_WORKSPACE_ACCOUNTS is off: every workspace would share one Google account, so one customer could read a site another customer granted.');
        }

        foreach (['dom', 'mbstring', 'openssl', 'curl', 'pdo_mysql', 'intl'] as $ext) {
            if (! extension_loaded($ext)) {
                $this->warn("PHP extension missing on the command line: {$ext}");
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
