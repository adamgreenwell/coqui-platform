<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Disks
    |--------------------------------------------------------------------------
    |
    | Everything customer-uploaded lives on object storage. The application
    | container only keeps its own logs and caches, so a customer's crawl
    | export can never fill the volume the app itself runs on.
    |
    | The "r2" disk is Cloudflare R2 through its S3-compatible API. Only the
    | imports feature uses it today; keep the credentials on a token scoped to
    | this one bucket.
    |
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('R2_USE_PATH_STYLE_ENDPOINT', false),
            // Nothing in a customer bucket is ever public. Downloads are
            // short-lived presigned URLs issued per request.
            'visibility' => 'private',
            'throw' => true,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
