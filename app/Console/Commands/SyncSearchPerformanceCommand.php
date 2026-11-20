<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\PlanFeature;
use App\Domains\Integrations\Jobs\SyncBingWebmasterJob;
use App\Domains\Integrations\Jobs\SyncGoogleSearchConsoleJob;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\SearchPerformanceSyncService;
use Illuminate\Console\Command;

class SyncSearchPerformanceCommand extends Command
{
    protected $signature = 'search-console:sync
                            {--project= : Optional project ID to synchronize}
                            {--provider= : Optional provider filter (gsc or bing)}
                            {--now : Run synchronously immediately instead of queueing}';

    protected $description = 'Synchronize search performance data from Google Search Console and Bing Webmaster Tools.';

    public function handle(SearchPerformanceSyncService $syncService): int
    {
        $projectId = $this->option('project') ? (int) $this->option('project') : null;
        $provider = $this->option('provider') ? (string) $this->option('provider') : null;
        $runNow = (bool) $this->option('now');

        $query = ProjectIntegration::query()
            ->where('status', 'connected')
            ->whereIn('provider', ['gsc', 'bing']);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        if ($provider !== null) {
            $query->where('provider', $provider);
        }

        // Search performance sync is part of the traffic attribution plan feature.
        $integrations = $query->with('project.workspace')->get()
            ->filter(fn (ProjectIntegration $integration): bool => $integration->project?->workspace?->hasFeature(PlanFeature::TrafficAttribution) === true)
            ->values();

        if ($integrations->isEmpty()) {
            $this->info('No connected Search Console / Bing integrations found to synchronize.');

            return self::SUCCESS;
        }

        $this->info("Found {$integrations->count()} integration(s) to synchronize.");

        foreach ($integrations as $integration) {
            $this->line("Processing [{$integration->provider}] for project #{$integration->project_id} ({$integration->site_url})...");

            if ($runNow) {
                if ($integration->provider === 'gsc') {
                    $result = $syncService->syncGoogleSearchConsole($integration);
                } else {
                    $result = $syncService->syncBingWebmaster($integration);
                }

                if ($result['success']) {
                    $this->info("  -> Success: synced {$result['records_synced']} records.");
                } else {
                    $this->warn("  -> Failed: {$result['error']}");
                }
            } else {
                if ($integration->provider === 'gsc') {
                    SyncGoogleSearchConsoleJob::dispatch($integration);
                } else {
                    SyncBingWebmasterJob::dispatch($integration);
                }
                $this->info('  -> Dispatched sync job.');
            }
        }

        return self::SUCCESS;
    }
}
