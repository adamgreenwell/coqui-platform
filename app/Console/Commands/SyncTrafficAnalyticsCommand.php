<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\PlanFeature;
use App\Domains\Integrations\Jobs\SyncTrafficAnalyticsJob;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\TrafficAttributionService;
use App\Domains\Integrations\TrafficProvider;
use Illuminate\Console\Command;
use Throwable;

class SyncTrafficAnalyticsCommand extends Command
{
    protected $signature = 'traffic:sync
                            {--project= : Optional project ID to synchronize}
                            {--provider= : Optional provider filter (ga4, umami or plausible)}
                            {--now : Run synchronously immediately instead of queueing}';

    protected $description = 'Synchronize page traffic from connected analytics integrations (GA4, Umami, Plausible).';

    public function handle(TrafficAttributionService $trafficService): int
    {
        $projectId = $this->option('project') ? (int) $this->option('project') : null;
        $provider = $this->option('provider') ? (string) $this->option('provider') : null;

        if ($provider !== null && TrafficProvider::tryFrom($provider) === null) {
            $this->error("Unknown analytics provider [{$provider}]. Use one of: ".implode(', ', TrafficProvider::values()).'.');

            return self::INVALID;
        }

        $query = ProjectIntegration::query()
            ->where('status', 'connected')
            ->whereIn('provider', $provider !== null ? [$provider] : TrafficProvider::values());

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $integrations = $query->with('project.workspace')->get()
            ->filter(fn (ProjectIntegration $integration): bool => $integration->project?->workspace?->hasFeature(PlanFeature::TrafficAttribution) === true)
            ->values();

        if ($integrations->isEmpty()) {
            $this->info('No connected analytics integrations found to synchronize.');

            return self::SUCCESS;
        }

        $this->info("Found {$integrations->count()} integration(s) to synchronize.");

        foreach ($integrations as $integration) {
            $this->line("Processing [{$integration->provider}] for project #{$integration->project_id} ({$integration->site_url})...");

            if (! $this->option('now')) {
                SyncTrafficAnalyticsJob::dispatch($integration);
                $this->info('  -> Dispatched sync job.');

                continue;
            }

            try {
                $count = $trafficService->syncIntegration($integration);
                $this->info("  -> Success: synced {$count} snapshots.");
            } catch (Throwable $e) {
                $this->warn("  -> Failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
