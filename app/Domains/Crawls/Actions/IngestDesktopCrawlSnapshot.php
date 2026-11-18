<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Actions;

use App\Domains\Crawls\DTOs\CrawlSyncPayload;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
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

        $run->project()->update(['last_crawled_at' => now()]);

        return $run->refresh();
    }
}
