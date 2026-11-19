<?php

declare(strict_types=1);

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'search_console_base_url' => env('SEARCH_CONSOLE_API_BASE_URL', 'https://searchconsole.googleapis.com'),
        'ga4_base_url' => env('GA4_DATA_API_BASE_URL', 'https://analyticsdata.googleapis.com'),
    ],

    'bing' => [
        'api_key' => env('BING_WEBMASTER_API_KEY'),
        'base_url' => env('BING_WEBMASTER_API_BASE_URL', 'https://ssl.bing.com/webmaster/api.svc/json'),
    ],

    'plausible' => [
        'base_url' => env('PLAUSIBLE_API_BASE_URL', 'https://plausible.io'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'base_url' => env('STRIPE_API_BASE_URL', 'https://api.stripe.com'),
    ],

    'runnr' => [
        'webhook_secret' => env('RUNNR_WEBHOOK_SECRET'),
        'telemetry_token' => env('RUNNR_TELEMETRY_TOKEN'),
    ],
];
