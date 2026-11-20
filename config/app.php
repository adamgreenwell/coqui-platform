<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Coqui SEO'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    // Treat every request as https (see ForceHttpsScheme). Defaults to on when
    // APP_URL is https, since the proxy chain may forward the scheme as http.
    // An empty value (Compose passes `${APP_FORCE_HTTPS:-}`) means "not set".
    'force_https' => in_array(env('APP_FORCE_HTTPS'), [null, ''], true)
        ? str_starts_with((string) env('APP_URL', ''), 'https://')
        : filter_var(env('APP_FORCE_HTTPS'), FILTER_VALIDATE_BOOLEAN),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
        ),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
    ],
];
