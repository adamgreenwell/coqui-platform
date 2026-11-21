<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\BotCrawlLog;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BotTelemetryService
{
    public const array SEARCH_ENGINE_BOTS = [
        'googlebot',
        'bingbot',
        'yandexbot',
        'baiduspider',
        'duckduckbot',
    ];

    public const array AI_CRAWLER_BOTS = [
        'gptbot',
        'chatgpt-user',
        'claudebot',
        'anthropic-ai',
        'perplexitybot',
        'cohere-ai',
        'applebot-extended',
        'bytespider',
        'meta-externalagent',
    ];

    /** Statuses that mean the page is gone, for the 404 remediation list. */
    public const array GONE_STATUSES = [404, 410];

    /** Paths kept per stored row. */
    private const int PATHS_PER_ROW = 100;

    /**
     * Ingest a batch of hourly bot crawl summaries from Runnr access logs.
     *
     * Each batch is stored under its batch ID (or, without one, a hash of
     * its contents). Sending the same batch again replaces its own rows, so
     * a retry never counts twice; different batches for the same hour add
     * up.
     *
     * @param  list<array{
     *     botFamily: string,
     *     statusBucket: int,
     *     hitCount: int,
     *     bytesServed?: int|null,
     *     parameterHitCount?: int|null,
     *     bucketHour: string,
     *     topPaths?: list<array{path: string, hits: int, status?: int|null, redirectTarget?: string|null, hops?: int|null}>|null,
     *     topErrorPaths?: list<array{path: string, hits: int, status?: int|null}>|null,
     * }>  $entries
     * @return array{ingestedCount: int, totalHits: int, project_id: int, duplicate: bool}
     */
    public function ingest(string $runnrSiteId, array $entries, ?string $batchId = null): array
    {
        /** @var Project $project */
        $project = Project::query()->where('runnr_site_id', $runnrSiteId)->firstOrFail();

        $batchKey = $this->batchKey($batchId, $entries);
        $buckets = $this->buckets($entries);
        $totalHits = array_sum(array_column($buckets, 'hit_count'));

        try {
            $duplicate = DB::transaction(function () use ($project, $batchKey, $buckets): bool {
                // Two copies of a batch with no bucket in common would both
                // pass the unique key and store their union. Serialize each
                // project's batch until commit; SQLite already serializes
                // writers.
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["bot_crawl_logs:{$project->id}:{$batchKey}"]);
                }

                $previous = BotCrawlLog::query()
                    ->where('project_id', $project->id)
                    ->where('batch_id', $batchKey)
                    ->delete();

                foreach ($buckets as $bucket) {
                    BotCrawlLog::query()->create([
                        ...$bucket,
                        'project_id' => $project->id,
                        'batch_id' => $batchKey,
                    ]);
                }

                return $previous > 0;
            });
        } catch (UniqueConstraintViolationException) {
            // The same batch arrived twice at once and the other copy won.
            $duplicate = true;
        }

        // Alert once per batch when Googlebot keeps hitting a missing page.
        if (! $duplicate) {
            $this->alertOnGooglebot404s($project, $buckets);
        }

        return [
            'ingestedCount' => count($entries),
            'totalHits' => (int) $totalHits,
            'project_id' => $project->id,
            'duplicate' => $duplicate,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function batchKey(?string $batchId, array $entries): string
    {
        $batchId = trim((string) $batchId);
        if ($batchId !== '') {
            return sha1('id:'.$batchId);
        }

        return sha1('content:'.json_encode($entries, JSON_THROW_ON_ERROR));
    }

    /**
     * Combine the batch's entries into one row per bot, status class and
     * hour.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{bot_family: string, status_bucket: int, hit_count: int, bytes_served: int, parameter_hits: int, bucket_hour: CarbonInterface, top_paths: list<array{path: string, hits: int, status: int, redirectTarget?: string, hops?: int}>}>
     */
    private function buckets(array $entries): array
    {
        /** @var array<string, array{bot_family: string, status_bucket: int, hit_count: int, bytes_served: int, parameter_hits: int, bucket_hour: CarbonInterface, paths: array<string, array{path: string, hits: int, status: int, redirectTarget?: string, hops?: int}>}> $map */
        $map = [];

        foreach ($entries as $entry) {
            $bot = strtolower(trim((string) $entry['botFamily']));
            $rawStatus = (int) $entry['statusBucket'];
            $statusBucket = self::statusClass($rawStatus);
            $hour = Carbon::parse((string) $entry['bucketHour'])->utc()->startOfHour();
            $hits = max(0, (int) $entry['hitCount']);

            $key = $bot.'|'.$statusBucket.'|'.$hour->toIso8601String();
            $map[$key] ??= [
                'bot_family' => $bot,
                'status_bucket' => $statusBucket,
                'hit_count' => 0,
                'bytes_served' => 0,
                'parameter_hits' => 0,
                'bucket_hour' => $hour,
                'paths' => [],
            ];

            $map[$key]['hit_count'] += $hits;
            $map[$key]['bytes_served'] += max(0, (int) ($entry['bytesServed'] ?? 0));

            // topErrorPaths is the older field for the same data; a producer
            // sending both repeats its error paths, so prefer topPaths.
            /** @var list<array<string, mixed>> $paths */
            $paths = is_array($entry['topPaths'] ?? null) && $entry['topPaths'] !== []
                ? $entry['topPaths']
                : (is_array($entry['topErrorPaths'] ?? null) ? $entry['topErrorPaths'] : []);

            $parameterHits = 0;
            foreach ($paths as $p) {
                $path = trim((string) ($p['path'] ?? ''));
                $pHits = max(0, (int) ($p['hits'] ?? 0));
                if ($path === '' || $pHits === 0) {
                    continue;
                }
                // A path's own status, or the entry's when it gave an exact code.
                $status = isset($p['status']) ? (int) $p['status'] : $rawStatus;
                if (self::isParameterized($path)) {
                    $parameterHits += $pHits;
                }

                $pathKey = $path.'|'.$status;
                $item = $map[$key]['paths'][$pathKey] ?? ['path' => $path, 'hits' => 0, 'status' => $status];
                $item['hits'] += $pHits;
                // Keep redirect details from whichever entry reported them.
                $target = trim((string) ($p['redirectTarget'] ?? ''));
                if ($target !== '' && ! isset($item['redirectTarget'])) {
                    $item['redirectTarget'] = mb_substr($target, 0, 2048);
                }
                $hops = (int) ($p['hops'] ?? 0);
                if ($hops > ($item['hops'] ?? 0)) {
                    $item['hops'] = $hops;
                }
                $map[$key]['paths'][$pathKey] = $item;
            }

            // Runnr's own count covers every parameterized hit, not just the
            // top paths; use it when sent.
            $reported = $entry['parameterHitCount'] ?? null;
            $map[$key]['parameter_hits'] += min($hits, max(0, $reported !== null ? (int) $reported : $parameterHits));
        }

        $buckets = [];
        foreach ($map as $row) {
            $paths = array_values($row['paths']);
            usort($paths, fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);
            unset($row['paths']);
            $row['top_paths'] = array_slice($paths, 0, self::PATHS_PER_ROW);
            $buckets[] = $row;
        }

        return $buckets;
    }

    /**
     * @param  list<array{bot_family: string, top_paths: list<array{path: string, hits: int, status: int}>}>  $buckets
     */
    private function alertOnGooglebot404s(Project $project, array $buckets): void
    {
        $topPath = null;
        $topHits = 0;
        foreach ($buckets as $bucket) {
            if ($bucket['bot_family'] !== 'googlebot') {
                continue;
            }
            foreach ($bucket['top_paths'] as $p) {
                if (in_array($p['status'], self::GONE_STATUSES, true) && $p['hits'] > $topHits) {
                    $topHits = $p['hits'];
                    $topPath = $p['path'];
                }
            }
        }

        if ($topPath === null || $topHits < 5) {
            return;
        }

        ProjectActivity::log(
            project: $project,
            eventType: 'telemetry.bot_404_detected',
            title: 'High-frequency 404 detected on Googlebot',
            description: "Googlebot hit {$topPath} {$topHits} times. Recommended for 301 redirect remediation.",
            properties: [
                'path' => $topPath,
                'hits' => $topHits,
                'bot' => 'googlebot',
            ],
        );
    }

    public static function statusClass(int $status): int
    {
        return match (true) {
            $status >= 500 => 500,
            $status >= 400 => 400,
            $status >= 300 => 300,
            default => 200,
        };
    }

    public static function isParameterized(string $path): bool
    {
        $query = parse_url($path, PHP_URL_QUERY);

        return is_string($query) && $query !== '';
    }

    /**
     * Crawl budget metrics, daily timeseries, bot breakdowns, top crawled
     * paths, redirect chains, parameter waste and the 404 remediation list.
     *
     * Wasted crawl budget is hits on redirects (3xx), errors (4xx/5xx) and
     * parameterized URLs that otherwise returned 2xx.
     *
     * @return array{
     *     kpis: array{
     *         totalHits: int,
     *         searchEngineHits: int,
     *         aiCrawlerHits: int,
     *         wastedHits: int,
     *         wastePercentage: float,
     *         redirectHits: int,
     *         parameterHits: int,
     *         errorHits: int,
     *         bytesServed: int,
     *         errorRate: float,
     *     },
     *     dailyTimeseries: list<array{
     *         date: string,
     *         searchEngineHits: int,
     *         aiCrawlerHits: int,
     *         wastedHits: int,
     *         totalHits: int,
     *     }>,
     *     botFamilyBreakdown: list<array{
     *         botFamily: string,
     *         category: string,
     *         hits: int,
     *         percent: float,
     *         status2xx: int,
     *         status3xx: int,
     *         status4xx: int,
     *         status5xx: int,
     *         errorRate: float,
     *         bytesServed: int,
     *     }>,
     *     statusBreakdown: array{
     *         status2xx: int,
     *         status3xx: int,
     *         status4xx: int,
     *         status5xx: int,
     *     },
     *     topPaths: list<array{path: string, hits: int, status: int, bots: list<string>, parameterized: bool}>,
     *     redirectChains: list<array{path: string, hits: int, hops: int, target: string|null, bots: list<string>}>,
     *     parameterPaths: list<array{path: string, hits: int, status: int, bots: list<string>}>,
     *     flagged404s: list<array{
     *         path: string,
     *         hits: int,
     *         lastHitAt: string,
     *         bots: list<string>,
     *         recommendedTarget: string,
     *     }>,
     * }
     */
    public function getTelemetryDashboard(Project $project, int $days = 14): array
    {
        $since = now()->subDays($days)->startOfDay();

        $logs = BotCrawlLog::query()
            ->where('project_id', $project->id)
            ->where('bucket_hour', '>=', $since)
            ->orderBy('bucket_hour')
            ->get();

        $totalHits = 0;
        $searchEngineHits = 0;
        $aiCrawlerHits = 0;
        $parameterHits = 0;
        $status = [200 => 0, 300 => 0, 400 => 0, 500 => 0];
        $bytesServed = 0;

        /** @var array<string, array{searchEngineHits: int, aiCrawlerHits: int, wastedHits: int, totalHits: int}> $dailyMap */
        $dailyMap = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dailyMap[now()->subDays($i)->format('Y-m-d')] = ['searchEngineHits' => 0, 'aiCrawlerHits' => 0, 'wastedHits' => 0, 'totalHits' => 0];
        }

        /** @var array<string, array{botFamily: string, category: string, hits: int, status: array<int, int>, bytesServed: int}> $botMap */
        $botMap = [];

        /** @var array<string, array{path: string, hits: int, statusHits: array<int, int>, bots: array<string, bool>, lastHitAt: CarbonInterface, target: string|null, hops: int}> $pathMap */
        $pathMap = [];

        foreach ($logs as $log) {
            $hits = $log->hit_count;
            $bucket = self::statusClass($log->status_bucket);
            $bot = strtolower($log->bot_family);
            $isSearch = in_array($bot, self::SEARCH_ENGINE_BOTS, true);
            $isAi = in_array($bot, self::AI_CRAWLER_BOTS, true);
            $category = $isSearch ? 'search_engine' : ($isAi ? 'ai_crawler' : 'other');

            // Parameterized hits are waste only where they didn't already
            // count as a redirect or error.
            $rowParameterHits = $bucket === 200 ? min($hits, $log->parameter_hits) : 0;
            $rowWaste = $bucket === 200 ? $rowParameterHits : $hits;

            $totalHits += $hits;
            $bytesServed += $log->bytes_served;
            $status[$bucket] += $hits;
            $parameterHits += $rowParameterHits;
            $searchEngineHits += $isSearch ? $hits : 0;
            $aiCrawlerHits += $isAi ? $hits : 0;

            $date = $log->bucket_hour->format('Y-m-d');
            $dailyMap[$date] ??= ['searchEngineHits' => 0, 'aiCrawlerHits' => 0, 'wastedHits' => 0, 'totalHits' => 0];
            $dailyMap[$date]['totalHits'] += $hits;
            $dailyMap[$date]['searchEngineHits'] += $isSearch ? $hits : 0;
            $dailyMap[$date]['aiCrawlerHits'] += $isAi ? $hits : 0;
            $dailyMap[$date]['wastedHits'] += $rowWaste;

            $botMap[$bot] ??= ['botFamily' => $bot, 'category' => $category, 'hits' => 0, 'status' => [200 => 0, 300 => 0, 400 => 0, 500 => 0], 'bytesServed' => 0];
            $botMap[$bot]['hits'] += $hits;
            $botMap[$bot]['status'][$bucket] += $hits;
            $botMap[$bot]['bytesServed'] += $log->bytes_served;

            foreach ($log->top_paths ?? [] as $p) {
                $path = (string) ($p['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $pHits = (int) ($p['hits'] ?? 0);
                $pStatus = (int) ($p['status'] ?? $bucket);

                $pathMap[$path] ??= ['path' => $path, 'hits' => 0, 'statusHits' => [], 'bots' => [], 'lastHitAt' => $log->bucket_hour, 'target' => null, 'hops' => 0];
                $pathMap[$path]['hits'] += $pHits;
                $pathMap[$path]['statusHits'][$pStatus] = ($pathMap[$path]['statusHits'][$pStatus] ?? 0) + $pHits;
                $pathMap[$path]['bots'][$bot] = true;
                if ($log->bucket_hour->greaterThan($pathMap[$path]['lastHitAt'])) {
                    $pathMap[$path]['lastHitAt'] = $log->bucket_hour;
                }
                if (isset($p['redirectTarget']) && is_string($p['redirectTarget']) && $p['redirectTarget'] !== '') {
                    $pathMap[$path]['target'] = $p['redirectTarget'];
                }
                $pathMap[$path]['hops'] = max($pathMap[$path]['hops'], (int) ($p['hops'] ?? 0));
            }
        }

        $redirectHits = $status[300];
        $errorHits = $status[400] + $status[500];
        $wastedHits = $redirectHits + $errorHits + $parameterHits;

        $dailyTimeseries = [];
        foreach ($dailyMap as $dt => $vals) {
            $dailyTimeseries[] = ['date' => (string) $dt, ...$vals];
        }

        $botFamilyBreakdown = [];
        foreach ($botMap as $b) {
            $bErrors = $b['status'][400] + $b['status'][500];
            $botFamilyBreakdown[] = [
                'botFamily' => $b['botFamily'],
                'category' => $b['category'],
                'hits' => $b['hits'],
                'percent' => $totalHits > 0 ? round(($b['hits'] / $totalHits) * 100, 1) : 0.0,
                'status2xx' => $b['status'][200],
                'status3xx' => $b['status'][300],
                'status4xx' => $b['status'][400],
                'status5xx' => $b['status'][500],
                'errorRate' => $b['hits'] > 0 ? round(($bErrors / $b['hits']) * 100, 1) : 0.0,
                'bytesServed' => $b['bytesServed'],
            ];
        }
        usort($botFamilyBreakdown, fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);

        uasort($pathMap, fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);

        $topPaths = [];
        $redirectChains = [];
        $parameterPaths = [];
        $flagged404s = [];
        foreach ($pathMap as $path => $data) {
            arsort($data['statusHits']);
            $mainStatus = (int) array_key_first($data['statusHits']);
            $bots = array_keys($data['bots']);
            $parameterized = self::isParameterized($path);

            if (count($topPaths) < 50) {
                $topPaths[] = ['path' => $path, 'hits' => $data['hits'], 'status' => $mainStatus, 'bots' => $bots, 'parameterized' => $parameterized];
            }

            if ($parameterized && count($parameterPaths) < 50) {
                $parameterPaths[] = ['path' => $path, 'hits' => $data['hits'], 'status' => $mainStatus, 'bots' => $bots];
            }

            if (self::statusClass($mainStatus) === 300) {
                // A chain: more than one hop, or a redirect to a path that
                // itself redirects.
                $target = $data['target'];
                $targetPath = $target !== null ? $this->pathOf($target) : null;
                $targetRedirects = $targetPath !== null && $targetPath !== $path && isset($pathMap[$targetPath])
                    && $this->redirectHits($pathMap[$targetPath]['statusHits']) > 0;
                $hops = max($data['hops'], $targetRedirects ? 2 : 1);
                if ($hops >= 2 && count($redirectChains) < 50) {
                    $redirectChains[] = ['path' => $path, 'hits' => $data['hits'], 'hops' => $hops, 'target' => $target, 'bots' => $bots];
                }
            }

            $goneHits = 0;
            foreach (self::GONE_STATUSES as $code) {
                $goneHits += $data['statusHits'][$code] ?? 0;
            }
            if ($goneHits > 0) {
                $flagged404s[] = ['path' => $path, 'hits' => $goneHits, 'lastHitAt' => $data['lastHitAt'], 'bots' => $bots];
            }
        }
        // Rank by missing-page hits before limiting, so a path with many
        // 404s isn't pushed out by busier paths with only a few.
        usort($flagged404s, fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);
        $flagged404s = array_map(fn (array $row): array => [
            'path' => $row['path'],
            'hits' => $row['hits'],
            'lastHitAt' => $row['lastHitAt']->toIso8601String(),
            'bots' => $row['bots'],
            'recommendedTarget' => $this->suggestRedirectTarget($row['path']),
        ], array_slice($flagged404s, 0, 50));

        return [
            'kpis' => [
                'totalHits' => $totalHits,
                'searchEngineHits' => $searchEngineHits,
                'aiCrawlerHits' => $aiCrawlerHits,
                'wastedHits' => $wastedHits,
                'wastePercentage' => $totalHits > 0 ? round(($wastedHits / $totalHits) * 100, 1) : 0.0,
                'redirectHits' => $redirectHits,
                'parameterHits' => $parameterHits,
                'errorHits' => $errorHits,
                'bytesServed' => $bytesServed,
                'errorRate' => $totalHits > 0 ? round(($errorHits / $totalHits) * 100, 1) : 0.0,
            ],
            'dailyTimeseries' => $dailyTimeseries,
            'botFamilyBreakdown' => $botFamilyBreakdown,
            'statusBreakdown' => [
                'status2xx' => $status[200],
                'status3xx' => $status[300],
                'status4xx' => $status[400],
                'status5xx' => $status[500],
            ],
            'topPaths' => $topPaths,
            'redirectChains' => $redirectChains,
            'parameterPaths' => $parameterPaths,
            'flagged404s' => $flagged404s,
        ];
    }

    /**
     * @param  array<int, int>  $statusHits
     */
    private function redirectHits(array $statusHits): int
    {
        $hits = 0;
        foreach ($statusHits as $code => $count) {
            if (self::statusClass((int) $code) === 300) {
                $hits += $count;
            }
        }

        return $hits;
    }

    /**
     * The path (and query) of a redirect target, absolute or relative.
     */
    private function pathOf(string $target): ?string
    {
        $parts = parse_url($target);
        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    /**
     * Suggest a reasonable redirect target for a dead 404 path based on path structure.
     */
    private function suggestRedirectTarget(string $path): string
    {
        $trimmed = trim((string) parse_url($path, PHP_URL_PATH), '/');
        if ($trimmed === '') {
            return '/';
        }

        $segments = explode('/', $trimmed);
        if (count($segments) > 1) {
            return '/'.$segments[0];
        }

        return '/';
    }
}
