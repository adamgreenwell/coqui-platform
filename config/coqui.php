<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | DataForSEO v3 Integration & Cost Controls
    |--------------------------------------------------------------------------
    |
    | Base URL defaults to the DataForSEO sandbox outside production so wiring
    | and postbacks can be exercised without spending account credits.
    | Scheduled background spend requires DATAFORSEO_SCHEDULED_SPEND=true.
    |
    */
    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'base_url' => env('DATAFORSEO_API_BASE_URL', 'https://sandbox.dataforseo.com'),
        'timeout' => (int) env('DATAFORSEO_HTTP_TIMEOUT', 60),
        'free_read_retries' => (int) env('DATAFORSEO_FREE_READ_RETRIES', 1),
        'webhook_secret' => env('DATAFORSEO_WEBHOOK_SECRET'),

        'default_location_code' => (int) env('DATAFORSEO_LOCATION_CODE', 2840),
        'default_language_code' => env('DATAFORSEO_LANGUAGE_CODE', 'en'),

        'scheduled_spend' => (bool) env('DATAFORSEO_SCHEDULED_SPEND', false),
        'daily_cap_usd' => (float) env('DATAFORSEO_DAILY_CAP_USD', 5.00),
        'monthly_cap_usd' => (float) env('DATAFORSEO_MONTHLY_CAP_USD', 100.00),

        'cache_ttl_hours' => [
            'keyword_overview' => 24 * 30,
            'domain_overview' => 24 * 14,
            'backlinks_summary' => 24 * 14,
            'serp_snapshot' => 24 * 7,
        ],

        // Pre-flight estimated unit costs (USD); actual charges are always read from
        // DataForSEO's response `cost` field.
        'prices' => [
            'serp_standard_task' => 0.0006,
            'serp_live_task' => 0.0020,
            'serp_ai_overview_surcharge' => 0.0006,
            'serp_paa_surcharge' => 0.00015,
            'labs_keyword_overview_task' => 0.012,
            'labs_keyword_overview_item' => 0.00012,
            'labs_domain_intersection_task' => 0.012,
            'labs_domain_intersection_item' => 0.00012,
            'backlinks_task' => 0.024,
            'backlinks_item' => 0.000036,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Headless Rust Cloud Worker (coqui-worker)
    |--------------------------------------------------------------------------
    */
    'worker' => [
        'url' => env('COQUI_WORKER_URL', 'http://coqui-worker:8085'),
        'token' => env('COQUI_WORKER_TOKEN'),
        'timeout' => (int) env('COQUI_WORKER_TIMEOUT', 30),
        'default_max_urls' => (int) env('COQUI_WORKER_DEFAULT_MAX_URLS', 1000),
        'smoke_max_urls' => (int) env('COQUI_WORKER_SMOKE_MAX_URLS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Coqui Desktop Sync Contract Limits
    |--------------------------------------------------------------------------
    */
    'sync' => [
        'max_urls_per_chunk' => 1000,
        'max_links_per_chunk' => 2500,
        'max_issues_per_chunk' => 2500,
    ],
];
