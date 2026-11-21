<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Models\SearchPerformancePage;
use App\Domains\Integrations\Models\SearchPerformanceQuery;
use App\Domains\Projects\Models\ProjectActivity;
use App\Support\PageUrl;
use Carbon\CarbonInterface;
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
     * @return array{success: bool, records_synced: int, pages_synced?: int, error?: string}
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

            $rows = [];
            $startRow = 0;
            $pageSize = 5000;
            $maxRows = 25000;

            do {
                $pageRows = $this->gscClient->querySearchAnalytics(
                    integration: $integration,
                    startDate: $startDate,
                    endDate: $endDate,
                    dimensions: ['query', 'date'],
                    startRow: $startRow,
                    rowLimit: $pageSize,
                );

                $rows = array_merge($rows, $pageRows);
                $startRow += $pageSize;
            } while (count($pageRows) === $pageSize && count($rows) < $maxRows);

            $recordsToUpsert = [];
            $now = Carbon::now();

            $deduped = [];
            foreach ($rows as $row) {
                $keys = $row['keys'];
                $query = isset($keys[0]) ? trim($keys[0]) : '';
                $dateStr = isset($keys[1]) ? trim($keys[1]) : '';

                if ($query === '' || $dateStr === '') {
                    continue;
                }

                // Truncate query to 255 chars if needed and aggregate collisions on (query, date)
                $safeQuery = mb_substr($query, 0, 255);
                $key = $safeQuery.'|'.$dateStr;

                if (isset($deduped[$key])) {
                    $existingClicks = (int) $deduped[$key]['clicks'] + (int) $row['clicks'];
                    $existingImpressions = (int) $deduped[$key]['impressions'] + (int) $row['impressions'];
                    $combinedCtr = $existingImpressions > 0 ? round($existingClicks / $existingImpressions, 4) : 0.0;
                    $totalPosImpr = ((float) $deduped[$key]['position'] * (int) $deduped[$key]['impressions']) + ((float) $row['position'] * (int) $row['impressions']);
                    $combinedPos = $existingImpressions > 0 ? round($totalPosImpr / $existingImpressions, 2) : (float) $row['position'];

                    $deduped[$key]['clicks'] = $existingClicks;
                    $deduped[$key]['impressions'] = $existingImpressions;
                    $deduped[$key]['ctr'] = $combinedCtr;
                    $deduped[$key]['position'] = $combinedPos;

                    continue;
                }

                $deduped[$key] = [
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

            $recordsToUpsert = array_values($deduped);

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

            // Page-level clicks and impressions feed traffic attribution and
            // the crawl comparison's traffic overlay.
            $pageCount = $this->syncGooglePages($integration, $startDate, $endDate, $now);

            $integration->update([
                'status' => 'connected',
                'last_synced_at' => $now,
            ]);

            ProjectActivity::log(
                project: $project,
                eventType: 'integration.synced',
                title: 'Google Search Console Synced',
                description: "Synchronized {$count} query and {$pageCount} page performance records from Google Search Console.",
                properties: [
                    'provider' => 'gsc',
                    'count' => $count,
                    'pages' => $pageCount,
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
            );

            return ['success' => true, 'records_synced' => $count, 'pages_synced' => $pageCount];
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
     * Fetch page-level performance (page, date) and store it in
     * search_performance_pages, keyed by the normalized path so URL variants
     * of one page are combined and rows join to crawls and traffic data.
     * Returns how many rows were stored.
     */
    private function syncGooglePages(ProjectIntegration $integration, CarbonInterface $startDate, CarbonInterface $endDate, CarbonInterface $now): int
    {
        $rows = [];
        $startRow = 0;
        $pageSize = 5000;
        $maxRows = 25000;

        do {
            $pageRows = $this->gscClient->querySearchAnalytics(
                integration: $integration,
                startDate: $startDate,
                endDate: $endDate,
                dimensions: ['page', 'date'],
                startRow: $startRow,
                rowLimit: $pageSize,
            );

            $rows = array_merge($rows, $pageRows);
            $startRow += $pageSize;
        } while (count($pageRows) === $pageSize && count($rows) < $maxRows);

        /** @var array<string, array<string, mixed>> $deduped */
        $deduped = [];
        foreach ($rows as $row) {
            $url = isset($row['keys'][0]) ? trim((string) $row['keys'][0]) : '';
            $dateStr = isset($row['keys'][1]) ? trim((string) $row['keys'][1]) : '';
            if ($url === '' || $dateStr === '') {
                continue;
            }

            $hash = PageUrl::hash($url);
            $key = $hash.'|'.$dateStr;
            $clicks = (int) $row['clicks'];
            $impressions = (int) $row['impressions'];

            if (isset($deduped[$key])) {
                // http/https, www and trailing-slash variants of one page.
                $totalImpressions = (int) $deduped[$key]['impressions'] + $impressions;
                $weightedPosition = ((float) $deduped[$key]['position'] * (int) $deduped[$key]['impressions']) + ((float) $row['position'] * $impressions);

                $deduped[$key]['clicks'] = (int) $deduped[$key]['clicks'] + $clicks;
                $deduped[$key]['impressions'] = $totalImpressions;
                $deduped[$key]['ctr'] = $totalImpressions > 0 ? round($deduped[$key]['clicks'] / $totalImpressions, 4) : 0.0;
                $deduped[$key]['position'] = $totalImpressions > 0 ? round($weightedPosition / $totalImpressions, 2) : (float) $row['position'];

                continue;
            }

            $deduped[$key] = [
                'project_id' => $integration->project_id,
                'provider' => 'gsc',
                'url' => $url,
                'url_hash' => $hash,
                'date' => $dateStr,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => round((float) $row['ctr'], 4),
                'position' => round((float) $row['position'], 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($deduped), 500) as $chunk) {
            SearchPerformancePage::query()->upsert(
                $chunk,
                ['project_id', 'provider', 'url_hash', 'date'],
                ['url', 'clicks', 'impressions', 'ctr', 'position', 'updated_at'],
            );
        }

        return count($deduped);
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

            $deduped = [];
            foreach ($rows as $row) {
                $query = trim($row['query']);
                $dateStr = trim($row['date']);

                if ($query === '' || $dateStr === '') {
                    continue;
                }

                $safeQuery = mb_substr($query, 0, 255);
                $key = $safeQuery.'|'.$dateStr;

                if (isset($deduped[$key])) {
                    $existingClicks = (int) $deduped[$key]['clicks'] + (int) $row['clicks'];
                    $existingImpressions = (int) $deduped[$key]['impressions'] + (int) $row['impressions'];
                    $combinedCtr = $existingImpressions > 0 ? round($existingClicks / $existingImpressions, 4) : 0.0;
                    $totalPosImpr = ((float) $deduped[$key]['position'] * (int) $deduped[$key]['impressions']) + ((float) $row['position'] * (int) $row['impressions']);
                    $combinedPos = $existingImpressions > 0 ? round($totalPosImpr / $existingImpressions, 2) : (float) $row['position'];

                    $deduped[$key]['clicks'] = $existingClicks;
                    $deduped[$key]['impressions'] = $existingImpressions;
                    $deduped[$key]['ctr'] = $combinedCtr;
                    $deduped[$key]['position'] = $combinedPos;

                    continue;
                }

                $deduped[$key] = [
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

            $recordsToUpsert = array_values($deduped);

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
