<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CrawlSyncController;
use App\Http\Controllers\Api\V1\MeApiController;
use App\Http\Controllers\Api\V1\ProjectApiController;
use App\Http\Controllers\Internal\CoquiWorkerCallbackController;
use App\Http\Controllers\Webhooks\DataForSeoWebhookController;
use App\Http\Controllers\Webhooks\RunnrDeployWebhookController;
use App\Http\Middleware\VerifyCoquiWorkerToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    // Current authenticated user identity & workspaces
    Route::get('/me', [MeApiController::class, 'show']);

    // Project management for standalone app
    Route::get('/projects', [ProjectApiController::class, 'index'])
        ->middleware('ability:projects:read,crawls:sync');

    Route::post('/projects', [ProjectApiController::class, 'store'])
        ->middleware('ability:projects:write,crawls:sync');

    Route::get('/projects/{project}', [ProjectApiController::class, 'show'])
        ->middleware('ability:projects:read,crawls:sync');

    // Historical crawl runs & inspection for standalone app
    Route::get('/projects/{project}/crawls', [CrawlSyncController::class, 'index'])
        ->middleware('ability:crawls:read,crawls:sync,projects:read');

    Route::get('/projects/{project}/crawls/{crawlId}', [CrawlSyncController::class, 'show'])
        ->middleware('ability:crawls:read,crawls:sync,projects:read');

    // Desktop crawl synchronization & streaming ingestion
    Route::post('/projects/{project}/crawls/sync', [CrawlSyncController::class, 'sync'])
        ->middleware('ability:crawls:sync');

    Route::post('/projects/{project}/crawls/{crawlId}/chunks', [CrawlSyncController::class, 'ingestChunk'])
        ->middleware('ability:crawls:sync');

    Route::post('/projects/{project}/crawls/{crawlId}/finalize', [CrawlSyncController::class, 'finalize'])
        ->middleware('ability:crawls:sync');
});

Route::post('/internal/crawls/callback', CoquiWorkerCallbackController::class)
    ->middleware(VerifyCoquiWorkerToken::class)
    ->name('internal.crawls.callback');

Route::post('/webhooks/dataforseo', DataForSeoWebhookController::class)
    ->name('webhooks.dataforseo');

Route::post('/webhooks/runnr/deploy', RunnrDeployWebhookController::class)
    ->name('webhooks.runnr.deploy');
