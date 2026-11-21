<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Services;

use App\Domains\Crawls\Models\CrawlDiffReport;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use App\Support\PageUrl;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CrawlDiffService
{
    public const DRILL_DOWN_PER_PAGE = 50;

    /** Days a share link lasts when no expiry is chosen. */
    public const DEFAULT_SHARE_DAYS = 30;

    /** Page fields compared before and after for each issue's URL. */
    private const PAGE_FIELDS = [
        'status' => 'status',
        'title' => 'title',
        'metaDescription' => 'meta_description',
        'h1' => 'h1',
        'canonical' => 'canonical',
        'metaRobots' => 'meta_robots',
        'redirectTarget' => 'redirect_target',
        'wordCount' => 'word_count',
    ];

    /**
     * One page of a rule's new or resolved issues, with each URL's page
     * fields in both crawls.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function issueDrillDown(CrawlRun $baselineRun, CrawlRun $targetRun, string $type, string $rule, ?string $severity, int $page): array
    {
        $total = $this->issueDeltaQuery($baselineRun->id, $targetRun->id, $type, $rule, $severity)->count();
        $lastPage = max(1, (int) ceil($total / self::DRILL_DOWN_PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        return [
            'data' => $this->issueDeltaRows(
                $baselineRun->id,
                $targetRun->id,
                $type,
                $rule,
                $severity,
                ($page - 1) * self::DRILL_DOWN_PER_PAGE,
                self::DRILL_DOWN_PER_PAGE,
            ),
            'total' => $total,
            'page' => $page,
            'perPage' => self::DRILL_DOWN_PER_PAGE,
            'lastPage' => $lastPage,
        ];
    }

    /**
     * Issues present in one crawl and not the other: "new" are in the target
     * only, "resolved" in the baseline only. Aliased `i`.
     */
    private function issueDeltaQuery(int $baselineId, int $targetId, string $type, ?string $rule = null, ?string $severity = null): Builder
    {
        [$in, $notIn] = $type === 'resolved' ? [$baselineId, $targetId] : [$targetId, $baselineId];

        return DB::table('crawl_issues as i')
            ->where('i.crawl_run_id', $in)
            ->when($rule !== null, fn (Builder $q) => $q->where('i.rule', $rule))
            ->when($severity !== null, fn (Builder $q) => $q->where('i.severity', $severity))
            ->whereNotExists(function ($query) use ($notIn): void {
                $query->select(DB::raw(1))
                    ->from('crawl_issues as o')
                    ->where('o.crawl_run_id', $notIn)
                    ->whereColumn('o.url_hash', 'i.url_hash')
                    ->whereColumn('o.rule', 'i.rule');
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function issueDeltaRows(int $baselineId, int $targetId, string $type, ?string $rule = null, ?string $severity = null, int $offset = 0, int $limit = 100): array
    {
        $select = ['i.url', 'i.url_hash', 'i.rule', 'i.severity', 'i.detail'];
        foreach (self::PAGE_FIELDS as $column) {
            $select[] = "bu.{$column} as before_{$column}";
            $select[] = "tu.{$column} as after_{$column}";
        }
        $select[] = 'bu.id as before_id';
        $select[] = 'tu.id as after_id';

        $rows = $this->issueDeltaQuery($baselineId, $targetId, $type, $rule, $severity)
            ->leftJoin('crawl_urls as bu', function ($join) use ($baselineId): void {
                $join->on('bu.url_hash', '=', 'i.url_hash')->where('bu.crawl_run_id', '=', $baselineId);
            })
            ->leftJoin('crawl_urls as tu', function ($join) use ($targetId): void {
                $join->on('tu.url_hash', '=', 'i.url_hash')->where('tu.crawl_run_id', '=', $targetId);
            })
            ->select($select)
            ->orderBy('i.id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return $rows->map(function (object $row): array {
            $before = $this->pageFields($row, 'before');
            $after = $this->pageFields($row, 'after');

            $changed = [];
            if ($before !== null && $after !== null) {
                foreach (array_keys(self::PAGE_FIELDS) as $field) {
                    if ($before[$field] !== $after[$field]) {
                        $changed[] = $field;
                    }
                }
            }

            return [
                'url' => (string) $row->url,
                'urlHash' => (string) $row->url_hash,
                'rule' => (string) $row->rule,
                'severity' => (string) $row->severity,
                'detail' => isset($row->detail) ? (string) $row->detail : null,
                // null when the URL wasn't in that crawl.
                'before' => $before,
                'after' => $after,
                'changedFields' => $changed,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, int|string|null>|null
     */
    private function pageFields(object $row, string $side): ?array
    {
        if (! isset($row->{$side.'_id'})) {
            return null;
        }

        $fields = [];
        foreach (self::PAGE_FIELDS as $field => $column) {
            $value = $row->{$side.'_'.$column} ?? null;
            $fields[$field] = $value === null ? null : (in_array($column, ['status', 'word_count'], true) ? (int) $value : (string) $value);
        }

        return $fields;
    }

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
        // Paths already counted, so URL variants of one page (e.g. with and
        // without a trailing slash) don't add its clicks twice.
        $countedPaths = [];

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
                $pathHash = $pathHashes[$hash] ?? $hash;
                if ($clicks !== null && $clicks > 0 && ! isset($countedPaths[$pathHash])) {
                    $totalLostClicks += $clicks;
                    $countedPaths[$pathHash] = true;
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

        // 3. Issue Delta Breakdown: New, Resolved, Persistent. Counts come
        // from the database; only samples are loaded, with each URL's page
        // fields from both crawls so the change itself is visible.
        $ruleDeltas = [];
        $newIssuesCount = 0;
        $resolvedIssuesCount = 0;

        foreach (['new' => 'newCount', 'resolved' => 'resolvedCount'] as $type => $field) {
            $counts = $this->issueDeltaQuery($baselineId, $targetId, $type)
                ->select('i.rule', 'i.severity')
                ->selectRaw('COUNT(*) as issue_count')
                ->groupBy('i.rule', 'i.severity')
                ->get();

            foreach ($counts as $row) {
                $key = $row->severity.'|'.$row->rule;
                $ruleDeltas[$key] ??= [
                    'rule' => (string) $row->rule,
                    'severity' => (string) $row->severity,
                    'newCount' => 0,
                    'resolvedCount' => 0,
                ];
                $ruleDeltas[$key][$field] += (int) $row->issue_count;
                if ($type === 'new') {
                    $newIssuesCount += (int) $row->issue_count;
                } else {
                    $resolvedIssuesCount += (int) $row->issue_count;
                }
            }
        }

        $persistentCount = (int) DB::table('crawl_issues as t')
            ->join('crawl_issues as b', function ($join) use ($baselineId): void {
                $join->on('b.url_hash', '=', 't.url_hash')
                    ->on('b.rule', '=', 't.rule')
                    ->where('b.crawl_run_id', '=', $baselineId);
            })
            ->where('t.crawl_run_id', $targetId)
            ->count();

        $rulesSummary = array_values(array_map(static fn (array $d): array => [
            'rule' => $d['rule'],
            'severity' => $d['severity'],
            'newCount' => $d['newCount'],
            'resolvedCount' => $d['resolvedCount'],
            'netChange' => $d['newCount'] - $d['resolvedCount'],
        ], $ruleDeltas));

        $newIssuesSample = array_map(function (array $issue) use ($trafficStats): array {
            $issue['gscClicks'] = $trafficStats[$issue['urlHash']]['clicks'] ?? null;

            return $issue;
        }, $this->issueDeltaRows($baselineId, $targetId, 'new', limit: 100));

        $resolvedIssuesSample = $this->issueDeltaRows($baselineId, $targetId, 'resolved', limit: 100);

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
                    'newIssuesCount' => $newIssuesCount,
                    'resolvedIssuesCount' => $resolvedIssuesCount,
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

        // Every link expires; it can also be revoked sooner.
        $expiresAt = Carbon::now()->addDays($expiresInDays !== null && $expiresInDays > 0 ? $expiresInDays : self::DEFAULT_SHARE_DAYS);

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

        if ($report === null || $report->isExpired() || $report->isRevoked()) {
            return null;
        }

        return $report;
    }
}
