<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CrawlSyncController;
use App\Http\Controllers\Internal\CoquiWorkerCallbackController;
use App\Http\Controllers\Webhooks\DataForSeoWebhookController;
use App\Http\Controllers\Webhooks\RunnrDeployWebhookController;
use App\Http\Middleware\VerifyCoquiWorkerToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', function (Request $request) {
        return response()->json([
            'user' => $request->user()?->only(['id', 'name', 'email']),
        ]);
    });

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
