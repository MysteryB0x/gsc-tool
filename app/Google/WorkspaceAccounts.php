<?php

namespace App\Google;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Creates each workspace's own Google service account in the app's Cloud project.
 * Off until GOOGLE_WORKSPACE_ACCOUNTS=true (needs the IAM roles in docs/setup.md).
 */
class WorkspaceAccounts
{
    public function __construct(protected ServiceAccountProvider $app) {}

    public static function enabled(): bool
    {
        return (bool) config('gsctool.google.workspace_accounts');
    }

    /** Does this workspace read Google data with its own account (true) or the app's (false)? */
    public static function appliesTo(Team $team): bool
    {
        return static::enabled() && ! $team->uses_app_account;
    }

    public function project(): string
    {
        return config('gsctool.bigquery.project') ?: ($this->app->projectId() ?? throw new RuntimeException('No Google Cloud project set (BIGQUERY_PROJECT).'));
    }

    /** Create the account when it's missing or failed before. Returns its email. */
    public function ensure(Team $team): string
    {
        if ($team->google_account_email && $team->google_account_status === 'ready') {
            return $team->google_account_email;
        }

        $team->forceFill(['google_account_status' => 'pending', 'google_account_error' => null])->save();

        try {
            // 6–30 characters: a letter first, then lowercase letters, digits and hyphens.
            $accountId = 'ws-'.$team->id.'-'.Str::lower(Str::random(6));
            $response = Http::withToken($this->app->accessToken([ImpersonatedProvider::CLOUD_PLATFORM]))->timeout(30)
                ->post("https://iam.googleapis.com/v1/projects/{$this->project()}/serviceAccounts", [
                    'accountId' => $accountId,
                    'serviceAccount' => [
                        'displayName' => Str::limit(config('gsctool.name').': '.$team->name, 95, ''),
                        'description' => 'Workspace '.$team->id.' ('.$team->slug.'). Created by '.config('gsctool.name').'.',
                    ],
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Google IAM: '.($response->json('error.message') ?? $response->status()));
            }

            $team->forceFill(['google_account_email' => $response->json('email'), 'google_account_status' => 'ready'])->save();

            return $team->google_account_email;
        } catch (Throwable $e) {
            $team->forceFill(['google_account_status' => 'failed', 'google_account_error' => $e->getMessage()])->save();
            throw $e;
        }
    }
}
