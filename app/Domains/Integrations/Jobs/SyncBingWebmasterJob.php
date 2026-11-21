<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Jobs;

use App\Domains\Billing\PlanFeature;
use App\Domains\Integrations\Actions\LogIntegrationSyncFailure;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\SearchPerformanceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncBingWebmasterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly ProjectIntegration $integration
    ) {}

    public function handle(SearchPerformanceSyncService $syncService): void
    {
        // Queued before a downgrade, run after it: skip instead of syncing.
        $workspace = $this->integration->project?->workspace;
        if ($workspace === null || ! $workspace->hasFeature(PlanFeature::TrafficAttribution)) {
            return;
        }

        $result = $syncService->syncBingWebmaster($this->integration);

        if (! $result['success'] && ($result['error'] ?? null) !== null && $this->integration->fresh()?->status !== 'needs_reconnect') {
            throw new \RuntimeException("Bing Webmaster sync failed: {$result['error']}");
        }
    }

    /**
     * After the last retry: record the failure in the project's activity feed.
     */
    public function failed(?Throwable $exception): void
    {
        LogIntegrationSyncFailure::handle($this->integration, 'Bing Webmaster Tools', $exception);
    }
}
