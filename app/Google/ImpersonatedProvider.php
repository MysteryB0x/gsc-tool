<?php

namespace App\Google;

use App\Google\Contracts\CredentialProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A workspace's own service account, used by borrowing its identity: the app's account asks Google for a
 * short-lived token as the workspace account (iamcredentials generateAccessToken). No key files per workspace.
 */
class ImpersonatedProvider implements CredentialProvider
{
    public const CLOUD_PLATFORM = 'https://www.googleapis.com/auth/cloud-platform';

    public function __construct(protected ServiceAccountProvider $app, protected string $email) {}

    public function isConfigured(): bool
    {
        return $this->setupProblem() === null;
    }

    public function setupProblem(): ?string
    {
        return $this->app->setupProblem();
    }

    public function identity(): ?string
    {
        return $this->email;
    }

    public function accessToken(array $scopes): string
    {
        sort($scopes);

        return Cache::remember('google:impersonated:'.md5($this->email.implode(' ', $scopes)), now()->addMinutes(50), function () use ($scopes) {
            $response = Http::withToken($this->app->accessToken([self::CLOUD_PLATFORM]))->timeout(20)
                ->post('https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/'.rawurlencode($this->email).':generateAccessToken', [
                    'scope' => $scopes,
                    'lifetime' => '3600s',
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Google (workspace account): '.($response->json('error.message') ?? $response->status()));
            }

            return $response->json('accessToken');
        });
    }
}
