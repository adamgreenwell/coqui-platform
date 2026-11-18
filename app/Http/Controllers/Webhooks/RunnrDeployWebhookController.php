<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domains\Integrations\Services\RunnrDeployWebhookHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunnrDeployWebhookController extends Controller
{
    public function __construct(
        private readonly RunnrDeployWebhookHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.runnr.webhook_secret');
        $signature = (string) $request->header('X-Runnr-Signature', '');

        if ($secret !== '') {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);
            if (! hash_equals($expected, $signature)) {
                return response()->json(['message' => 'Invalid signature.'], 403);
            }
        }

        $validated = $request->validate([
            'site_id' => ['required', 'string', 'max:120'],
            'deploy_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $run = $this->handler->handleDeploy(
            (string) $validated['site_id'],
            isset($validated['deploy_url']) ? (string) $validated['deploy_url'] : null,
        );

        if ($run === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'no_matching_project'], 202);
        }

        return response()->json([
            'status' => 'queued',
            'crawlId' => $run->external_crawl_id,
        ], 202);
    }
}
