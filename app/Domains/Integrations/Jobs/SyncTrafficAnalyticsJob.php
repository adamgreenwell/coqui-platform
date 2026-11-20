<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Jobs;

use App\Domains\Billing\PlanFeature;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\SearchConsoleAuthException;
use App\Domains\Integrations\Services\TrafficAttributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Syncs one analytics integration (GA4, Plausible, ...) into page traffic
 * snapshots.
 */
class SyncTrafficAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly ProjectIntegration $integration,
        public readonly int $days = 30,
    ) {}

    public function handle(TrafficAttributionService $trafficService): void
    {
        // Queued before a downgrade, run after it: skip instead of syncing.
        $workspace = $this->integration->project?->workspace;
        if ($workspace === null || ! $workspace->hasFeature(PlanFeature::TrafficAttribution)) {
            return;
        }

        try {
            $trafficService->syncIntegration($this->integration, $this->days);
        } catch (SearchConsoleAuthException) {
            // The integration is marked as needing a reconnect; retrying with
            // the same credentials can't succeed.
            return;
        }
    }
}
