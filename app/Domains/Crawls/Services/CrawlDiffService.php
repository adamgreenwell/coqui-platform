<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Services;

use App\Domains\Crawls\Models\CrawlDiffReport;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use App\Support\PageUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CrawlDiffService
{
    /**
     * Compute comprehensive technical diff between baseline and target crawl runs.
     *
     * @return array<string, mixed>
     */
    public function compare(CrawlRun $baselineRun, CrawlRun $targetRun): array
    {
        $projectId = $targetRun->project_id;
        $baselineId = $baselineRun->id;
        $targetId = $targetRun->id;

        // 1. URL Set Differences: New & Removed counts
        $newUrlsCount = (int) DB::table('crawl_urls as t')
            ->where('t.crawl_run_id', $targetId)
            ->whereNotExists(function ($query) use ($baselineId): void {
                $query->select(DB::raw(1))
                    ->from('crawl_urls as b')
                    ->where('b.crawl_run_id', $baselineId)
                    ->whereColumn('b.url_hash', 't.url_hash');
            })
            ->count();

        $removedUrlsCount = (int) DB::table('crawl_urls as b')
            ->where('b.crawl_run_id', $baselineId)
            ->whereNotExists(function ($query) use ($targetId): void {
                $query->select(DB::raw(1))
                    ->from('crawl_urls as t')
                    ->where('t.crawl_run_id', $targetId)
                    ->whereColumn('t.url_hash', 'b.url_hash');
            })
            ->count();

        // Sample up to 100 new URLs
        $newUrlsSample = DB::table('crawl_urls as t')
            ->where('t.crawl_run_id', $targetId)
            ->whereNotExists(function ($query) use ($baselineId): void {
                $query->select(DB::raw(1))
                    ->from('crawl_urls as b')
                    ->where('b.crawl_run_id', $baselineId)
                    ->whereColumn('b.url_hash', 't.url_hash');
            })
            ->select('t.url', 't.status', 't.title', 't.depth')
            ->orderBy('t.id')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'url' => (string) $row->url,
                'status' => isset($row->status) ? (int) $row->status : null,
                'title' => isset($row->title) ? (string) $row->title : null,
                'depth' => (int) ($row->depth ?? 0),
            ])
            ->all();

        // Sample up to 100 removed URLs
        $removedUrlsSample = DB::table('crawl_urls as b')
            ->where('b.crawl_run_id', $baselineId)
            ->whereNotExists(function ($query) use ($targetId): void {
                $query->select(DB::raw(1))
                    ->from('crawl_urls as t')
                    ->where('t.crawl_run_id', $targetId)
                    ->whereColumn('t.url_hash', 'b.url_hash');
            })
            ->select('b.url', 'b.status', 'b.title')
            ->orderBy('b.id')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'url' => (string) $row->url,
                'status' => isset($row->status) ? (int) $row->status : null,
                'title' => isset($row->title) ? (string) $row->title : null,
            ])
            ->all();

        // 2. Status & Field Transitions for intersecting URLs
        $rawTransitions = DB::table('crawl_urls as t')
            ->join('crawl_urls as b', function ($join) use ($baselineId): void {
                $join->on('b.url_hash', '=', 't.url_hash')
                    ->where('b.crawl_run_id', '=', $baselineId);
            })
            ->where('t.crawl_run_id', $targetId)
            ->where(function ($query): void {
                $query->where(function ($q): void {
                    $q->whereColumn('t.status', '!=', 'b.status')
                        ->orWhere(fn ($sub) => $sub->whereNull('t.status')->whereNotNull('b.status'))
                        ->orWhere(fn ($sub) => $sub->whereNotNull('t.status')->whereNull('b.status'));
                })
                    ->orWhere(function ($q): void {
                        $q->whereColumn('t.title', '!=', 'b.title')
                            ->orWhere(fn ($sub) => $sub->whereNull('t.title')->whereNotNull('b.title'))
                            ->orWhere(fn ($sub) => $sub->whereNotNull('t.title')->whereNull('b.title'));
                    })
                    ->orWhere(function ($q): void {
                        $q->whereColumn('t.canonical', '!=', 'b.canonical')
                            ->orWhere(fn ($sub) => $sub->whereNull('t.canonical')->whereNotNull('b.canonical'))
                            ->orWhere(fn ($sub) => $sub->whereNotNull('t.canonical')->whereNull('b.canonical'));
                    })
                    ->orWhere(function ($q): void {
                        $q->whereColumn('t.meta_robots', '!=', 'b.meta_robots')
                            ->orWhere(fn ($sub) => $sub->whereNull('t.meta_robots')->whereNotNull('b.meta_robots'))
                            ->orWhere(fn ($sub) => $sub->whereNotNull('t.meta_robots')->whereNull('b.meta_robots'));
                    });
            })
            ->select([
                't.url',
                't.url_hash',
                'b.status as baseline_status',
                't.status as target_status',
                'b.title as baseline_title',
                't.title as target_title',
                'b.canonical as baseline_canonical',
                't.canonical as target_canonical',
                'b.meta_robots as baseline_robots',
                't.meta_robots as target_robots',
            ])
            ->get();

        // GSC traffic overlay. Search performance rows are keyed by the
        // page's normalized path, not the crawl's full-URL hash, so map each
        // crawled URL to its path hash first.
        $pathHashes = [];
        foreach ($rawTransitions as $transition) {
            $pathHashes[(string) $transition->url_hash] = PageUrl::hash((string) $transition->url);
        }
        $trafficStats = [];

        if ($pathHashes !== []) {
            $trafficRows = DB::table('search_performance_pages')
                ->where('project_id', $projectId)
                ->whereIn('provider', ['gsc', 'google'])
                ->whereIn('url_hash', array_slice(array_values(array_unique($pathHashes)), 0, 5000))
                ->selectRaw('url_hash, sum(clicks) as total_clicks, sum(impressions) as total_impressions')
                ->groupBy('url_hash')
                ->get();

            $byPath = [];
            foreach ($trafficRows as $tRow) {
                $byPath[(string) $tRow->url_hash] = [
                    'clicks' => (int) $tRow->total_clicks,
                    'impressions' => (int) $tRow->total_impressions,
                ];
            }

            foreach ($pathHashes as $crawlHash => $pathHash) {
                if (isset($byPath[$pathHash])) {
                    $trafficStats[$crawlHash] = $byPath[$pathHash];
                }
            }
        }

        $newlyBroken = [];
        $otherTransitions = [];
        $totalLostClicks = 0;

        foreach ($rawTransitions as $row) {
            $bStatus = isset($row->baseline_status) ? (int) $row->baseline_status : null;
            $tStatus = isset($row->target_status) ? (int) $row->target_status : null;
            $hash = (string) $row->url_hash;
            $clicks = $trafficStats[$hash]['clicks'] ?? null;
            $impressions = $trafficStats[$hash]['impressions'] ?? null;

            $isBroken = ($bStatus === null || $bStatus < 400) && ($tStatus !== null && $tStatus >= 400);

            $item = [
                'url' => (string) $row->url,
                'urlHash' => $hash,
                'baselineStatus' => $bStatus,
                'targetStatus' => $tStatus,
                'baselineTitle' => isset($row->baseline_title) ? (string) $row->baseline_title : null,
                'targetTitle' => isset($row->target_title) ? (string) $row->target_title : null,
                'baselineCanonical' => isset($row->baseline_canonical) ? (string) $row->baseline_canonical : null,
                'targetCanonical' => isset($row->target_canonical) ? (string) $row->target_canonical : null,
                'baselineRobots' => isset($row->baseline_robots) ? (string) $row->baseline_robots : null,
                'targetRobots' => isset($row->target_robots) ? (string) $row->target_robots : null,
                'isNewlyBroken' => $isBroken,
                'gscClicks' => $clicks,
                'gscImpressions' => $impressions,
            ];

            if ($isBroken) {
                $newlyBroken[] = $item;
                if ($clicks !== null && $clicks > 0) {
                    $totalLostClicks += $clicks;
                }
            } else {
                $otherTransitions[] = $item;
            }
        }

        // Sort newly broken by highest GSC clicks first
        usort($newlyBroken, static function (array $a, array $b): int {
            $aClicks = (int) ($a['gscClicks'] ?? 0);
            $bClicks = (int) ($b['gscClicks'] ?? 0);

            return $bClicks <=> $aClicks;
        });

        // 3. Issue Delta Breakdown: New, Resolved, Persistent
        $newIssues = DB::table('crawl_issues as t')
            ->leftJoin('crawl_issues as b', function ($join) use ($baselineId): void {
                $join->on('b.url_hash', '=', 't.url_hash')
                    ->on('b.rule', '=', 't.rule')
                    ->where('b.crawl_run_id', '=', $baselineId);
            })
            ->where('t.crawl_run_id', $targetId)
            ->whereNull('b.id')
            ->select(['t.url', 't.url_hash', 't.rule', 't.severity', 't.detail'])
            ->get();

        $resolvedIssues = DB::table('crawl_issues as b')
            ->leftJoin('crawl_issues as t', function ($join) use ($targetId): void {
                $join->on('t.url_hash', '=', 'b.url_hash')
                    ->on('t.rule', '=', 'b.rule')
                    ->where('t.crawl_run_id', '=', $targetId);
            })
            ->where('b.crawl_run_id', $baselineId)
            ->whereNull('t.id')
            ->select(['b.url', 'b.url_hash', 'b.rule', 'b.severity', 'b.detail'])
            ->get();

        $persistentCount = (int) DB::table('crawl_issues as t')
            ->join('crawl_issues as b', function ($join) use ($baselineId): void {
                $join->on('b.url_hash', '=', 't.url_hash')
                    ->on('b.rule', '=', 't.rule')
                    ->where('b.crawl_run_id', '=', $baselineId);
            })
            ->where('t.crawl_run_id', $targetId)
            ->count();

        // Aggregate issue delta by rule and severity
        $ruleDeltas = [];

        foreach ($newIssues as $iss) {
            $key = $iss->severity.'|'.$iss->rule;
            if (! isset($ruleDeltas[$key])) {
                $ruleDeltas[$key] = [
                    'rule' => (string) $iss->rule,
                    'severity' => (string) $iss->severity,
                    'newCount' => 0,
                    'resolvedCount' => 0,
                ];
            }
            $ruleDeltas[$key]['newCount']++;
        }

        foreach ($resolvedIssues as $iss) {
            $key = $iss->severity.'|'.$iss->rule;
            if (! isset($ruleDeltas[$key])) {
                $ruleDeltas[$key] = [
                    'rule' => (string) $iss->rule,
                    'severity' => (string) $iss->severity,
                    'newCount' => 0,
                    'resolvedCount' => 0,
                ];
            }
            $ruleDeltas[$key]['resolvedCount']++;
        }

        $rulesSummary = array_values(array_map(static fn (array $d): array => [
            'rule' => $d['rule'],
            'severity' => $d['severity'],
            'newCount' => $d['newCount'],
            'resolvedCount' => $d['resolvedCount'],
            'netChange' => $d['newCount'] - $d['resolvedCount'],
        ], $ruleDeltas));

        // Sample top new issues
        $newIssuesSample = $newIssues->take(100)->map(fn (object $iss): array => [
            'url' => (string) $iss->url,
            'rule' => (string) $iss->rule,
            'severity' => (string) $iss->severity,
            'detail' => isset($iss->detail) ? (string) $iss->detail : null,
            'gscClicks' => $trafficStats[(string) $iss->url_hash]['clicks'] ?? null,
        ])->all();

        // Sample top resolved issues
        $resolvedIssuesSample = $resolvedIssues->take(100)->map(fn (object $iss): array => [
            'url' => (string) $iss->url,
            'rule' => (string) $iss->rule,
            'severity' => (string) $iss->severity,
            'detail' => isset($iss->detail) ? (string) $iss->detail : null,
        ])->all();

        $baselineTotal = (int) $baselineRun->total_urls;
        $targetTotal = (int) $targetRun->total_urls;

        return [
            'summary' => [
                'baseline' => [
                    'id' => $baselineRun->external_crawl_id,
                    'externalCrawlId' => $baselineRun->external_crawl_id,
                    'seed' => $baselineRun->seed,
                    'source' => $baselineRun->source,
                    'status' => $baselineRun->status,
                    'totalUrls' => $baselineTotal,
                    'errorCount' => (int) $baselineRun->error_count,
                    'warningCount' => (int) $baselineRun->warning_count,
                    'opportunityCount' => (int) $baselineRun->opportunity_count,
                    'createdAt' => $baselineRun->created_at?->toIso8601String(),
                    'finalizedAt' => $baselineRun->finalized_at?->toIso8601String(),
                ],
                'target' => [
                    'id' => $targetRun->external_crawl_id,
                    'externalCrawlId' => $targetRun->external_crawl_id,
                    'seed' => $targetRun->seed,
                    'source' => $targetRun->source,
                    'status' => $targetRun->status,
                    'totalUrls' => $targetTotal,
                    'errorCount' => (int) $targetRun->error_count,
                    'warningCount' => (int) $targetRun->warning_count,
                    'opportunityCount' => (int) $targetRun->opportunity_count,
                    'createdAt' => $targetRun->created_at?->toIso8601String(),
                    'finalizedAt' => $targetRun->finalized_at?->toIso8601String(),
                ],
                'delta' => [
                    'totalUrls' => $targetTotal - $baselineTotal,
                    'newUrlsCount' => $newUrlsCount,
                    'removedUrlsCount' => $removedUrlsCount,
                    'newlyBrokenCount' => count($newlyBroken),
                    'statusChangesCount' => count($rawTransitions),
                    'newIssuesCount' => $newIssues->count(),
                    'resolvedIssuesCount' => $resolvedIssues->count(),
                    'persistentIssuesCount' => $persistentCount,
                    'trafficImpactedBrokenUrls' => count(array_filter($newlyBroken, static fn ($item) => ($item['gscClicks'] ?? 0) > 0)),
                    'totalLostClicks' => $totalLostClicks,
                ],
            ],
            'newlyBroken' => array_slice($newlyBroken, 0, 200),
            'transitions' => array_slice($otherTransitions, 0, 200),
            'issueDeltas' => [
                'rules' => $rulesSummary,
                'newIssues' => $newIssuesSample,
                'resolvedIssues' => $resolvedIssuesSample,
            ],
            'urlSamples' => [
                'newUrls' => $newUrlsSample,
                'removedUrls' => $removedUrlsSample,
            ],
        ];
    }

    /**
     * Create or retrieve a shareable read-only report token with optional expiry.
     */
    public function createShareableReport(
        Project $project,
        CrawlRun $baselineRun,
        CrawlRun $targetRun,
        ?string $title = null,
        ?int $expiresInDays = null,
        ?User $user = null,
    ): CrawlDiffReport {
        $diffData = $this->compare($baselineRun, $targetRun);

        $expiresAt = $expiresInDays !== null && $expiresInDays > 0
            ? Carbon::now()->addDays($expiresInDays)
            : null;

        $reportTitle = $title ?? sprintf(
            'Crawl Diff: %s (%s vs %s)',
            $project->name,
            $baselineRun->created_at?->format('M j, Y') ?? 'Baseline',
            $targetRun->created_at?->format('M j, Y') ?? 'Target'
        );

        /** @var CrawlDiffReport $report */
        $report = CrawlDiffReport::query()->create([
            'project_id' => $project->id,
            'baseline_run_id' => $baselineRun->id,
            'target_run_id' => $targetRun->id,
            'share_token' => CrawlDiffReport::generateShareToken(),
            'title' => $reportTitle,
            'summary' => $diffData['summary'],
            'expires_at' => $expiresAt,
            'created_by_user_id' => $user?->id,
        ]);

        return $report;
    }

    /**
     * Locate a shareable report by its token, returning null if expired.
     */
    public function getReportByToken(string $token): ?CrawlDiffReport
    {
        /** @var CrawlDiffReport|null $report */
        $report = CrawlDiffReport::query()
            ->with(['project.workspace', 'baselineRun', 'targetRun'])
            ->where('share_token', $token)
            ->first();

        if ($report === null || $report->isExpired()) {
            return null;
        }

        return $report;
    }
}
