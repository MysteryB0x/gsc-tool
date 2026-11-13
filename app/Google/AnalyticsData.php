<?php

namespace App\Google;

use App\Google\Contracts\CredentialProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** GA4 Data API (runReport). Free within GA4's quotas; no credits. */
class AnalyticsData
{
    public function __construct(protected CredentialProvider $credentials) {}

    protected function http()
    {
        return Http::withToken($this->credentials->accessToken([Scopes::ANALYTICS]))->timeout(60)->acceptJson();
    }

    public static function normalizeId(string $id): string
    {
        return preg_replace('/\D/', '', $id);
    }

    /** Can we read this property? Returns null when yes, else Google's reason. */
    public function accessProblem(string $propertyId): ?string
    {
        $response = $this->http()->get('https://analyticsdata.googleapis.com/v1beta/properties/'.static::normalizeId($propertyId).'/metadata');

        return $response->successful() ? null : ($response->json('error.message') ?? 'HTTP '.$response->status());
    }

    /**
     * Rows as [dimension => value, metric => number]. With two date ranges GA4 adds a "dateRange" column.
     *
     * @return list<array<string, mixed>>
     */
    public function report(string $propertyId, array $body): array
    {
        $rows = [];
        $offset = 0;
        $limit = $body['limit'] ?? 10000;

        do {
            $response = $this->http()->post('https://analyticsdata.googleapis.com/v1beta/properties/'.static::normalizeId($propertyId).':runReport', $body + ['limit' => $limit, 'offset' => $offset]);
            if ($response->failed()) {
                throw new RuntimeException('GA4: '.($response->json('error.message') ?? $response->status()), $response->status());
            }

            $json = $response->json();
            $dims = array_column($json['dimensionHeaders'] ?? [], 'name');
            $mets = array_column($json['metricHeaders'] ?? [], 'name');
            foreach ($json['rows'] ?? [] as $row) {
                $out = [];
                foreach ($dims as $i => $d) {
                    $out[$d] = $row['dimensionValues'][$i]['value'] ?? null;
                }
                foreach ($mets as $i => $m) {
                    $out[$m] = (float) ($row['metricValues'][$i]['value'] ?? 0);
                }
                $rows[] = $out;
            }
            $offset += $limit;
        } while (($json['rowCount'] ?? 0) > $offset && ! isset($body['limit']));

        return $rows;
    }
}
