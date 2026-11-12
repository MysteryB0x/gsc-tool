<?php

/*
 * Everything that makes this app "this app": its name, look, who may sign up, how credits work and
 * where the BigQuery data lives. Change the name by setting APP_NAME (and GSCT_*) in .env — nothing
 * in the code hardcodes it.
 */
return [

    // Shown in the panels, emails and page titles. Falls back to APP_NAME.
    'name' => env('GSCT_NAME', env('APP_NAME', 'GSC Tool')),

    // Short name for URLs, BigQuery labels and log prefixes (lowercase, no spaces).
    'slug' => env('GSCT_SLUG', 'gsc-tool'),

    // Primary colour of both panels.
    'primary_color' => env('GSCT_PRIMARY_COLOR', '#2563EB'),

    // Sign-ups. Off while the tool is only for the owner; switch on when paid plans go live.
    'registration' => (bool) env('GSCT_REGISTRATION', false),

    // Plan a new workspace starts on, and the plan admins (the owner) get.
    'signup_plan' => env('GSCT_SIGNUP_PLAN', 'starter'),
    'owner_plan' => 'owner',

    'timezone' => env('GSCT_TIMEZONE', 'Europe/Amsterdam'),

    'google' => [
        // Service-account key file (JSON), stored outside the web root. Relative paths are read from the app folder.
        'credentials' => env('GOOGLE_SERVICE_ACCOUNT_JSON'),

        // OAuth is built in but switched off: service accounts only for now.
        'oauth_enabled' => (bool) env('GOOGLE_OAUTH_ENABLED', false),
        'oauth_client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
    ],

    'bigquery' => [
        'project' => env('BIGQUERY_PROJECT'),
        'location' => env('BIGQUERY_LOCATION', 'EU'),

        // Dataset for properties synced through the Search Console API (no Bulk Export).
        'api_dataset' => env('BIGQUERY_API_DATASET', 'gsc_api'),
        'api_table' => 'searchdata_url_impression',

        // Hard ceiling for any single query, whatever the credits (protects against a runaway query).
        'max_bytes_per_query' => (int) env('BIGQUERY_MAX_BYTES_PER_QUERY', 50 * 1024 ** 3),

        // BigQuery bills at least 10 MB per table a query references.
        'min_bytes_billed' => 10 * 1024 ** 2,
    ],

    'credits' => [
        // 1 credit = this many bytes billed by BigQuery (1 GiB). BigQuery on-demand: ~$6.25 per TiB.
        'bytes_per_credit' => (int) env('GSCT_BYTES_PER_CREDIT', 1024 ** 3),

        // Every metered query costs at least this many credits.
        'min_per_query' => 1,

        // A repeat of the same query within this many hours is served from cache for 0 credits.
        'cache_hours' => (int) env('GSCT_QUERY_CACHE_HOURS', 24),

        // Credits a team may spend per day (null = no daily cap).
        'daily_cap' => env('GSCT_DAILY_CREDIT_CAP') !== null ? (int) env('GSCT_DAILY_CREDIT_CAP') : null,

        // Fixed credits per paid third-party call (phase 3).
        'ai_brief' => 5,
        'serp_context' => 2,
    ],

    'sync' => [
        // Search Console keeps 16 months; the backfill fetches this many days.
        'backfill_days' => 486,
        // Data lags 2–3 days; the daily sync refetches this many recent days.
        'refetch_days' => 4,
        // Search Analytics API page size (max 25,000).
        'page_size' => 25000,
    ],

    'reports' => [
        // Window for the "current" reports (cannibalisation, striking distance, CTR).
        'window_days' => 28,

        // Striking distance: positions and minimum impressions.
        'striking' => ['min_position' => 4, 'max_position' => 20, 'min_impressions' => 50],

        // Cannibalisation: a query counts when 2+ URLs each get at least this share of its impressions.
        'cannibalisation' => ['min_share' => 0.10, 'min_impressions' => 100],

        // Content decay: months compared and the drop that flags a page.
        'decay' => ['months' => 6, 'min_clicks' => 30, 'drop' => 0.25],

        // Expected organic CTR by position 1–20 (a typical curve; tune per site later).
        'ctr_curve' => [
            1 => 0.276, 2 => 0.158, 3 => 0.110, 4 => 0.084, 5 => 0.063, 6 => 0.049, 7 => 0.039, 8 => 0.033, 9 => 0.027, 10 => 0.024,
            11 => 0.018, 12 => 0.016, 13 => 0.015, 14 => 0.014, 15 => 0.013, 16 => 0.012, 17 => 0.011, 18 => 0.010, 19 => 0.010, 20 => 0.009,
        ],
    ],
];
