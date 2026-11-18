<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Actions;

use App\Domains\Crawls\DTOs\CrawlSyncPayload;
use App\Domains\Crawls\Events\CrawlProgressUpdated;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Support\Facades\DB;

class IngestDesktopCrawlSnapshot
{
    public function upsertRun(Project $project, CrawlSyncPayload $payload): CrawlRun
    {
        /** @var CrawlRun $run */
        $run = CrawlRun::query()->updateOrCreate(
            ['external_crawl_id' => $payload->externalCrawlId],
            [
                'project_id' => $project->id,
                'source' => $payload->source,
                'seed' => $payload->seed,
                'seed_kind' => $payload->seedKind,
                'status' => $payload->status,
                'config' => $payload->config,
                'total_urls' => $payload->totalUrls,
                'client_created_at_ms' => $payload->createdAtMs,
                'client_started_at_ms' => $payload->startedAtMs,
                'client_finished_at_ms' => $payload->finishedAtMs,
            ],
        );

        return $run;
    }

    /**
     * Ingest one chunk of camelCase `UrlRecord`, `LinkRecord`, and `Issue` items.
     *
     * @param  list<array<string, mixed>>  $urls
     * @param  list<array<string, mixed>>  $links
     * @param  list<array<string, mixed>>  $issues
     * @return array{urls: int, links: int, issues: int}
     */
    public function ingestChunk(CrawlRun $run, array $urls, array $links, array $issues): array
    {
        $now = now();

        DB::transaction(function () use ($run, $urls, $links, $issues, $now): void {
            if ($urls !== []) {
                $urlRows = array_map(static function (array $row) use ($run, $now): array {
                    $urlString = (string) $row['url'];

                    return [
                        'crawl_run_id' => $run->id,
                        'client_row_id' => isset($row['id']) ? (int) $row['id'] : null,
                        'url' => $urlString,
                        'url_hash' => hash('sha256', $urlString),
                        'status' => isset($row['status']) ? (int) $row['status'] : null,
                        'content_type' => $row['contentType'] ?? null,
                        'title' => $row['title'] ?? null,
                        'meta_description' => $row['metaDescription'] ?? null,
                        'h1' => $row['h1'] ?? null,
                        'h1_count' => (int) ($row['h1Count'] ?? 0),
                        'canonical' => $row['canonical'] ?? null,
                        'meta_robots' => $row['metaRobots'] ?? null,
                        'redirect_target' => $row['redirectTarget'] ?? null,
                        'redirect_chain' => json_encode($row['redirectChain'] ?? [], JSON_THROW_ON_ERROR),
                        'word_count' => isset($row['wordCount']) ? (int) $row['wordCount'] : null,
                        'depth' => (int) ($row['depth'] ?? 0),
                        'response_time_ms' => isset($row['responseTimeMs']) ? (int) $row['responseTimeMs'] : null,
                        'size_bytes' => isset($row['sizeBytes']) ? (int) $row['sizeBytes'] : null,
                        'error' => $row['error'] ?? null,
                        'discovered_at_ms' => isset($row['discoveredAt']) ? (int) $row['discoveredAt'] : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $urls);

                DB::table('crawl_urls')->upsert(
                    $urlRows,
                    ['crawl_run_id', 'url_hash'],
                    [
                        'client_row_id',
                        'status',
                        'content_type',
                        'title',
                        'meta_description',
                        'h1',
                        'h1_count',
                        'canonical',
                        'meta_robots',
                        'redirect_target',
                        'redirect_chain',
                        'word_count',
                        'depth',
                        'response_time_ms',
                        'size_bytes',
                        'error',
                        'discovered_at_ms',
                        'updated_at',
                    ],
                );
            }

            if ($links !== []) {
                $linkRows = array_map(static fn (array $row): array => [
                    'crawl_run_id' => $run->id,
                    'from_url' => (string) $row['fromUrl'],
                    'to_url' => (string) $row['toUrl'],
                    'anchor' => $row['anchor'] ?? null,
                    'rel' => $row['rel'] ?? null,
                    'internal' => (bool) ($row['internal'] ?? true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $links);

                foreach (array_chunk($linkRows, 500) as $batch) {
                    DB::table('crawl_links')->insert($batch);
                }
            }

            if ($issues !== []) {
                $issueRows = array_map(static function (array $row) use ($run, $now): array {
                    $urlString = (string) $row['url'];

                    return [
                        'crawl_run_id' => $run->id,
                        'url' => $urlString,
                        'url_hash' => hash('sha256', $urlString),
                        'rule' => (string) $row['rule'],
                        'severity' => (string) $row['severity'],
                        'detail' => $row['detail'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $issues);

                DB::table('crawl_issues')->upsert(
                    $issueRows,
                    ['crawl_run_id', 'url_hash', 'rule'],
                    ['severity', 'detail', 'updated_at'],
                );
            }
        });

        $crawledCount = (int) DB::table('crawl_urls')->where('crawl_run_id', $run->id)->count();
        $errorCount = (int) DB::table('crawl_issues')->where('crawl_run_id', $run->id)->where('severity', 'error')->count();
        $warningCount = (int) DB::table('crawl_issues')->where('crawl_run_id', $run->id)->where('severity', 'warning')->count();
        $opportunityCount = (int) DB::table('crawl_issues')->where('crawl_run_id', $run->id)->where('severity', 'opportunity')->count();

        rescue(fn () => broadcast(new CrawlProgressUpdated(
            projectId: $run->project_id,
            externalCrawlId: $run->external_crawl_id,
            crawledUrls: $crawledCount,
            totalUrls: (int) ($run->total_urls ?: $crawledCount),
            errorCount: $errorCount,
            warningCount: $warningCount,
            opportunityCount: $opportunityCount,
            status: 'running',
        )), report: true);

        return [
            'urls' => count($urls),
            'links' => count($links),
            'issues' => count($issues),
        ];
    }

    public function finalize(CrawlRun $run, ?string $status = null): CrawlRun
    {
        $totalUrls = DB::table('crawl_urls')->where('crawl_run_id', $run->id)->count();
        $counts = DB::table('crawl_issues')
            ->selectRaw('severity, count(*) as aggregate')
            ->where('crawl_run_id', $run->id)
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');

        $run->update([
            'status' => $status ?? 'completed',
            'total_urls' => $totalUrls,
            'error_count' => (int) ($counts['error'] ?? 0),
            'warning_count' => (int) ($counts['warning'] ?? 0),
            'opportunity_count' => (int) ($counts['opportunity'] ?? 0),
            'finalized_at' => now(),
        ]);

        $run->project()->withTrashed()->update(['last_crawled_at' => now()]);

        $refreshed = $run->refresh();

        rescue(fn () => broadcast(new CrawlProgressUpdated(
            projectId: $refreshed->project_id,
            externalCrawlId: $refreshed->external_crawl_id,
            crawledUrls: (int) $refreshed->total_urls,
            totalUrls: (int) $refreshed->total_urls,
            errorCount: (int) $refreshed->error_count,
            warningCount: (int) $refreshed->warning_count,
            opportunityCount: (int) $refreshed->opportunity_count,
            status: $refreshed->status,
        )), report: true);

        $project = $refreshed->project;
        if ($project !== null) {
            ProjectActivity::log(
                project: $project,
                eventType: 'crawl.completed',
                title: 'Crawl run completed',
                description: "Crawl run finalized with {$refreshed->total_urls} URLs and {$refreshed->error_count} errors.",
                properties: [
                    'crawl_id' => $refreshed->id,
                    'external_crawl_id' => $refreshed->external_crawl_id,
                    'total_urls' => $refreshed->total_urls,
                    'error_count' => $refreshed->error_count,
                    'warning_count' => $refreshed->warning_count,
                    'opportunity_count' => $refreshed->opportunity_count,
                    'source' => $refreshed->source,
                ]
            );
        }

        return $refreshed;
    }
}
