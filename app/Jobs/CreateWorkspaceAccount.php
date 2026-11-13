<?php

namespace App\Jobs;

use App\Google\WorkspaceAccounts;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateWorkspaceAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public function __construct(public Team $team) {}

    public function handle(WorkspaceAccounts $accounts): void
    {
        if (WorkspaceAccounts::appliesTo($this->team)) {
            $accounts->ensure($this->team);
        }
    }
}
