<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domains\Integrations\Services\RunnrDeployWebhookHandler;
use App\Domains\Projects\Models\Project;
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

        if ($secret === '') {
            return response()->json(['message' => 'Runnr webhook secret is not configured.'], 503);
        }

        if ($signature === '') {
            return response()->json(['message' => 'Missing webhook signature.'], 401);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $validated = $request->validate([
            'site_id' => ['required', 'string', 'max:120'],
            'deploy_url' => ['nullable', 'url', 'max:2048'],
            'deploy_id' => ['nullable', 'string', 'max:120'],
            'environment' => ['nullable', 'string', 'max:64'],
            'commit_sha' => ['nullable', 'string', 'max:64'],
            'callback_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $run = $this->handler->handleDeploy(
            (string) $validated['site_id'],
            isset($validated['deploy_url']) ? (string) $validated['deploy_url'] : null,
            [
                'deploy_id' => isset($validated['deploy_id']) ? (string) $validated['deploy_id'] : null,
                'environment' => isset($validated['environment']) ? (string) $validated['environment'] : null,
                'commit_sha' => isset($validated['commit_sha']) ? (string) $validated['commit_sha'] : null,
                'callback_url' => isset($validated['callback_url']) ? (string) $validated['callback_url'] : null,
            ],
        );

        if ($run === null) {
            $reason = Project::query()->where('runnr_site_id', (string) $validated['site_id'])->exists()
                ? 'feature_not_in_plan'
                : 'no_matching_project';

            return response()->json(['status' => 'ignored', 'reason' => $reason], 202);
        }

        return response()->json([
            'status' => 'queued',
            'crawlId' => $run->external_crawl_id,
        ], 202);
    }
}
