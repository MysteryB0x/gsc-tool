<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TeamOnboarding;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** First run: plans, the owner account (admin) and the owner's workspace on the unlimited plan. */
class Install extends Command
{
    protected $signature = 'app:install {--email=} {--name=} {--password=} {--workspace=}';

    protected $description = 'Create the plans, the owner (admin) account and its workspace';

    public function handle(TeamOnboarding $onboarding): int
    {
        $this->call('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);

        $email = $this->option('email') ?: text('Your email', required: true);
        $user = User::firstOrNew(['email' => $email]);
        $user->name = $this->option('name') ?: ($user->name ?: text('Your name', required: true));
        if (! $user->exists || $this->option('password')) {
            $user->password = $this->option('password') ?: password('Password (min. 12 characters)', required: true, validate: fn ($v) => strlen($v) < 12 ? 'At least 12 characters.' : null);
        }
        $user->is_admin = true;
        $user->email_verified_at ??= now();
        $user->save();

        if (! $user->teams()->exists()) {
            $team = $onboarding->createTeam($user, $this->option('workspace') ?: text('Workspace name', default: config('gsctool.name')));
            $team->forceFill(['uses_app_account' => true])->save();   // your own workspace reads with the app's account
            $this->info("Workspace \"{$team->name}\" created on the {$team->plan?->name} plan.");
        }

        $this->info('Done. Log in at '.url('/app').' (admin: '.url('/admin').').');

        return self::SUCCESS;
    }
}
