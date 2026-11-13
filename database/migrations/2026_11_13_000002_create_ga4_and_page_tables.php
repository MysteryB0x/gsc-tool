<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GA4 data (small, aggregated by the Data API, so it lives in MariaDB) and per-page Search Console
 * totals (built nightly from BigQuery) that the Analytics page and the change alerts read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('ga4_has_access')->default(false)->after('ga4_property_id');
            $table->date('ga4_synced_until')->nullable()->after('ga4_has_access');
            $table->text('ga4_error')->nullable()->after('ga4_synced_until');
        });

        Schema::create('ga4_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedInteger('active_users')->default(0);
            $table->unsignedInteger('key_events')->default(0);
            $table->decimal('revenue', 14, 2)->default(0);
            $table->unsignedInteger('organic_sessions')->default(0);   // Organic Search channel
            $table->unique(['property_id', 'date']);
        });

        Schema::create('ga4_ai_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('source');                                  // chatgpt.com, perplexity.ai …
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('key_events')->default(0);
            $table->unique(['property_id', 'date', 'source']);
        });

        Schema::create('ga4_landing_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024);
            $table->unsignedInteger('sessions')->default(0);           // last 28 days
            $table->unsignedInteger('sessions_prev')->default(0);      // the 28 before
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedInteger('key_events')->default(0);
            $table->decimal('revenue', 14, 2)->default(0);
            $table->index(['property_id', 'sessions']);
        });

        Schema::create('report_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('path', 1024);
            $table->unsignedInteger('clicks_7')->default(0);
            $table->unsignedInteger('clicks_prev_7')->default(0);
            $table->unsignedInteger('clicks_28')->default(0);
            $table->unsignedInteger('clicks_prev_28')->default(0);
            $table->unsignedInteger('impressions_28')->default(0);
            $table->decimal('position_28', 8, 3)->default(0);
            $table->index(['property_id', 'clicks_28']);
            $table->index(['property_id', 'path']);
        });
    }

    public function down(): void
    {
        foreach (['report_pages', 'ga4_landing_pages', 'ga4_ai_referrals', 'ga4_daily'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn(['ga4_has_access', 'ga4_synced_until', 'ga4_error']));
    }
};
