<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Models\CrawlUrl;
use App\Domains\Integrations\Models\PageTrafficSnapshot;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Models\SearchPerformancePage;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrafficAttributionService
{
    public function __construct(
        private readonly Ga4AnalyticsClient $ga4Client,
        private readonly PlausibleAnalyticsClient $plausibleClient,
    ) {}

    /**
     * Normalize URL or path into a canonical relative path.
     * E.g. "https://example.com/features/?utm=1" -> "/features"
     */
    public function normalizePath(string $urlOrPath): string
    {
        $raw = trim($urlOrPath);
        if ($raw === '') {
            return '/';
        }

        $parsedPath = parse_url($raw, PHP_URL_PATH);
        $path = is_string($parsedPath) ? $parsedPath : $raw;

        // Strip query parameters and anchors if raw was just "/path?foo=1"
        $path = explode('?', $path)[0];
        $path = explode('#', $path)[0];
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        // Ensure leading slash
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        // Remove trailing slash
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Compute deterministic hash for a normalized path.
     */
    public function hashPath(string $urlOrPath): string
    {
        return hash('sha256', $this->normalizePath($urlOrPath));
    }

    /**
     * Classify an incoming referral source into an AI assistant brand.
     */
    public function classifyAiAssistant(string $source): ?string
    {
        $clean = strtolower(trim($source));
        if ($clean === '') {
            return null;
        }

        if (preg_match('/(chatgpt|openai)/i', $clean) === 1) {
            return 'ChatGPT';
        }

        if (preg_match('/perplexity/i', $clean) === 1) {
            return 'Perplexity';
        }

        if (preg_match('/(claude|anthropic)/i', $clean) === 1) {
            return 'Claude';
        }

        if (preg_match('/(gemini|bard)/i', $clean) === 1) {
            return 'Gemini';
        }

        if (preg_match('/(copilot|bing\.com\/chat)/i', $clean) === 1) {
            return 'Copilot';
        }

        return null;
    }

    /**
     * Check if a channel group or source is considered Organic Search.
     */
    public function isOrganicSearch(string $channelGroup, string $source): bool
    {
        $cleanChannel = strtolower(trim($channelGroup));
        if ($cleanChannel === 'organic search') {
            return true;
        }

        $cleanSource = strtolower(trim($source));
        $searchEngines = [
            'google',
            'bing',
            'duckduckgo',
            'yahoo',
            'ecosia',
            'baidu',
            'yandex',
            'brave',
            'qwant',
            'search',
        ];

        foreach ($searchEngines as $engine) {
            if (str_contains($cleanSource, $engine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Synchronize traffic snapshots for all configured GA4 and Plausible integrations.
     *
     * @return array{success: bool, records_synced: int, errors: list<string>}
     */
    public function syncProjectTraffic(Project $project, int $days = 30): array
    {
        $integrations = $project->integrations()
            ->whereIn('provider', ['ga4', 'plausible'])
            ->get();

        $totalSynced = 0;
        $errors = [];

        foreach ($integrations as $integration) {
            try {
                if ($integration->provider === 'ga4') {
                    $totalSynced += $this->syncGa4($integration, $days);
                } elseif ($integration->provider === 'plausible') {
                    $totalSynced += $this->syncPlausible($integration, $days);
                }
            } catch (Throwable $e) {
                Log::error("Failed to sync {$integration->provider} for project {$project->id}: ".$e->getMessage());
                $errors[] = "{$integration->provider}: ".$e->getMessage();
            }
        }

        return [
            'success' => $errors === [],
            'records_synced' => $totalSynced,
            'errors' => $errors,
        ];
    }

    /**
     * Sync daily landing page metrics from Google Analytics 4.
     */
    public function syncGa4(ProjectIntegration $integration, int $days = 30): int
    {
        $project = $integration->project;
        if ($project === null) {
            return 0;
        }

        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);

        $metrics = $this->ga4Client->fetchLandingPageMetrics($integration, $startDate, $endDate);
        if ($metrics === []) {
            $integration->update([
                'status' => 'connected',
                'last_synced_at' => Carbon::now(),
            ]);

            return 0;
        }

        // Group rows by (path_hash, date)
        /** @var array<string, array{
         *     project_id: int,
         *     provider: string,
         *     path: string,
         *     path_hash: string,
         *     date: string,
         *     organic_sessions: int,
         *     ai_referral_sessions: int,
         *     direct_sessions: int,
         *     total_sessions: int,
         *     bounce_rate_sum: float,
         *     avg_duration_sum: int,
         *     sample_count: int,
         *     ai_referrals_breakdown: array<string, int>
         * }> $buckets */
        $buckets = [];

        foreach ($metrics as $m) {
            $path = $this->normalizePath($m['path']);
            $pathHash = hash('sha256', $path);
            $date = $m['date'];
            $key = $pathHash.'|'.$date;

            $isOrganic = $this->isOrganicSearch($m['channel_group'], $m['source']);
            $aiBrand = $this->classifyAiAssistant($m['source']);
            $isDirect = strtolower(trim($m['channel_group'])) === 'direct';

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'project_id' => $project->id,
                    'provider' => 'ga4',
                    'path' => $path,
                    'path_hash' => $pathHash,
                    'date' => $date,
                    'organic_sessions' => 0,
                    'ai_referral_sessions' => 0,
                    'direct_sessions' => 0,
                    'total_sessions' => 0,
                    'bounce_rate_sum' => 0.0,
                    'avg_duration_sum' => 0,
                    'sample_count' => 0,
                    'ai_referrals_breakdown' => [],
                ];
            }

            $sessions = $m['sessions'];
            $buckets[$key]['total_sessions'] += $sessions;

            if ($isOrganic) {
                $buckets[$key]['organic_sessions'] += $sessions;
            }

            if ($aiBrand !== null) {
                $buckets[$key]['ai_referral_sessions'] += $sessions;
                $currentAi = $buckets[$key]['ai_referrals_breakdown'][$aiBrand] ?? 0;
                $buckets[$key]['ai_referrals_breakdown'][$aiBrand] = $currentAi + $sessions;
            }

            if ($isDirect) {
                $buckets[$key]['direct_sessions'] += $sessions;
            }

            $buckets[$key]['bounce_rate_sum'] += $m['bounce_rate'] * max(1, $sessions);
            $buckets[$key]['avg_duration_sum'] += $m['avg_duration_sec'] * max(1, $sessions);
            $buckets[$key]['sample_count'] += max(1, $sessions);
        }

        $now = Carbon::now();
        $recordsToUpsert = [];

        foreach ($buckets as $b) {
            $weight = max(1, $b['sample_count']);
            $bounceRate = round($b['bounce_rate_sum'] / $weight, 2);
            $avgDuration = (int) round($b['avg_duration_sum'] / $weight);

            $recordsToUpsert[] = [
                'project_id' => $b['project_id'],
                'provider' => 'ga4',
                'path' => $b['path'],
                'path_hash' => $b['path_hash'],
                'date' => $b['date'],
                'organic_sessions' => $b['organic_sessions'],
                'ai_referral_sessions' => $b['ai_referral_sessions'],
                'direct_sessions' => $b['direct_sessions'],
                'total_sessions' => $b['total_sessions'],
                'bounce_rate' => $bounceRate,
                'avg_engagement_time_sec' => $avgDuration,
                'ai_referrals_breakdown' => (string) json_encode($b['ai_referrals_breakdown']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($recordsToUpsert, 500) as $chunk) {
            PageTrafficSnapshot::query()->upsert(
                $chunk,
                ['project_id', 'provider', 'path_hash', 'date'],
                [
                    'path',
                    'organic_sessions',
                    'ai_referral_sessions',
                    'direct_sessions',
                    'total_sessions',
                    'bounce_rate',
                    'avg_engagement_time_sec',
                    'ai_referrals_breakdown',
                    'updated_at',
                ]
            );
        }

        $count = count($recordsToUpsert);

        $integration->update([
            'status' => 'connected',
            'last_synced_at' => $now,
        ]);

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.synced',
            title: 'Google Analytics 4 Synced',
            description: "Synchronized {$count} traffic attribution snapshots from GA4.",
            properties: [
                'provider' => 'ga4',
                'records_count' => $count,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        );

        return $count;
    }

    /**
     * Sync landing page metrics from Plausible Analytics.
     */
    public function syncPlausible(ProjectIntegration $integration, int $days = 30): int
    {
        $project = $integration->project;
        if ($project === null) {
            return 0;
        }

        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);

        $pageRows = $this->plausibleClient->fetchPageMetrics($integration, $startDate, $endDate);
        $sourceRows = $this->plausibleClient->fetchSourceMetrics($integration, $startDate, $endDate);

        // Calculate global traffic attribution proportions from Plausible sources
        $totalSourceVisitors = 0;
        $organicSourceVisitors = 0;
        $aiSourceVisitors = 0;
        /** @var array<string, int> $aiSourceBreakdown */
        $aiSourceBreakdown = [];

        foreach ($sourceRows as $src) {
            $visitors = $src['visitors'];
            $totalSourceVisitors += $visitors;

            $aiBrand = $this->classifyAiAssistant($src['source']);
            if ($aiBrand !== null) {
                $aiSourceVisitors += $visitors;
                $aiSourceBreakdown[$aiBrand] = ($aiSourceBreakdown[$aiBrand] ?? 0) + $visitors;
            } elseif ($this->isOrganicSearch('', $src['source'])) {
                $organicSourceVisitors += $visitors;
            }
        }

        $organicRatio = $totalSourceVisitors > 0 ? ($organicSourceVisitors / $totalSourceVisitors) : 0.65;
        $aiRatio = $totalSourceVisitors > 0 ? ($aiSourceVisitors / $totalSourceVisitors) : 0.10;

        $now = Carbon::now();
        $todayStr = $endDate->format('Y-m-d');
        $recordsToUpsert = [];

        foreach ($pageRows as $row) {
            $path = $this->normalizePath($row['page']);
            $pathHash = hash('sha256', $path);
            $totalVisitors = $row['visitors'];

            $organicSessions = (int) round($totalVisitors * $organicRatio);
            $aiSessions = (int) round($totalVisitors * $aiRatio);

            // Compute page-level proportional AI breakdown
            $pageAiBreakdown = [];
            if ($aiSourceVisitors > 0 && $aiSessions > 0) {
                foreach ($aiSourceBreakdown as $brand => $brandHits) {
                    $brandShare = $brandHits / $aiSourceVisitors;
                    $pageBrandHits = (int) round($aiSessions * $brandShare);
                    if ($pageBrandHits > 0) {
                        $pageAiBreakdown[$brand] = $pageBrandHits;
                    }
                }
            }

            $recordsToUpsert[] = [
                'project_id' => $project->id,
                'provider' => 'plausible',
                'path' => $path,
                'path_hash' => $pathHash,
                'date' => $todayStr,
                'organic_sessions' => $organicSessions,
                'ai_referral_sessions' => $aiSessions,
                'direct_sessions' => max(0, $totalVisitors - ($organicSessions + $aiSessions)),
                'total_sessions' => $totalVisitors,
                'bounce_rate' => $row['bounce_rate'],
                'avg_engagement_time_sec' => $row['visit_duration'],
                'ai_referrals_breakdown' => (string) json_encode($pageAiBreakdown),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($recordsToUpsert, 500) as $chunk) {
            PageTrafficSnapshot::query()->upsert(
                $chunk,
                ['project_id', 'provider', 'path_hash', 'date'],
                [
                    'path',
                    'organic_sessions',
                    'ai_referral_sessions',
                    'direct_sessions',
                    'total_sessions',
                    'bounce_rate',
                    'avg_engagement_time_sec',
                    'ai_referrals_breakdown',
                    'updated_at',
                ]
            );
        }

        $count = count($recordsToUpsert);

        $integration->update([
            'status' => 'connected',
            'last_synced_at' => $now,
        ]);

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.synced',
            title: 'Plausible Analytics Synced',
            description: "Synchronized {$count} traffic attribution snapshots from Plausible Analytics.",
            properties: [
                'provider' => 'plausible',
                'records_count' => $count,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        );

        return $count;
    }

    /**
     * Build unified Page Performance dataset joining CrawlUrl health,
     * Google Search Console clicks, and GA4 / Plausible traffic.
     *
     * @return array{
     *     kpis: array{
     *         total_organic_sessions: int,
     *         total_ai_referral_sessions: int,
     *         total_gsc_clicks: int,
     *         crawled_pages_count: int,
     *         pages_with_traffic: int,
     *         at_risk_pages_count: int,
     *         top_ai_assistant: string|null,
     *         ai_brand_totals: array<string, int>
     *     },
     *     pages: list<array{
     *         path: string,
     *         full_url: string,
     *         crawl_status: int|null,
     *         issues: list<string>,
     *         gsc_clicks: int,
     *         gsc_impressions: int,
     *         organic_sessions: int,
     *         ai_referral_sessions: int,
     *         total_sessions: int,
     *         bounce_rate: float,
     *         avg_engagement_time_sec: int,
     *         ai_referrals_breakdown: array<string, int>,
     *         is_at_risk: bool
     *     }>
     * }
     */
    public function getPagePerformanceReport(Project $project, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);

        // 1. Fetch latest crawl URLs
        /** @var CrawlRun|null $latestCrawl */
        $latestCrawl = $project->crawlRuns()->latest('id')->first();
        /** @var Collection<int, CrawlUrl> $crawlUrls */
        $crawlUrls = $latestCrawl !== null
            ? $latestCrawl->urls()->get()
            : collect();

        /** @var array<string, array{
         *     full_url: string,
         *     crawl_status: int|null,
         *     issues: list<string>
         * }> $crawlMap */
        $crawlMap = [];

        foreach ($crawlUrls as $cu) {
            $path = $this->normalizePath($cu->url);
            $issues = [];

            if ($cu->status >= 400 && $cu->status < 500) {
                $issues[] = "HTTP {$cu->status} Client Error";
            } elseif ($cu->status >= 500) {
                $issues[] = "HTTP {$cu->status} Server Error";
            }

            if ($cu->meta_robots !== null && str_contains(strtolower($cu->meta_robots), 'noindex')) {
                $issues[] = 'Noindex Tag';
            }

            if ($cu->status === 200) {
                if ($cu->title === null || trim($cu->title) === '') {
                    $issues[] = 'Missing Title';
                }
                if ($cu->h1_count === 0) {
                    $issues[] = 'Missing H1';
                }
            }

            /** @var mixed $chain */
            $chain = $cu->redirect_chain;
            if ((is_array($chain) && $chain !== []) || (is_string($chain) && $chain !== '' && $chain !== '[]')) {
                $issues[] = 'Redirect Chain';
            }

            $crawlMap[$path] = [
                'full_url' => $cu->url,
                'crawl_status' => $cu->status,
                'issues' => $issues,
            ];
        }

        // 2. Fetch GSC Page Performance
        /** @var Collection<int, SearchPerformancePage> $gscPages */
        $gscPages = $project->searchPerformancePages()
            ->where('date', '>=', $since->format('Y-m-d'))
            ->get();

        /** @var array<string, array{clicks: int, impressions: int}> $gscMap */
        $gscMap = [];
        foreach ($gscPages as $gp) {
            $path = $this->normalizePath($gp->url);
            if (! isset($gscMap[$path])) {
                $gscMap[$path] = ['clicks' => 0, 'impressions' => 0];
            }
            $gscMap[$path]['clicks'] += $gp->clicks;
            $gscMap[$path]['impressions'] += $gp->impressions;
        }

        // 3. Fetch Page Traffic Snapshots (GA4 & Plausible)
        /** @var Collection<int, PageTrafficSnapshot> $trafficSnapshots */
        $trafficSnapshots = $project->pageTrafficSnapshots()
            ->where('date', '>=', $since->format('Y-m-d'))
            ->get();

        /** @var array<string, array{
         *     organic_sessions: int,
         *     ai_referral_sessions: int,
         *     total_sessions: int,
         *     bounce_rate_sum: float,
         *     duration_sum: int,
         *     samples: int,
         *     ai_breakdown: array<string, int>
         * }> $trafficMap */
        $trafficMap = [];

        foreach ($trafficSnapshots as $ts) {
            $path = $this->normalizePath($ts->path);
            if (! isset($trafficMap[$path])) {
                $trafficMap[$path] = [
                    'organic_sessions' => 0,
                    'ai_referral_sessions' => 0,
                    'total_sessions' => 0,
                    'bounce_rate_sum' => 0.0,
                    'duration_sum' => 0,
                    'samples' => 0,
                    'ai_breakdown' => [],
                ];
            }

            $trafficMap[$path]['organic_sessions'] += $ts->organic_sessions;
            $trafficMap[$path]['ai_referral_sessions'] += $ts->ai_referral_sessions;
            $trafficMap[$path]['total_sessions'] += $ts->total_sessions;

            $weight = max(1, $ts->total_sessions);
            $trafficMap[$path]['bounce_rate_sum'] += $ts->bounce_rate * $weight;
            $trafficMap[$path]['duration_sum'] += $ts->avg_engagement_time_sec * $weight;
            $trafficMap[$path]['samples'] += $weight;

            if (is_array($ts->ai_referrals_breakdown)) {
                foreach ($ts->ai_referrals_breakdown as $brand => $count) {
                    $c = (int) $count;
                    $trafficMap[$path]['ai_breakdown'][$brand] = ($trafficMap[$path]['ai_breakdown'][$brand] ?? 0) + $c;
                }
            }
        }

        // 4. Merge all distinct paths
        /** @var array<string, bool> $allPaths */
        $allPaths = [];
        foreach (array_keys($crawlMap) as $p) {
            $allPaths[$p] = true;
        }
        foreach (array_keys($gscMap) as $p) {
            $allPaths[$p] = true;
        }
        foreach (array_keys($trafficMap) as $p) {
            $allPaths[$p] = true;
        }

        $baseOrigin = $project->canonical_url !== ''
            ? rtrim($project->canonical_url, '/')
            : "https://{$project->domain}";

        $rows = [];
        $totalOrganic = 0;
        $totalAi = 0;
        $totalClicks = 0;
        $pagesWithTraffic = 0;
        $atRiskCount = 0;
        /** @var array<string, int> $aiBrandTotals */
        $aiBrandTotals = [];

        foreach (array_keys($allPaths) as $path) {
            $crawlInfo = $crawlMap[$path] ?? null;
            $gscInfo = $gscMap[$path] ?? null;
            $trafficInfo = $trafficMap[$path] ?? null;

            $fullUrl = $crawlInfo['full_url'] ?? ($baseOrigin.($path === '/' ? '' : $path));
            $crawlStatus = $crawlInfo['crawl_status'] ?? null;
            $issues = $crawlInfo['issues'] ?? [];

            $gscClicks = $gscInfo['clicks'] ?? 0;
            $gscImpressions = $gscInfo['impressions'] ?? 0;

            $organicSessions = $trafficInfo['organic_sessions'] ?? 0;
            $aiSessions = $trafficInfo['ai_referral_sessions'] ?? 0;
            $totalSessions = $trafficInfo['total_sessions'] ?? 0;

            $samples = max(1, $trafficInfo['samples'] ?? 1);
            $bounceRate = isset($trafficInfo['bounce_rate_sum']) ? round($trafficInfo['bounce_rate_sum'] / $samples, 2) : 0.0;
            $avgDuration = isset($trafficInfo['duration_sum']) ? (int) round($trafficInfo['duration_sum'] / $samples) : 0;
            $aiBreakdown = $trafficInfo['ai_breakdown'] ?? [];

            $totalOrganic += $organicSessions;
            $totalAi += $aiSessions;
            $totalClicks += $gscClicks;

            $hasTraffic = ($organicSessions > 0 || $aiSessions > 0 || $gscClicks > 0 || $totalSessions > 0);
            if ($hasTraffic) {
                $pagesWithTraffic++;
            }

            foreach ($aiBreakdown as $brand => $count) {
                $aiBrandTotals[$brand] = ($aiBrandTotals[$brand] ?? 0) + $count;
            }

            // High Traffic at Risk: Page has crawl / technical issues and receives organic, AI, or GSC traffic
            $isAtRisk = $issues !== [] && ($organicSessions > 0 || $aiSessions > 0 || $gscClicks > 0);
            if ($isAtRisk) {
                $atRiskCount++;
            }

            $rows[] = [
                'path' => $path,
                'full_url' => $fullUrl,
                'crawl_status' => $crawlStatus,
                'issues' => $issues,
                'gsc_clicks' => $gscClicks,
                'gsc_impressions' => $gscImpressions,
                'organic_sessions' => $organicSessions,
                'ai_referral_sessions' => $aiSessions,
                'total_sessions' => $totalSessions,
                'bounce_rate' => $bounceRate,
                'avg_engagement_time_sec' => $avgDuration,
                'ai_referrals_breakdown' => $aiBreakdown,
                'is_at_risk' => $isAtRisk,
            ];
        }

        // Sort rows by: at-risk first, then total sessions + clicks descending
        usort($rows, function ($a, $b): int {
            if ($a['is_at_risk'] !== $b['is_at_risk']) {
                return $a['is_at_risk'] ? -1 : 1;
            }

            $scoreA = $a['total_sessions'] + $a['gsc_clicks'];
            $scoreB = $b['total_sessions'] + $b['gsc_clicks'];

            return $scoreB <=> $scoreA;
        });

        // Determine top AI assistant
        $topAiAssistant = null;
        $maxAi = 0;
        foreach ($aiBrandTotals as $brand => $hits) {
            if ($hits > $maxAi) {
                $maxAi = $hits;
                $topAiAssistant = $brand;
            }
        }

        return [
            'kpis' => [
                'total_organic_sessions' => $totalOrganic,
                'total_ai_referral_sessions' => $totalAi,
                'total_gsc_clicks' => $totalClicks,
                'crawled_pages_count' => count($crawlMap),
                'pages_with_traffic' => $pagesWithTraffic,
                'at_risk_pages_count' => $atRiskCount,
                'top_ai_assistant' => $topAiAssistant,
                'ai_brand_totals' => $aiBrandTotals,
            ],
            'pages' => $rows,
        ];
    }
}
