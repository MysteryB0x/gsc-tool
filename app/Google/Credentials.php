<?php

namespace App\Google;

use App\Google\Contracts\CredentialProvider;
use App\Models\Property;
use App\Models\Team;
use RuntimeException;

/**
 * Which Google identity reads a property's Search Console and GA4 data:
 * - the property's own OAuth login (later, when switched on);
 * - the workspace's own service account (GOOGLE_WORKSPACE_ACCOUNTS=true), so a grant only reaches that workspace;
 * - otherwise the app's service account (the owner's tool).
 * BigQuery always runs as the app's account: the data lives in the app's project.
 */
class Credentials
{
    public function __construct(protected ServiceAccountProvider $serviceAccount) {}

    public function app(): ServiceAccountProvider
    {
        return $this->serviceAccount;
    }

    public function forProperty(Property $property): CredentialProvider
    {
        if ($property->credential_provider === 'oauth') {
            return new OAuthProvider;   // the stored refresh token is wired in when OAuth is switched on
        }

        return $this->forTeam($property->team);
    }

    public function forTeam(Team $team): CredentialProvider
    {
        if (! WorkspaceAccounts::appliesTo($team)) {
            return $this->serviceAccount;
        }

        if (! $team->google_account_email || $team->google_account_status !== 'ready') {
            throw new RuntimeException('This workspace\'s Google account is not set up yet.');
        }

        return new ImpersonatedProvider($this->serviceAccount, $team->google_account_email);
    }

    /** The email customers add in Search Console and GA4, or null while it's being created. */
    public function identityFor(Team $team): ?string
    {
        return WorkspaceAccounts::appliesTo($team)
            ? ($team->google_account_status === 'ready' ? $team->google_account_email : null)
            : $this->serviceAccount->identity();
    }
}
