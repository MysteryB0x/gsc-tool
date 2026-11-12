<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search Console properties, the BigQuery query log and the pre-computed report tables the
 * dashboards read. Raw search data lives in BigQuery, never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('site_url');                                        // sc-domain:example.nl or https://www.example.nl/
            $table->string('source')->default('api');                          // api = synced via the Search Console API, bulk = Bulk Export
            $table->string('credential_provider')->default('service_account'); // service_account, oauth
            $table->string('bq_dataset')->nullable();                          // Bulk Export dataset (source = bulk)
            $table->string('ga4_property_id')->nullable();                     // phase 2
            $table->boolean('has_access')->default(false);
            $table->timestamp('access_checked_at')->nullable();
            $table->date('synced_until')->nullable();                          // newest day loaded into BigQuery
            $table->date('backfilled_from')->nullable();                       // oldest day loaded
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('reports_built_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['team_id', 'site_url']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status')->default('queued');                       // queued, running, done, failed
            $table->unsignedInteger('rows')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['property_id', 'created_at']);
        });

        // Every BigQuery job: who ran it, why, what it was estimated to cost and what it did cost.
        Schema::create('bq_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose');                                         // report:decay, explorer, load …
            $table->char('sql_hash', 64)->index();
            $table->text('sql')->nullable();
            $table->unsignedBigInteger('bytes_estimated')->default(0);
            $table->unsignedBigInteger('bytes_billed')->default(0);
            $table->unsignedInteger('credits_reserved')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->boolean('charged')->default(false);                        // false = paid by the plan (nightly reports) or the owner
            $table->boolean('from_cache')->default(false);
            $table->string('job_id')->nullable();
            $table->string('status')->default('done');                         // done, failed, refused
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->longText('result_json')->nullable();                       // cached result (ad-hoc queries), cleared after cache_hours
            $table->timestamps();
            $table->index(['team_id', 'created_at']);
        });

        Schema::create('daily_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('position', 8, 3)->default(0);
            $table->unique(['property_id', 'date']);
        });

        Schema::create('report_decay', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->json('monthly_clicks');                                    // {"2026-05": 120, …} oldest first
            $table->unsignedInteger('peak_clicks')->default(0);
            $table->unsignedInteger('last_clicks')->default(0);
            $table->decimal('change', 8, 4)->default(0);                       // last month vs peak, -0.40 = down 40%
            $table->index(['property_id', 'change']);
        });

        Schema::create('report_cannibalisation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('query', 1024);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedSmallInteger('url_count')->default(0);
            $table->json('urls');                                              // [{url, impressions, clicks, position, share}]
            $table->index(['property_id', 'impressions']);
        });

        Schema::create('report_striking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('query', 1024);
            $table->string('url', 2048);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('position', 8, 3)->default(0);
            $table->unsignedInteger('potential_clicks')->default(0);           // extra clicks a month at position 3
            $table->index(['property_id', 'potential_clicks']);
        });

        Schema::create('report_ctr', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('query', 1024);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('position', 8, 3)->default(0);
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('expected_ctr', 8, 6)->default(0);
            $table->integer('missed_clicks')->default(0);                      // expected − actual clicks in the window
            $table->index(['property_id', 'missed_clicks']);
        });

        // Things you did to a site; done ones become markers on the charts.
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('query', 1024)->nullable();
            $table->string('source')->nullable();                              // decay, cannibalisation, striking, ctr, manual
            $table->string('status')->default('open');                         // open, done, dismissed
            $table->date('done_on')->nullable();
            $table->timestamps();
            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['actions', 'report_ctr', 'report_striking', 'report_cannibalisation', 'report_decay', 'daily_totals', 'bq_queries', 'sync_runs', 'properties'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
