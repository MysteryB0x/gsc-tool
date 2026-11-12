<?php

namespace App\Google;

use App\Google\Contracts\CredentialProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A user's own Google login (OAuth 2, offline access). Built so properties can switch to it later;
 * off until GOOGLE_OAUTH_ENABLED=true and the consent screen is verified by Google.
 *
 * Still to do when switching it on: the consent redirect + callback route that stores the refresh token
 * (encrypted) on the property or user, and set properties.credential_provider = 'oauth'.
 */
class OAuthProvider implements CredentialProvider
{
    public function __construct(protected ?string $refreshToken = null, protected ?string $email = null) {}

    public static function enabled(): bool
    {
        return (bool) config('gsctool.google.oauth_enabled');
    }

    public function isConfigured(): bool
    {
        return $this->setupProblem() === null;
    }

    public function setupProblem(): ?string
    {
        return match (true) {
            ! static::enabled() => 'Google OAuth is switched off (GOOGLE_OAUTH_ENABLED=false).',
            blank(config('gsctool.google.oauth_client_id')) || blank(config('gsctool.google.oauth_client_secret')) => 'Google OAuth client id/secret missing.',
            blank($this->refreshToken) => 'No Google account connected yet.',
            default => null,
        };
    }

    public function identity(): ?string
    {
        return $this->email;
    }

    public function accessToken(array $scopes): string
    {
        if ($problem = $this->setupProblem()) {
            throw new RuntimeException($problem);
        }

        return Cache::remember('google:oauth:'.md5($this->refreshToken), now()->addMinutes(50), fn () => Http::asForm()->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => config('gsctool.google.oauth_client_id'),
                'client_secret' => config('gsctool.google.oauth_client_secret'),
            ])->throw()->json('access_token'));
    }
}
