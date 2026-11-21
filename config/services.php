<?php

declare(strict_types=1);

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Register exactly this URI in Google Cloud Console (defaults to {APP_URL}/integrations/google/callback).
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'search_console_base_url' => env('SEARCH_CONSOLE_API_BASE_URL', 'https://searchconsole.googleapis.com'),
        'ga4_base_url' => env('GA4_DATA_API_BASE_URL', 'https://analyticsdata.googleapis.com'),
        // Lists the GA4 properties a Google account can see, for the property picker.
        'ga4_admin_base_url' => env('GA4_ADMIN_API_BASE_URL', 'https://analyticsadmin.googleapis.com'),
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
        // Recurring Price IDs (price_...) from the Stripe dashboard. Use the
        // test-mode prices with sk_test_ keys and live prices with sk_live_ keys.
        'prices' => [
            'personal' => env('STRIPE_PRICE_PERSONAL'),
            'pro' => env('STRIPE_PRICE_PRO'),
            'agency' => env('STRIPE_PRICE_AGENCY'),
            'runnr_addon' => env('STRIPE_PRICE_RUNNR_ADDON'),
        ],
    ],

    'runnr' => [
        'webhook_secret' => env('RUNNR_WEBHOOK_SECRET'),
        'telemetry_token' => env('RUNNR_TELEMETRY_TOKEN'),
    ],
];
