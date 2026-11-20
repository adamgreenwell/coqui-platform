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
            'related_keywords' => 24 * 30,
            'domain_overview' => 24 * 14,
            'domain_intersection' => 24 * 14,
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
            'labs_related_keywords_task' => 0.012,
            'labs_related_keywords_item' => 0.00012,
            'labs_domain_intersection_task' => 0.012,
            'labs_domain_intersection_item' => 0.00012,
            'backlinks_task' => 0.024,
            'backlinks_item' => 0.000036,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SERPHouse Rank-Tracking Fallback
    |--------------------------------------------------------------------------
    |
    | Re-checks rankings when a DataForSEO rank task fails or stalls. Live
    | Google results, one 10-result page per request ($0.75 per 1,000 pages on
    | the Basic plan). Spend counts toward the workspace's live data allowance.
    |
    */
    'serphouse' => [
        'api_key' => env('SERPHOUSE_API_KEY'),
        'base_url' => env('SERPHOUSE_API_BASE_URL', 'https://api.serphouse.com'),
        'timeout' => (int) env('SERPHOUSE_HTTP_TIMEOUT', 30),
        'fallback_enabled' => (bool) env('SERPHOUSE_FALLBACK_ENABLED', false),
        'google_domain' => env('SERPHOUSE_GOOGLE_DOMAIN', 'google.com'),
        // A pending primary task older than this gets a fallback check.
        'stale_after_hours' => (int) env('SERPHOUSE_STALE_AFTER_HOURS', 6),
        // 10 pages of 10 results matches the primary check's depth of 100.
        'max_pages' => (int) env('SERPHOUSE_MAX_PAGES', 10),
        'page_cost_usd' => (float) env('SERPHOUSE_PAGE_COST_USD', 0.00075),
        'daily_cap_usd' => (float) env('SERPHOUSE_DAILY_CAP_USD', 2.00),
        'monthly_cap_usd' => (float) env('SERPHOUSE_MONTHLY_CAP_USD', 25.00),
        // Each check can make max_pages requests; 5 checks a minute stays
        // under SERPHouse's 60 requests a minute.
        'checks_per_minute' => (int) env('SERPHOUSE_CHECKS_PER_MINUTE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | SE Ranking Data API & Credit Controls
    |--------------------------------------------------------------------------
    |
    | Used alongside DataForSEO for flat-batch keyword screening and domain
    | organic visibility. Backlinks and SERP tracking stay on DataForSEO.
    |
    */
    'seranking' => [
        'api_key' => env('SERANKING_API_KEY'),
        'base_url' => env('SERANKING_API_BASE_URL', 'https://api.seranking.com'),
        'default_source' => env('SERANKING_SOURCE', 'us'),
        'timeout' => (int) env('SERANKING_HTTP_TIMEOUT', 30),
        'scheduled_spend' => (bool) env('SERANKING_SCHEDULED_SPEND', false),
        'daily_credit_cap' => (int) env('SERANKING_DAILY_CREDIT_CAP', 5000),
        'monthly_credit_cap' => (int) env('SERANKING_MONTHLY_CREDIT_CAP', 100000),
        'batch_size' => (int) env('SERANKING_BATCH_SIZE', 1000),
        'min_split_batch_size' => 50,
        'cache_ttl_hours' => [
            'keywords_export' => 24 * 30,
            'domain_overview' => 24 * 14,
        ],
        'credit_costs' => [
            'keywords_export' => 100,
            'domain_overview' => 100,
            'domain_competitors' => 100,
            'domain_keywords' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public MCP Server Surface
    |--------------------------------------------------------------------------
    */
    'mcp' => [
        'path' => env('COQUI_MCP_PATH', '/mcp/coqui'),
        // Auth is on everywhere except local development; tokens need the mcp:use ability.
        'require_auth' => (bool) env('COQUI_MCP_REQUIRE_AUTH', env('APP_ENV') !== 'local'),
        'rate_limit_per_minute' => (int) env('COQUI_MCP_RATE_LIMIT', 60),
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
