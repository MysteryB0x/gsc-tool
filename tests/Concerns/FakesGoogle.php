<?php

namespace Tests\Concerns;

use App\Models\Plan;
use App\Models\Property;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Http;

trait FakesGoogle
{
    protected function fakeServiceAccount(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = sys_get_temp_dir().'/gsct-test-key-'.getmypid().'.json';
        file_put_contents($path, json_encode(['client_email' => 'gsc-tool@test-project.iam.gserviceaccount.com', 'private_key' => $pem, 'project_id' => 'test-project']));

        config([
            'gsctool.google.credentials' => $path,
            'gsctool.bigquery.project' => 'test-project',
        ]);
    }

    /**
     * Fake BigQuery: dry runs answer $estimatedBytes, queries answer $rows and bill $billedBytes.
     * Recorded requests are checked with Http::assertSent.
     */
    protected function fakeBigQuery(int $estimatedBytes, int $billedBytes, array $rows = [], array $schema = []): void
    {
        $this->fakeServiceAccount();

        Http::fake(function ($request) use ($estimatedBytes, $billedBytes, $rows, $schema) {
            $url = $request->url();
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
            }
            if (str_ends_with(parse_url($url, PHP_URL_PATH), '/queries') && $request->method() === 'POST') {
                if ($request['dryRun'] ?? false) {
                    return Http::response(['totalBytesProcessed' => (string) $estimatedBytes, 'jobComplete' => true]);
                }

                return Http::response([
                    'jobComplete' => true,
                    'jobReference' => ['jobId' => 'job_1'],
                    'totalBytesProcessed' => (string) $billedBytes,
                    'totalBytesBilled' => (string) $billedBytes,
                    'schema' => ['fields' => $schema],
                    'rows' => array_map(fn ($r) => ['f' => array_map(fn ($v) => ['v' => $v === null ? null : (string) $v], array_values($r))], $rows),
                ]);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });
    }

    protected function workspace(string $planKey = 'starter', int $credits = 0): array
    {
        $this->seed(PlanSeeder::class);
        $user = User::factory()->create(['is_admin' => $planKey === 'owner']);
        $team = Team::create(['name' => 'Test', 'plan_id' => Plan::where('key', $planKey)->value('id'), 'credit_balance' => $credits]);
        $team->users()->attach($user, ['role' => 'owner']);
        $property = Property::create(['team_id' => $team->id, 'name' => 'Example', 'site_url' => 'sc-domain:example.nl', 'has_access' => true]);

        return [$user, $team->fresh(), $property];
    }
}
