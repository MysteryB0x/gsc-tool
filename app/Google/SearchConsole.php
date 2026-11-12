<?php

namespace App\Google;

use App\Google\Contracts\CredentialProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Search Console API: the properties we can read and Search Analytics rows. */
class SearchConsole
{
    public function __construct(protected CredentialProvider $credentials) {}

    protected function http()
    {
        return Http::withToken($this->credentials->accessToken([Scopes::SEARCH_CONSOLE]))->timeout(60)->retry(2, 1000, throw: false);
    }

    /** Properties the account can read: siteUrl => permissionLevel. */
    public function sites(): array
    {
        $entries = $this->http()->get('https://www.googleapis.com/webmasters/v3/sites')->throw()->json('siteEntry') ?? [];

        return collect($entries)
            ->reject(fn ($e) => ($e['permissionLevel'] ?? '') === 'siteUnverifiedUser')
            ->mapWithKeys(fn ($e) => [$e['siteUrl'] => $e['permissionLevel']])
            ->all();
    }

    /** The property as the account sees it; tries the other format (sc-domain ↔ URL prefix) too. */
    public function findProperty(string $siteUrl): ?string
    {
        $sites = $this->sites();
        if (array_key_exists($siteUrl, $sites)) {
            return $siteUrl;
        }

        $host = str_starts_with($siteUrl, 'sc-domain:') ? substr($siteUrl, 10) : (string) parse_url($siteUrl, PHP_URL_HOST);
        $bare = preg_replace('/^www\./', '', strtolower($host));
        foreach (["sc-domain:{$bare}", "https://www.{$bare}/", "https://{$bare}/", "http://www.{$bare}/", "http://{$bare}/"] as $candidate) {
            if (array_key_exists($candidate, $sites)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every Search Analytics row for one day, all pages, with the dimensions the BigQuery table needs.
     *
     * @return list<array{query: string, page: string, country: string, device: string, clicks: int, impressions: int, position: float}>
     */
    public function day(string $siteUrl, string $date, string $searchType = 'web'): array
    {
        $rows = [];
        $startRow = 0;
        $pageSize = (int) config('gsctool.sync.page_size', 25000);

        do {
            $response = $this->http()->post('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query', [
                'startDate' => $date,
                'endDate' => $date,
                'dimensions' => ['query', 'page', 'country', 'device'],
                'type' => $searchType,
                'dataState' => 'all',
                'rowLimit' => $pageSize,
                'startRow' => $startRow,
            ]);

            try {
                $response->throw();
            } catch (RequestException $e) {
                throw new RuntimeException('Search Console: '.($response->json('error.message') ?? $e->getMessage()), $response->status(), $e);
            }

            $page = $response->json('rows') ?? [];
            foreach ($page as $row) {
                $rows[] = [
                    'query' => (string) $row['keys'][0],
                    'page' => (string) $row['keys'][1],
                    'country' => strtolower((string) $row['keys'][2]),
                    'device' => strtoupper((string) $row['keys'][3]),
                    'clicks' => (int) round($row['clicks'] ?? 0),
                    'impressions' => (int) round($row['impressions'] ?? 0),
                    'position' => (float) ($row['position'] ?? 0),
                ];
            }
            $startRow += count($page);
        } while (count($page) === $pageSize);

        return $rows;
    }
}
