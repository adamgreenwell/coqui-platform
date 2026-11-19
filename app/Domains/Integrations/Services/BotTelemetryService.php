<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\BotCrawlLog;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    /**
     * Ingest a batch of bot crawl log summaries from Runnr edge/server logs.
     *
     * @param  list<array{
     *     botFamily: string,
     *     statusBucket: int,
     *     hitCount: int,
     *     bytesServed?: int|null,
     *     bucketHour: string,
     *     topErrorPaths?: list<array{path: string, hits: int, status?: int|null}>|null,
     * }>  $entries
     * @return array{ingestedCount: int, totalHits: int, project_id: int}
     */
    public function ingest(string $runnrSiteId, array $entries): array
    {
        /** @var Project $project */
        $project = Project::query()->where('runnr_site_id', $runnrSiteId)->firstOrFail();

        $totalHits = 0;
        $flagged404Hits = 0;
        $top404Path = null;

        DB::transaction(function () use ($project, $entries, &$totalHits, &$flagged404Hits, &$top404Path): void {
            foreach ($entries as $entry) {
                $botFamily = strtolower(trim($entry['botFamily']));
                $statusBucket = (int) $entry['statusBucket'];
                // Normalize statusBucket to hundreds (200, 300, 400, 500)
                if ($statusBucket >= 200 && $statusBucket < 300) {
                    $statusBucket = 200;
                } elseif ($statusBucket >= 300 && $statusBucket < 400) {
                    $statusBucket = 300;
                } elseif ($statusBucket >= 400 && $statusBucket < 500) {
                    $statusBucket = 400;
                } elseif ($statusBucket >= 500) {
                    $statusBucket = 500;
                }

                $hitCount = max(0, (int) $entry['hitCount']);
                $bytesServed = max(0, (int) ($entry['bytesServed'] ?? 0));
                $bucketHour = Carbon::parse($entry['bucketHour'])->startOfHour();
                $topErrorPaths = $entry['topErrorPaths'] ?? [];

                $totalHits += $hitCount;

                if ($botFamily === 'googlebot' && $statusBucket === 400 && $topErrorPaths !== []) {
                    foreach ($topErrorPaths as $err) {
                        $errHits = (int) ($err['hits'] ?? 0);
                        if ($errHits > $flagged404Hits) {
                            $flagged404Hits = $errHits;
                            $top404Path = (string) ($err['path'] ?? '');
                        }
                    }
                }

                /** @var BotCrawlLog|null $existing */
                $existing = BotCrawlLog::query()
                    ->where('project_id', $project->id)
                    ->where('bot_family', $botFamily)
                    ->where('status_bucket', $statusBucket)
                    ->where('bucket_hour', $bucketHour)
                    ->first();

                if ($existing !== null) {
                    $mergedErrors = $this->mergeErrorPaths($existing->top_error_paths, $topErrorPaths);
                    $existing->update([
                        'hit_count' => $existing->hit_count + $hitCount,
                        'bytes_served' => $existing->bytes_served + $bytesServed,
                        'top_error_paths' => $mergedErrors,
                    ]);
                } else {
                    BotCrawlLog::query()->create([
                        'project_id' => $project->id,
                        'bot_family' => $botFamily,
                        'status_bucket' => $statusBucket,
                        'hit_count' => $hitCount,
                        'bytes_served' => $bytesServed,
                        'bucket_hour' => $bucketHour,
                        'top_error_paths' => $topErrorPaths,
                    ]);
                }
            }
        });

        // Trigger real-time activity alert when Googlebot repeatedly hits 404 pages
        if ($top404Path !== null && $flagged404Hits >= 5) {
            ProjectActivity::log(
                project: $project,
                eventType: 'telemetry.bot_404_detected',
                title: 'High-frequency 404 detected on Googlebot',
                description: "Googlebot hit {$top404Path} {$flagged404Hits} times. Recommended for 301 redirect remediation.",
                properties: [
                    'path' => $top404Path,
                    'hits' => $flagged404Hits,
                    'bot' => 'googlebot',
                ],
            );
        }

        return [
            'ingestedCount' => count($entries),
            'totalHits' => $totalHits,
            'project_id' => $project->id,
        ];
    }

    /**
     * Retrieve aggregated crawl budget metrics, daily timeseries, bot breakdowns, and 404 remediation list.
     *
     * @return array{
     *     kpis: array{
     *         totalHits: int,
     *         searchEngineHits: int,
     *         aiCrawlerHits: int,
     *         wastedHits: int,
     *         wastePercentage: float,
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
        $status2xx = 0;
        $status3xx = 0;
        $status4xx = 0;
        $status5xx = 0;
        $bytesServed = 0;

        /** @var array<string, array{searchEngineHits: int, aiCrawlerHits: int, wastedHits: int, totalHits: int}> $dailyMap */
        $dailyMap = [];

        // Prepopulate all days in range
        for ($i = $days - 1; $i >= 0; $i--) {
            $dateStr = now()->subDays($i)->format('Y-m-d');
            $dailyMap[$dateStr] = [
                'searchEngineHits' => 0,
                'aiCrawlerHits' => 0,
                'wastedHits' => 0,
                'totalHits' => 0,
            ];
        }

        /** @var array<string, array{
         *     botFamily: string,
         *     category: string,
         *     hits: int,
         *     status2xx: int,
         *     status3xx: int,
         *     status4xx: int,
         *     status5xx: int,
         *     bytesServed: int,
         * }> $botMap */
        $botMap = [];

        /** @var array<string, array{hits: int, lastHitAt: CarbonInterface, bots: array<string, bool>}> $errorPathMap */
        $errorPathMap = [];

        foreach ($logs as $log) {
            $hits = $log->hit_count;
            $totalHits += $hits;
            $bytesServed += $log->bytes_served;

            $bot = strtolower($log->bot_family);
            $isSearch = in_array($bot, self::SEARCH_ENGINE_BOTS, true);
            $isAi = in_array($bot, self::AI_CRAWLER_BOTS, true);

            if ($isSearch) {
                $searchEngineHits += $hits;
                $category = 'search_engine';
            } elseif ($isAi) {
                $aiCrawlerHits += $hits;
                $category = 'ai_crawler';
            } else {
                $category = 'other';
            }

            $bucket = $log->status_bucket;
            $isWaste = in_array($bucket, [400, 500], true);

            if ($bucket === 200) {
                $status2xx += $hits;
            } elseif ($bucket === 300) {
                $status3xx += $hits;
            } elseif ($bucket === 400) {
                $status4xx += $hits;
            } elseif ($bucket === 500) {
                $status5xx += $hits;
            }

            // Daily timeseries accumulation
            $date = $log->bucket_hour->format('Y-m-d');
            if (! isset($dailyMap[$date])) {
                $dailyMap[$date] = [
                    'searchEngineHits' => 0,
                    'aiCrawlerHits' => 0,
                    'wastedHits' => 0,
                    'totalHits' => 0,
                ];
            }

            $dailyMap[$date]['totalHits'] += $hits;
            if ($isSearch) {
                $dailyMap[$date]['searchEngineHits'] += $hits;
            }
            if ($isAi) {
                $dailyMap[$date]['aiCrawlerHits'] += $hits;
            }
            if ($isWaste) {
                $dailyMap[$date]['wastedHits'] += $hits;
            }

            // Bot family breakdown accumulation
            if (! isset($botMap[$bot])) {
                $botMap[$bot] = [
                    'botFamily' => $bot,
                    'category' => $category,
                    'hits' => 0,
                    'status2xx' => 0,
                    'status3xx' => 0,
                    'status4xx' => 0,
                    'status5xx' => 0,
                    'bytesServed' => 0,
                ];
            }
            $botMap[$bot]['hits'] += $hits;
            $botMap[$bot]['bytesServed'] += $log->bytes_served;
            if ($bucket === 200) {
                $botMap[$bot]['status2xx'] += $hits;
            } elseif ($bucket === 300) {
                $botMap[$bot]['status3xx'] += $hits;
            } elseif ($bucket === 400) {
                $botMap[$bot]['status4xx'] += $hits;
            } elseif ($bucket === 500) {
                $botMap[$bot]['status5xx'] += $hits;
            }

            // 404 paths extraction
            if ($bucket === 400 && is_array($log->top_error_paths)) {
                /** @var list<array<string, mixed>> $paths */
                $paths = $log->top_error_paths;
                foreach ($paths as $p) {
                    $pathStr = (string) ($p['path'] ?? '');
                    if ($pathStr === '') {
                        continue;
                    }
                    $pHits = (int) ($p['hits'] ?? 1);
                    if (! isset($errorPathMap[$pathStr])) {
                        $errorPathMap[$pathStr] = [
                            'hits' => 0,
                            'lastHitAt' => $log->bucket_hour,
                            'bots' => [],
                        ];
                    }
                    $errorPathMap[$pathStr]['hits'] += $pHits;
                    $errorPathMap[$pathStr]['bots'][$bot] = true;
                    if ($log->bucket_hour > $errorPathMap[$pathStr]['lastHitAt']) {
                        $errorPathMap[$pathStr]['lastHitAt'] = $log->bucket_hour;
                    }
                }
            }
        }

        $wastedHits = $status4xx + $status5xx;
        $wastePercentage = $totalHits > 0 ? round(($wastedHits / $totalHits) * 100, 1) : 0.0;
        $errorRate = $totalHits > 0 ? round((($status4xx + $status5xx) / $totalHits) * 100, 1) : 0.0;

        /** @var list<array{date: string, searchEngineHits: int, aiCrawlerHits: int, wastedHits: int, totalHits: int}> $dailyTimeseries */
        $dailyTimeseries = [];
        foreach ($dailyMap as $dt => $vals) {
            $dailyTimeseries[] = [
                'date' => $dt,
                'searchEngineHits' => $vals['searchEngineHits'],
                'aiCrawlerHits' => $vals['aiCrawlerHits'],
                'wastedHits' => $vals['wastedHits'],
                'totalHits' => $vals['totalHits'],
            ];
        }

        /** @var list<array{botFamily: string, category: string, hits: int, percent: float, status2xx: int, status3xx: int, status4xx: int, status5xx: int, errorRate: float, bytesServed: int}> $botFamilyBreakdown */
        $botFamilyBreakdown = [];
        foreach ($botMap as $b) {
            $bHits = $b['hits'];
            $bErrors = $b['status4xx'] + $b['status5xx'];
            $botFamilyBreakdown[] = [
                'botFamily' => $b['botFamily'],
                'category' => $b['category'],
                'hits' => $bHits,
                'percent' => $totalHits > 0 ? round(($bHits / $totalHits) * 100, 1) : 0.0,
                'status2xx' => $b['status2xx'],
                'status3xx' => $b['status3xx'],
                'status4xx' => $b['status4xx'],
                'status5xx' => $b['status5xx'],
                'errorRate' => $bHits > 0 ? round(($bErrors / $bHits) * 100, 1) : 0.0,
                'bytesServed' => $b['bytesServed'],
            ];
        }
        usort($botFamilyBreakdown, fn ($a, $b) => $b['hits'] <=> $a['hits']);

        // Flagged 404 paths for redirect remediation
        /** @var list<array{path: string, hits: int, lastHitAt: string, bots: list<string>, recommendedTarget: string}> $flagged404s */
        $flagged404s = [];
        foreach ($errorPathMap as $pathKey => $data) {
            $flagged404s[] = [
                'path' => $pathKey,
                'hits' => $data['hits'],
                'lastHitAt' => $data['lastHitAt']->toIso8601String(),
                'bots' => array_keys($data['bots']),
                'recommendedTarget' => $this->suggestRedirectTarget($pathKey),
            ];
        }
        usort($flagged404s, fn ($a, $b) => $b['hits'] <=> $a['hits']);

        return [
            'kpis' => [
                'totalHits' => $totalHits,
                'searchEngineHits' => $searchEngineHits,
                'aiCrawlerHits' => $aiCrawlerHits,
                'wastedHits' => $wastedHits,
                'wastePercentage' => $wastePercentage,
                'bytesServed' => $bytesServed,
                'errorRate' => $errorRate,
            ],
            'dailyTimeseries' => $dailyTimeseries,
            'botFamilyBreakdown' => $botFamilyBreakdown,
            'statusBreakdown' => [
                'status2xx' => $status2xx,
                'status3xx' => $status3xx,
                'status4xx' => $status4xx,
                'status5xx' => $status5xx,
            ],
            'flagged404s' => array_slice($flagged404s, 0, 50),
        ];
    }

    /**
     * Merge existing and new error path lists.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>|null  $existing
     * @param  list<array{path: string, hits: int, status?: int|null}>  $incoming
     * @return list<array{path: string, hits: int, status: int}>
     */
    private function mergeErrorPaths(mixed $existing, array $incoming): array
    {
        /** @var array<string, array{path: string, hits: int, status: int}> $map */
        $map = [];

        if (is_array($existing)) {
            /** @var list<array<string, mixed>> $existingList */
            $existingList = $existing;
            foreach ($existingList as $item) {
                $p = (string) ($item['path'] ?? '');
                if ($p !== '') {
                    $map[$p] = [
                        'path' => $p,
                        'hits' => (int) ($item['hits'] ?? 0),
                        'status' => (int) ($item['status'] ?? 404),
                    ];
                }
            }
        }

        foreach ($incoming as $item) {
            $p = (string) ($item['path'] ?? '');
            if ($p !== '') {
                if (isset($map[$p])) {
                    $map[$p]['hits'] += (int) ($item['hits'] ?? 1);
                } else {
                    $map[$p] = [
                        'path' => $p,
                        'hits' => (int) ($item['hits'] ?? 1),
                        'status' => (int) ($item['status'] ?? 404),
                    ];
                }
            }
        }

        return array_values($map);
    }

    /**
     * Suggest a reasonable redirect target for a dead 404 path based on path structure.
     */
    private function suggestRedirectTarget(string $path): string
    {
        $trimmed = trim($path, '/');
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
