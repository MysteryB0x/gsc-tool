<?php

namespace App\Google;

use App\Google\Contracts\CredentialProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * BigQuery over REST: dry runs (free estimates), queries capped with maximumBytesBilled, and batch
 * load jobs (free, unlike streaming inserts). Rows come back as plain arrays typed by the schema.
 */
class BigQuery
{
    protected const API = 'https://bigquery.googleapis.com/bigquery/v2';

    public function __construct(
        protected CredentialProvider $credentials,
        protected ?string $project = null,
        protected ?string $location = null,
    ) {
        $this->project ??= config('gsctool.bigquery.project');
        $this->location ??= config('gsctool.bigquery.location', 'EU');
    }

    public function project(): string
    {
        return $this->project ?: throw new RuntimeException('BIGQUERY_PROJECT is not set.');
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->credentials->accessToken([Scopes::BIGQUERY]))->timeout(120)->acceptJson();
    }

    protected function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new RuntimeException("BigQuery $what: ".($response->json('error.message') ?? $response->status()), $response->status());
        }

        return $response;
    }

    /** Bytes the query would process. Free; nothing runs. */
    public function dryRun(string $sql): int
    {
        $response = $this->check($this->http()->post(self::API."/projects/{$this->project()}/queries", [
            'query' => $sql,
            'useLegacySql' => false,
            'dryRun' => true,
            'location' => $this->location,
        ]), 'dry run');

        return (int) $response->json('totalBytesProcessed', 0);
    }

    /**
     * Run a query. BigQuery refuses to run it at all when it would bill more than $maxBytesBilled.
     *
     * @return array{rows: list<array<string, mixed>>, bytes_billed: int, bytes_processed: int, job_id: ?string, cache_hit: bool}
     */
    public function query(string $sql, int $maxBytesBilled, array $labels = []): array
    {
        $response = $this->check($this->http()->post(self::API."/projects/{$this->project()}/queries", array_filter([
            'query' => $sql,
            'useLegacySql' => false,
            'location' => $this->location,
            'maximumBytesBilled' => (string) $maxBytesBilled,
            'timeoutMs' => 60000,
            'maxResults' => 10000,
            'labels' => $labels ?: null,
        ], fn ($v) => $v !== null)), 'query');

        $body = $response->json();
        $jobId = $body['jobReference']['jobId'] ?? null;

        // Not done within the timeout, or more rows than one page: keep reading the results.
        while (! ($body['jobComplete'] ?? false)) {
            $body = $this->results($jobId);
        }

        $schema = $body['schema']['fields'] ?? [];
        $rows = $this->decodeRows($schema, $body['rows'] ?? []);
        $pageToken = $body['pageToken'] ?? null;
        while ($pageToken && $jobId) {
            $page = $this->results($jobId, $pageToken);
            array_push($rows, ...$this->decodeRows($schema, $page['rows'] ?? []));
            $pageToken = $page['pageToken'] ?? null;
        }

        $billed = $body['totalBytesBilled'] ?? null;
        if ($billed === null && $jobId) {
            $billed = $this->job($jobId)['statistics']['query']['totalBytesBilled'] ?? 0;
        }

        return [
            'rows' => $rows,
            'bytes_billed' => (int) $billed,
            'bytes_processed' => (int) ($body['totalBytesProcessed'] ?? 0),
            'job_id' => $jobId,
            'cache_hit' => (bool) ($body['cacheHit'] ?? false),
        ];
    }

    protected function results(string $jobId, ?string $pageToken = null): array
    {
        return $this->check($this->http()->get(self::API."/projects/{$this->project()}/queries/{$jobId}", array_filter([
            'location' => $this->location,
            'timeoutMs' => 60000,
            'maxResults' => 10000,
            'pageToken' => $pageToken,
        ])), 'results')->json();
    }

    public function job(string $jobId): array
    {
        return $this->check($this->http()->get(self::API."/projects/{$this->project()}/jobs/{$jobId}", ['location' => $this->location]), 'job')->json();
    }

    /** @return list<array<string, mixed>> */
    public function decodeRows(array $schema, array $rows): array
    {
        return array_map(function (array $row) use ($schema) {
            $out = [];
            foreach ($schema as $i => $field) {
                $v = $row['f'][$i]['v'] ?? null;
                $out[$field['name']] = $v === null ? null : match ($field['type']) {
                    'INTEGER', 'INT64' => (int) $v,
                    'FLOAT', 'FLOAT64', 'NUMERIC', 'BIGNUMERIC' => (float) $v,
                    'BOOLEAN', 'BOOL' => $v === 'true',
                    default => $v,
                };
            }

            return $out;
        }, $rows);
    }

    public function ensureDataset(string $dataset): void
    {
        $response = $this->http()->get(self::API."/projects/{$this->project()}/datasets/{$dataset}");
        if ($response->status() === 404) {
            $this->check($this->http()->post(self::API."/projects/{$this->project()}/datasets", [
                'datasetReference' => ['projectId' => $this->project(), 'datasetId' => $dataset],
                'location' => $this->location,
            ]), 'create dataset');

            return;
        }
        $this->check($response, 'dataset');
    }

    /** Create the table if it's missing: partitioned by day on $partitionField, clustered by $clusterBy. */
    public function ensureTable(string $dataset, string $table, array $schema, string $partitionField, array $clusterBy = []): void
    {
        $response = $this->http()->get(self::API."/projects/{$this->project()}/datasets/{$dataset}/tables/{$table}");
        if ($response->status() !== 404) {
            $this->check($response, 'table');

            return;
        }

        $this->check($this->http()->post(self::API."/projects/{$this->project()}/datasets/{$dataset}/tables", array_filter([
            'tableReference' => ['projectId' => $this->project(), 'datasetId' => $dataset, 'tableId' => $table],
            'schema' => ['fields' => $schema],
            'timePartitioning' => ['type' => 'DAY', 'field' => $partitionField],
            'clustering' => $clusterBy ? ['fields' => $clusterBy] : null,
            'requirePartitionFilter' => true,
        ])), 'create table');
    }

    /**
     * Batch-load newline-delimited JSON (free). With a partition ("20261110") and WRITE_TRUNCATE the
     * load replaces that one day only, so re-syncing a day never duplicates rows.
     */
    public function loadJson(string $dataset, string $table, string $ndjson, ?string $partition = null, string $writeDisposition = 'WRITE_TRUNCATE'): string
    {
        $boundary = 'gsct'.Str::random(16);
        $config = json_encode(['configuration' => ['load' => [
            'destinationTable' => ['projectId' => $this->project(), 'datasetId' => $dataset, 'tableId' => $partition ? "{$table}\${$partition}" : $table],
            'sourceFormat' => 'NEWLINE_DELIMITED_JSON',
            'writeDisposition' => $writeDisposition,
        ]], 'jobReference' => ['location' => $this->location]]);

        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$config}\r\n"
            ."--{$boundary}\r\nContent-Type: application/octet-stream\r\n\r\n{$ndjson}\r\n--{$boundary}--";

        $job = $this->check(
            $this->http()->withBody($body, "multipart/related; boundary={$boundary}")
                ->post("https://bigquery.googleapis.com/upload/bigquery/v2/projects/{$this->project()}/jobs?uploadType=multipart"),
            'load'
        )->json();

        $jobId = $job['jobReference']['jobId'];
        $deadline = time() + 300;
        while (($job['status']['state'] ?? '') !== 'DONE') {
            if (time() > $deadline) {
                throw new RuntimeException("BigQuery load job $jobId still running after 5 minutes.");
            }
            usleep(1_000_000);
            $job = $this->job($jobId);
        }
        if (isset($job['status']['errorResult'])) {
            throw new RuntimeException('BigQuery load: '.$job['status']['errorResult']['message']);
        }

        return $jobId;
    }
}
