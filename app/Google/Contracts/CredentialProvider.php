<?php

namespace App\Google\Contracts;

/**
 * Where Google access tokens come from. Service accounts today; OAuth (a user's own Google login)
 * plugs in behind the same interface once it's switched on (GOOGLE_OAUTH_ENABLED).
 */
interface CredentialProvider
{
    /** @param  list<string>  $scopes */
    public function accessToken(array $scopes): string;

    public function isConfigured(): bool;

    /** Why it can't be used right now (for admins), or null. */
    public function setupProblem(): ?string;

    /** The identity customers grant access to, e.g. the service account's email address. */
    public function identity(): ?string;
}
