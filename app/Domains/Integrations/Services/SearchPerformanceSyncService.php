<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Models\SearchPerformanceQuery;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SearchPerformanceSyncService
{
    public function __construct(
        private readonly GoogleSearchConsoleClient $gscClient,
        private readonly BingWebmasterClient $bingClient,
    ) {}

    /**
     * Synchronize query search performance from Google Search Console.
     * Respects Google's 4-day finalization lag.
     *
     * @return array{success: bool, records_synced: int, error?: string}
     */
    public function syncGoogleSearchConsole(ProjectIntegration $integration): array
    {
        $project = $integration->project;
        if ($project === null) {
            return ['success' => false, 'records_synced' => 0, 'error' => 'Project not found'];
        }

        try {
            // Google Search Console final data has a ~4-day lag: sync trailing 28 finalized days
            $endDate = Carbon::now()->subDays(4);
            $startDate = Carbon::now()->subDays(32);

            $rows = $this->gscClient->querySearchAnalytics(
                integration: $integration,
                startDate: $startDate,
                endDate: $endDate,
                dimensions: ['query', 'date'],
                rowLimit: 5000,
            );

            $recordsToUpsert = [];
            $now = Carbon::now();

            foreach ($rows as $row) {
                $keys = $row['keys'];
                $query = isset($keys[0]) && is_string($keys[0]) ? trim($keys[0]) : '';
                $dateStr = isset($keys[1]) && is_string($keys[1]) ? trim($keys[1]) : '';

                if ($query === '' || $dateStr === '') {
                    continue;
                }

                // Truncate query to 255 chars if needed
                $safeQuery = mb_substr($query, 0, 255);

                $recordsToUpsert[] = [
                    'project_id' => $project->id,
                    'provider' => 'gsc',
                    'query' => $safeQuery,
                    'date' => $dateStr,
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions'],
                    'ctr' => $row['ctr'],
                    'position' => $row['position'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($recordsToUpsert !== []) {
                foreach (array_chunk($recordsToUpsert, 500) as $chunk) {
                    SearchPerformanceQuery::query()->upsert(
                        $chunk,
                        ['project_id', 'provider', 'query', 'date'],
                        ['clicks', 'impressions', 'ctr', 'position', 'updated_at'],
                    );
                }
            }

            $count = count($recordsToUpsert);

            $integration->update([
                'status' => 'connected',
                'last_synced_at' => $now,
            ]);

            ProjectActivity::log(
                project: $project,
                eventType: 'integration.synced',
                title: 'Google Search Console Synced',
                description: "Synchronized {$count} query performance records from Google Search Console.",
                properties: [
                    'provider' => 'gsc',
                    'count' => $count,
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
            );

            return ['success' => true, 'records_synced' => $count];
        } catch (SearchConsoleAuthException $e) {
            $integration->markNeedsReconnect($e->getMessage());

            Log::warning(sprintf(
                'Google Search Console auth error for project %d: %s',
                $project->id,
                $e->getMessage(),
            ));

            ProjectActivity::log(
                project: $project,
                eventType: 'integration.error',
                title: 'Google Search Console Authorization Expired',
                description: 'Google Search Console token expired or revoked. Please reconnect the integration.',
                properties: [
                    'provider' => 'gsc',
                    'error' => $e->getMessage(),
                ],
            );

            return ['success' => false, 'records_synced' => 0, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error(sprintf(
                'Unexpected error syncing Google Search Console for project %d: %s',
                $project->id,
                $e->getMessage(),
            ));

            return ['success' => false, 'records_synced' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Synchronize query search performance from Bing Webmaster Tools.
     *
     * @return array{success: bool, records_synced: int, error?: string}
     */
    public function syncBingWebmaster(ProjectIntegration $integration): array
    {
        $project = $integration->project;
        if ($project === null) {
            return ['success' => false, 'records_synced' => 0, 'error' => 'Project not found'];
        }

        try {
            $rows = $this->bingClient->getQueryStats($integration);

            $recordsToUpsert = [];
            $now = Carbon::now();

            foreach ($rows as $row) {
                $query = trim($row['query']);
                $dateStr = trim($row['date']);

                if ($query === '' || $dateStr === '') {
                    continue;
                }

                $safeQuery = mb_substr($query, 0, 255);

                $recordsToUpsert[] = [
                    'project_id' => $project->id,
                    'provider' => 'bing',
                    'query' => $safeQuery,
                    'date' => $dateStr,
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions'],
                    'ctr' => $row['ctr'],
                    'position' => $row['position'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($recordsToUpsert !== []) {
                foreach (array_chunk($recordsToUpsert, 500) as $chunk) {
                    SearchPerformanceQuery::query()->upsert(
                        $chunk,
                        ['project_id', 'provider', 'query', 'date'],
                        ['clicks', 'impressions', 'ctr', 'position', 'updated_at'],
                    );
                }
            }

            $count = count($recordsToUpsert);

            $integration->update([
                'status' => 'connected',
                'last_synced_at' => $now,
            ]);

            ProjectActivity::log(
                project: $project,
                eventType: 'integration.synced',
                title: 'Bing Webmaster Tools Synced',
                description: "Synchronized {$count} query performance records from Bing Webmaster Tools.",
                properties: [
                    'provider' => 'bing',
                    'count' => $count,
                ],
            );

            return ['success' => true, 'records_synced' => $count];
        } catch (SearchConsoleAuthException $e) {
            $integration->markNeedsReconnect($e->getMessage());

            Log::warning(sprintf(
                'Bing Webmaster auth error for project %d: %s',
                $project->id,
                $e->getMessage(),
            ));

            ProjectActivity::log(
                project: $project,
                eventType: 'integration.error',
                title: 'Bing Webmaster Authorization Expired',
                description: 'Bing Webmaster API key invalid or unauthorized. Please update integration credentials.',
                properties: [
                    'provider' => 'bing',
                    'error' => $e->getMessage(),
                ],
            );

            return ['success' => false, 'records_synced' => 0, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error(sprintf(
                'Unexpected error syncing Bing Webmaster for project %d: %s',
                $project->id,
                $e->getMessage(),
            ));

            return ['success' => false, 'records_synced' => 0, 'error' => $e->getMessage()];
        }
    }
}
