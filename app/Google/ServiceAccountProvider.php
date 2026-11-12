<?php

namespace App\Google;

use App\Google\Contracts\CredentialProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google service account (JWT bearer flow), no Google SDK needed. The key file comes from
 * GOOGLE_SERVICE_ACCOUNT_JSON; the same account reads Search Console and GA4 and runs BigQuery.
 */
class ServiceAccountProvider implements CredentialProvider
{
    protected ?array $credentials = null;

    public function __construct(protected ?string $path = null)
    {
        $this->path ??= static::keyPath();
    }

    /** A relative path (secrets/google.json) is read from the app folder, next to .env. */
    public static function keyPath(): ?string
    {
        $path = trim((string) config('gsctool.google.credentials'), " \t\n\r\0\x0B\"'");
        if ($path === '') {
            return null;
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    public function isConfigured(): bool
    {
        return $this->setupProblem() === null;
    }

    public function setupProblem(): ?string
    {
        try {
            $this->credentials();

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public function identity(): ?string
    {
        return $this->isConfigured() ? $this->credentials()['client_email'] : null;
    }

    public function projectId(): ?string
    {
        return $this->isConfigured() ? ($this->credentials()['project_id'] ?? null) : null;
    }

    protected function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }
        if (! $this->path) {
            throw new RuntimeException('No service account key set (GOOGLE_SERVICE_ACCOUNT_JSON).');
        }
        if (! is_readable($this->path)) {
            throw new RuntimeException("Service account key file not readable: {$this->path}");
        }

        $json = json_decode((string) file_get_contents($this->path), true);
        if (empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('Service account key file is missing client_email or private_key.');
        }

        return $this->credentials = $json;
    }

    public function accessToken(array $scopes): string
    {
        $creds = $this->credentials();
        sort($scopes);
        $scope = implode(' ', $scopes);

        return Cache::remember('google:token:'.md5($creds['client_email'].$scope), now()->addMinutes(50), function () use ($creds, $scope) {
            $now = time();
            $b64 = fn (string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
            $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode([
                'iss' => $creds['client_email'],
                'scope' => $scope,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            if (! openssl_sign("$header.$claims", $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the Google token request.');
            }

            return Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "$header.$claims.".$b64($signature),
            ])->throw()->json('access_token');
        });
    }
}
