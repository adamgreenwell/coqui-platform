<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\BotTelemetryApiController;
use App\Http\Controllers\Api\V1\CrawlSyncController;
use App\Http\Controllers\Api\V1\MeApiController;
use App\Http\Controllers\Api\V1\ProjectApiController;
use App\Http\Controllers\Api\V1\RunnrDeploySmokeController;
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

    Route::get('/projects/{project}/crawls/compare', [CrawlSyncController::class, 'compare'])
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

    // Runnr deploy smoke test inspection
    Route::get('/projects/{project}/deploy-smoke/latest', [RunnrDeploySmokeController::class, 'latest'])
        ->middleware('ability:projects:read,crawls:read');

    // Bot crawl logs telemetry inspection
    Route::get('/projects/{project}/telemetry/bot-logs', [BotTelemetryApiController::class, 'show'])
        ->middleware('ability:projects:read,crawls:read');
});

// Runnr edge bot crawler telemetry ingestion
Route::post('/v1/telemetry/bot-logs', [BotTelemetryApiController::class, 'ingest'])
    ->name('api.telemetry.bot_logs.ingest');

// Public deploy smoke SVG status badge
Route::get('/v1/projects/{project}/deploy-smoke/badge', [RunnrDeploySmokeController::class, 'badge'])
    ->name('api.projects.deploy_smoke.badge');

Route::post('/internal/crawls/callback', CoquiWorkerCallbackController::class)
    ->middleware(VerifyCoquiWorkerToken::class)
    ->name('internal.crawls.callback');

Route::post('/webhooks/dataforseo', DataForSeoWebhookController::class)
    ->name('webhooks.dataforseo');

Route::post('/webhooks/runnr/deploy', RunnrDeployWebhookController::class)
    ->name('webhooks.runnr.deploy');
