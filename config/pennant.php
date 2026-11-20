<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Pennant Store
    |--------------------------------------------------------------------------
    |
    | Every plan feature is a pure function of the workspace's billing state
    | (plan tier and Runnr add-on), so nothing needs to be persisted. The
    | array store resolves each feature fresh on every request and queued
    | job; Pennant flushes it between Octane requests and after each job, and
    | the Workspace model flushes it when billing state changes mid-request.
    | The database store would remember the first value it resolved and keep
    | serving it after an upgrade or downgrade.
    |
    | Supported: "array", "database"
    |
    */

    'default' => env('PENNANT_STORE', 'array'),

    'stores' => [

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'features',
        ],

    ],
];
