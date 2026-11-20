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
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * A long first sync can outlive the queue's retry window. Another copy
     * of the job waits instead of fetching the same days alongside it.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->integration->id))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

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
