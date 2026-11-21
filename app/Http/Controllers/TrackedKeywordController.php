<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Billing\PlanFeature;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\KeywordAiOverviewCitation;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\Services\RankTrendService;
use App\Domains\Serp\Support\VisibilityScore;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TrackedKeywordController extends Controller
{
    public function index(Request $request, Workspace $workspace, Project $project, RankTrendService $trends): Response
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        $deviceFilter = $request->query('device');
        $searchFilter = $request->query('search');

        $query = $project->trackedKeywords()->orderBy('keyword');

        if (is_string($deviceFilter) && in_array($deviceFilter, ['desktop', 'mobile'], true)) {
            $query->where('device', $deviceFilter);
        }

        if (is_string($searchFilter) && trim($searchFilter) !== '') {
            $search = trim($searchFilter);
            $query->where('keyword', 'like', "%{$search}%");
        }

        $trackedKeywords = $query->get();

        /** @var list<array{id: string, domain: string, label: string|null, sort_order: int}> $competitors */
        $competitors = $project->competitors()
            ->orderBy('sort_order')
            ->get(['id', 'uuid', 'domain', 'label', 'sort_order'])
            ->map(fn (ProjectCompetitor $c): array => [
                'id' => $c->uuid,
                'domain' => $c->domain,
                'label' => $c->label,
                'sort_order' => $c->sort_order,
            ])
            ->all();

        $keywordIds = $trackedKeywords->pluck('id')->all();

        $subQuery = KeywordRankSnapshot::query()
            ->select('*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY tracked_keyword_id, domain ORDER BY checked_on DESC) as rn')
            ->whereIn('tracked_keyword_id', $keywordIds);

        $allSnapshots = empty($keywordIds)
            ? collect()
            : KeywordRankSnapshot::query()
                ->fromSub($subQuery, 'ranked_snapshots')
                ->where('rn', '<=', 2)
                ->orderByDesc('checked_on')
                ->get();

        $snapshotsByKeyword = $allSnapshots->groupBy(fn (KeywordRankSnapshot $s): int => (int) $s->tracked_keyword_id);

        /** @var ProjectIntegration|null $gscIntegration */
        $gscIntegration = $project->integrations()->where('provider', 'gsc')->first();
        /** @var ProjectIntegration|null $bingIntegration */
        $bingIntegration = $project->integrations()->where('provider', 'bing')->first();

        // Query trailing 28 finalized days for first-party search performance overlay
        $cutoffDate = Carbon::now()->subDays(32)->format('Y-m-d');

        // The GSC/Bing overlay belongs to the traffic attribution plan feature;
        // without it the columns stay empty and the page shows an upgrade prompt.
        // Search Console rows are per device; rows synced before the split
        // cover all devices ("all"), as do Bing's. A complete per-device sync
        // deletes the older rows in its window, so an "all" row that's still
        // there means that day's device data may be partial: it stays
        // authoritative for its query and date, and device rows count only
        // on dates without one.
        $performanceStats = $workspace->hasFeature(PlanFeature::TrafficAttribution)
            ? DB::table('search_performance_queries as spq')
                ->where('spq.project_id', $project->id)
                ->where('spq.date', '>=', $cutoffDate)
                ->where(function ($query): void {
                    $query->where('spq.device', 'all')
                        ->orWhereNotExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from('search_performance_queries as legacy')
                                ->whereColumn('legacy.project_id', 'spq.project_id')
                                ->whereColumn('legacy.provider', 'spq.provider')
                                ->whereColumn('legacy.query', 'spq.query')
                                ->whereColumn('legacy.date', 'spq.date')
                                ->where('legacy.device', 'all');
                        });
                })
                ->selectRaw('spq.provider as provider, spq.device as device, LOWER(spq.query) as query_lower, SUM(spq.clicks) as total_clicks, SUM(spq.impressions) as total_impressions, SUM(spq.position * spq.impressions) as weighted_position, SUM(spq.position) as position_sum, COUNT(*) as row_count')
                ->groupBy('spq.provider', 'spq.device', 'query_lower')
                ->get()
            : collect();

        $gscStatsByQuery = $performanceStats
            ->where('provider', 'gsc')
            ->keyBy(fn (object $row): string => $row->device.'|'.$row->query_lower);

        $bingStatsByQuery = $performanceStats
            ->where('provider', 'bing')
            ->keyBy('query_lower')
            ->map(fn (object $row): object => $this->overlayTotals([$row]));

        $items = $trackedKeywords->map(function (TrackedKeyword $kw) use ($snapshotsByKeyword, $project, $competitors, $gscStatsByQuery, $bingStatsByQuery): array {
            $kwSnaps = $snapshotsByKeyword->get((int) $kw->id, collect());
            $domainSnaps = $kwSnaps->groupBy('domain');

            $projectSnaps = $domainSnaps->get($project->domain, collect())->sortByDesc('checked_on')->values();
            /** @var KeywordRankSnapshot|null $latestProjSnap */
            $latestProjSnap = $projectSnaps->first();
            /** @var KeywordRankSnapshot|null $prevProjSnap */
            $prevProjSnap = $projectSnaps->get(1);

            $currentRank = $latestProjSnap?->rank_group;
            $prevRank = $prevProjSnap?->rank_group;

            $delta = null;
            $deltaType = 'none';

            if ($currentRank !== null) {
                if ($prevRank !== null) {
                    $diff = $prevRank - $currentRank; // positive means improved (e.g. was 5, now 2 => +3)
                    $delta = $diff;
                    $deltaType = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'unchanged');
                } else {
                    $deltaType = 'new';
                }
            } elseif ($prevRank !== null) {
                $deltaType = 'dropped';
            }

            $competitorRanks = [];
            foreach ($competitors as $comp) {
                $cSnaps = $domainSnaps->get($comp['domain'], collect())->sortByDesc('checked_on')->values();
                /** @var KeywordRankSnapshot|null $latestCSnap */
                $latestCSnap = $cSnaps->first();
                $competitorRanks[$comp['domain']] = [
                    'rank' => $latestCSnap?->rank_group,
                    'url' => $latestCSnap?->ranking_url,
                    'has_ai_overview' => $latestCSnap !== null && $latestCSnap->has_ai_overview,
                    'has_featured_snippet' => $latestCSnap !== null && $latestCSnap->has_featured_snippet,
                ];
            }

            $kwKey = strtolower(trim($kw->keyword));

            // This keyword's device on fully synced dates, plus the older
            // all-device rows on the rest.
            $gscRows = array_values(array_filter([
                $gscStatsByQuery->get(strtolower($kw->device).'|'.$kwKey),
                $gscStatsByQuery->get('all|'.$kwKey),
            ]));
            /** @var object{total_clicks?: mixed, total_impressions?: mixed, avg_position?: mixed}|null $gsc */
            $gsc = $gscRows !== [] ? $this->overlayTotals($gscRows) : null;
            $gscClicks = ($gsc !== null && isset($gsc->total_clicks)) ? (int) $gsc->total_clicks : null;
            $gscImpressions = ($gsc !== null && isset($gsc->total_impressions)) ? (int) $gsc->total_impressions : null;
            $gscCtr = ($gscClicks !== null && $gscImpressions !== null && $gscImpressions > 0)
                ? round(($gscClicks / $gscImpressions) * 100, 2)
                : null;
            $gscPosition = ($gsc !== null && isset($gsc->avg_position)) ? round((float) $gsc->avg_position, 1) : null;

            /** @var object{total_clicks?: mixed, total_impressions?: mixed, avg_position?: mixed}|null $bing */
            $bing = $bingStatsByQuery->get($kwKey);
            $bingClicks = ($bing !== null && isset($bing->total_clicks)) ? (int) $bing->total_clicks : null;
            $bingImpressions = ($bing !== null && isset($bing->total_impressions)) ? (int) $bing->total_impressions : null;
            $bingCtr = ($bingClicks !== null && $bingImpressions !== null && $bingImpressions > 0)
                ? round(($bingClicks / $bingImpressions) * 100, 2)
                : null;
            $bingPosition = ($bing !== null && isset($bing->avg_position)) ? round((float) $bing->avg_position, 1) : null;

            return [
                'id' => $kw->uuid,
                'keyword' => $kw->keyword,
                'device' => $kw->device,
                'location_code' => $kw->location_code,
                'language_code' => $kw->language_code,
                'cadence' => $kw->cadence,
                'search_volume' => $kw->search_volume,
                'keyword_difficulty' => $kw->keyword_difficulty,
                'is_active' => $kw->is_active,
                'last_checked_at' => $kw->last_checked_at !== null
                    ? Carbon::parse($kw->last_checked_at)->toIso8601String()
                    : null,
                'current_rank' => $currentRank,
                'previous_rank' => $prevRank,
                'rank_delta' => $delta,
                'delta_type' => $deltaType,
                'ranking_url' => $latestProjSnap?->ranking_url,
                'ranking_title' => $latestProjSnap?->ranking_title,
                'has_ai_overview' => $latestProjSnap !== null && $latestProjSnap->has_ai_overview,
                'has_featured_snippet' => $latestProjSnap !== null && $latestProjSnap->has_featured_snippet,
                'serp_features' => $latestProjSnap !== null ? $latestProjSnap->serp_features : null,
                'competitor_ranks' => $competitorRanks,
                'gsc_performance' => [
                    'clicks' => $gscClicks,
                    'impressions' => $gscImpressions,
                    'ctr' => $gscCtr,
                    'position' => $gscPosition,
                ],
                'bing_performance' => [
                    'clicks' => $bingClicks,
                    'impressions' => $bingImpressions,
                    'ctr' => $bingCtr,
                    'position' => $bingPosition,
                ],
            ];
        })->all();

        // Calculate Visibility Metrics
        $totalCount = count($items);
        $top3Count = 0;
        $top10Count = 0;
        $top100Count = 0;
        $rankSum = 0;
        $rankedCount = 0;
        $visibilityPoints = 0;

        foreach ($items as $item) {
            $r = $item['current_rank'];
            if ($r !== null && $r > 0) {
                $rankedCount++;
                $rankSum += $r;
                if ($r <= 3) {
                    $top3Count++;
                }
                if ($r <= 10) {
                    $top10Count++;
                }
                if ($r <= 100) {
                    $top100Count++;
                }

                $visibilityPoints += VisibilityScore::points($r);
            }
        }

        $avgRank = $rankedCount > 0 ? round($rankSum / $rankedCount, 1) : null;
        $visibilityScore = $totalCount > 0 ? round(($visibilityPoints / ($totalCount * 100)) * 100, 1) : 0.0;

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Keywords/Index', [
            'workspace' => [
                'id' => $workspace->uuid,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'project' => [
                'id' => $project->uuid,
                'name' => $project->name,
                'domain' => $project->domain,
                'canonical_url' => $project->canonical_url,
                'location_code' => $project->location_code,
                'language_code' => $project->language_code,
            ],
            'competitors' => $competitors,
            'keywords' => $items,
            'trend' => $trends->daily($project->domain, array_map(intval(...), $keywordIds)),
            'metrics' => [
                'total_keywords' => $totalCount,
                'top_3' => $top3Count,
                'top_10' => $top10Count,
                'top_100' => $top100Count,
                'avg_rank' => $avgRank,
                'visibility_score' => $visibilityScore,
            ],
            'filters' => [
                'device' => $deviceFilter,
                'search' => $searchFilter,
            ],
            'canEdit' => $user->can('update', $project),
            'gscIntegration' => [
                'status' => $gscIntegration !== null ? $gscIntegration->status : 'not_connected',
                'site_url' => $gscIntegration?->site_url,
                'last_synced_at' => $gscIntegration?->last_synced_at instanceof CarbonInterface ? $gscIntegration->last_synced_at->toIso8601String() : null,
                'error_message' => $gscIntegration?->needsReconnect() === true ? $gscIntegration->status_reason : null,
                'connect_url' => route('workspaces.projects.integrations.google.connect', [$workspace, $project]),
            ],
            'bingIntegration' => [
                'status' => $bingIntegration !== null ? $bingIntegration->status : 'not_connected',
                'site_url' => $bingIntegration?->site_url,
                'last_synced_at' => $bingIntegration?->last_synced_at instanceof CarbonInterface ? $bingIntegration->last_synced_at->toIso8601String() : null,
                'error_message' => $bingIntegration?->needsReconnect() === true ? $bingIntegration->status_reason : null,
                'settings_url' => route('workspaces.projects.show', [$workspace, $project]),
            ],
        ]);
    }

    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'keywords' => ['required'],
            'device' => ['nullable', 'string', 'in:desktop,mobile'],
            'location_code' => ['nullable', 'integer', 'min:1'],
            'language_code' => ['nullable', 'string', 'max:16'],
            'cadence' => ['nullable', 'string', 'in:daily,weekly'],
        ]);

        $device = (string) ($validated['device'] ?? 'desktop');
        $locationCode = (int) ($validated['location_code'] ?? ($project->location_code ?: 2840));
        $languageCode = (string) ($validated['language_code'] ?? ($project->language_code ?: 'en'));
        $cadence = (string) ($validated['cadence'] ?? 'weekly');

        $rawKeywords = $validated['keywords'];
        $keywordList = [];

        if (is_string($rawKeywords)) {
            $lines = preg_split('/\r\n|\r|\n/', $rawKeywords) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $keywordList[] = $trimmed;
                }
            }
        } elseif (is_array($rawKeywords)) {
            foreach ($rawKeywords as $item) {
                if (is_string($item)) {
                    $trimmed = trim($item);
                    if ($trimmed !== '') {
                        $keywordList[] = $trimmed;
                    }
                }
            }
        }

        $keywordList = array_values(array_unique($keywordList));

        if (empty($keywordList)) {
            throw ValidationException::withMessages([
                'keywords' => 'Please provide at least one valid keyword.',
            ]);
        }

        if (count($keywordList) > 100) {
            throw ValidationException::withMessages([
                'keywords' => 'You can add at most 100 keywords at a time.',
            ]);
        }

        $workspace->assertCanTrackKeywords(count($keywordList));

        foreach ($keywordList as $kw) {
            if (mb_strlen($kw) > 255) {
                throw ValidationException::withMessages([
                    'keywords' => "The keyword '{$kw}' exceeds the maximum allowed length of 255 characters.",
                ]);
            }
        }

        $createdCount = DB::transaction(function () use ($keywordList, $project, $device, $locationCode, $languageCode, $cadence): int {
            $count = 0;
            foreach ($keywordList as $kw) {
                $existing = TrackedKeyword::query()
                    ->where('project_id', $project->id)
                    ->where('keyword', $kw)
                    ->where('device', $device)
                    ->where('location_code', $locationCode)
                    ->where('language_code', $languageCode)
                    ->exists();

                if (! $existing) {
                    TrackedKeyword::query()->create([
                        'project_id' => $project->id,
                        'keyword' => $kw,
                        'device' => $device,
                        'location_code' => $locationCode,
                        'language_code' => $languageCode,
                        'cadence' => $cadence,
                        'is_active' => true,
                    ]);
                    $count++;
                }
            }

            return $count;
        });

        ProjectActivity::log(
            project: $project,
            eventType: 'keyword.created',
            title: "Added {$createdCount} tracked keywords",
            description: "Added {$createdCount} keyword(s) for {$device} rank tracking.",
            properties: [
                'count' => $createdCount,
                'device' => $device,
                'location_code' => $locationCode,
                'cadence' => $cadence,
            ],
            user: $request->user(),
        );

        return back()->with('success', "Successfully added {$createdCount} tracked keyword(s).");
    }

    public function destroy(Workspace $workspace, Project $project, TrackedKeyword $keyword, Request $request): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id || $keyword->project_id !== $project->id, 404);
        Gate::authorize('update', $project);

        $kwText = $keyword->keyword;
        $keyword->delete();

        ProjectActivity::log(
            project: $project,
            eventType: 'keyword.deleted',
            title: Str::limit("Deleted keyword: {$kwText}", 255, ''),
            description: "Removed '{$kwText}' from tracked keywords.",
            properties: [
                'keyword' => $kwText,
                'device' => $keyword->device,
            ],
            user: $request->user(),
        );

        return back()->with('success', "Tracked keyword [{$kwText}] removed.");
    }

    public function snapshots(Workspace $workspace, Project $project, TrackedKeyword $keyword, RankTrendService $trends): JsonResponse
    {
        abort_if($project->workspace_id !== $workspace->id || $keyword->project_id !== $project->id, 404);
        Gate::authorize('view', $project);

        $snapshots = $keyword->snapshots()
            ->orderBy('checked_on')
            ->get([
                'id',
                'domain',
                'rank_group',
                'rank_absolute',
                'ranking_url',
                'ranking_title',
                'has_ai_overview',
                'has_featured_snippet',
                'serp_features',
                'checked_on',
            ]);

        $own = $snapshots->where('domain', $project->domain)->values();
        /** @var KeywordRankSnapshot|null $latest */
        $latest = $own->last();
        $features = $latest !== null && is_array($latest->serp_features) ? $latest->serp_features : [];

        // The latest check's AI Overview: its position and sources, in the
        // order it cited them, whoever it cited. None when that check had no
        // overview. (Older rows only know whether the project was cited.)
        $overviewPresent = $latest !== null
            && (($features['ai_overview_present'] ?? null) === true || $latest->has_ai_overview);
        $overviewOn = $overviewPresent
            ? Carbon::parse($latest->checked_on)->toDateString()
            : null;
        $citations = $overviewOn === null ? [] : KeywordAiOverviewCitation::query()
            ->where('tracked_keyword_id', $keyword->id)
            ->whereDate('checked_on', $overviewOn)
            ->orderBy('position')
            ->get(['position', 'domain', 'url', 'title'])
            ->map(fn (KeywordAiOverviewCitation $c): array => [
                'position' => $c->position,
                'domain' => $c->domain,
                'url' => $c->url,
                'title' => $c->title,
                'is_project' => $this->sameSite($c->domain, $project->domain),
            ])
            ->all();

        return response()->json([
            'keyword' => [
                'id' => $keyword->uuid,
                'keyword' => $keyword->keyword,
                'device' => $keyword->device,
                'location_code' => $keyword->location_code,
            ],
            'snapshots' => $snapshots,
            'volatility' => $trends->volatility($own),
            'ai_overview' => [
                'checked_on' => $overviewOn,
                'position' => $overviewOn !== null ? ($features['ai_overview_position'] ?? null) : null,
                'citations' => $citations,
            ],
            'people_also_ask' => is_array($features['people_also_ask'] ?? null) ? $features['people_also_ask'] : [],
            'local_pack' => is_array($features['local_pack'] ?? null) ? $features['local_pack'] : [],
        ]);
    }

    private function sameSite(string $domain, string $projectDomain): bool
    {
        $strip = static fn (string $d): string => (string) preg_replace('/^www\./', '', strtolower(trim($d)));
        $a = $strip($domain);
        $b = $strip($projectDomain);

        return $a === $b || str_ends_with($a, '.'.$b);
    }

    /**
     * Totals across aggregated overlay rows, with impression-weighted
     * position (a plain average when there were no impressions).
     *
     * @param  list<object>  $rows
     */
    private function overlayTotals(array $rows): object
    {
        $clicks = 0;
        $impressions = 0;
        $weighted = 0.0;
        $positionSum = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $clicks += (int) $row->total_clicks;
            $impressions += (int) $row->total_impressions;
            $weighted += (float) $row->weighted_position;
            $positionSum += (float) $row->position_sum;
            $count += (int) $row->row_count;
        }

        return (object) [
            'total_clicks' => $clicks,
            'total_impressions' => $impressions,
            'avg_position' => $impressions > 0
                ? round($weighted / $impressions, 1)
                : ($count > 0 ? round($positionSum / $count, 1) : null),
        ];
    }
}
