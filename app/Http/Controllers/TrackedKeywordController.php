<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TrackedKeywordController extends Controller
{
    public function index(Request $request, Workspace $workspace, Project $project): Response
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

        /** @var list<array{id: int, domain: string, label: string|null, sort_order: int}> $competitors */
        $competitors = $project->competitors()
            ->orderBy('sort_order')
            ->get(['id', 'domain', 'label', 'sort_order'])
            ->map(fn (ProjectCompetitor $c): array => [
                'id' => $c->id,
                'domain' => $c->domain,
                'label' => $c->label,
                'sort_order' => $c->sort_order,
            ])
            ->all();

        $keywordIds = $trackedKeywords->pluck('id')->all();

        $allSnapshots = empty($keywordIds)
            ? collect()
            : KeywordRankSnapshot::query()
                ->whereIn('tracked_keyword_id', $keywordIds)
                ->orderByDesc('checked_on')
                ->get();

        $snapshotsByKeyword = $allSnapshots->groupBy('tracked_keyword_id');

        $items = $trackedKeywords->map(function (TrackedKeyword $kw) use ($snapshotsByKeyword, $project, $competitors): array {
            $kwSnaps = $snapshotsByKeyword->get($kw->id, collect());
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

            return [
                'id' => $kw->id,
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

                // CTR weight points
                if ($r === 1) {
                    $visibilityPoints += 100;
                } elseif ($r === 2) {
                    $visibilityPoints += 70;
                } elseif ($r === 3) {
                    $visibilityPoints += 50;
                } elseif ($r <= 5) {
                    $visibilityPoints += 35;
                } elseif ($r <= 10) {
                    $visibilityPoints += 20;
                } elseif ($r <= 20) {
                    $visibilityPoints += 10;
                } elseif ($r <= 50) {
                    $visibilityPoints += 5;
                } else {
                    $visibilityPoints += 2;
                }
            }
        }

        $avgRank = $rankedCount > 0 ? round($rankSum / $rankedCount, 1) : null;
        $visibilityScore = $totalCount > 0 ? round(($visibilityPoints / ($totalCount * 100)) * 100, 1) : 0.0;

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Keywords/Index', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
                'canonical_url' => $project->canonical_url,
                'location_code' => $project->location_code,
                'language_code' => $project->language_code,
            ],
            'competitors' => $competitors,
            'keywords' => $items,
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

        $createdCount = 0;
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
                $createdCount++;
            }
        }

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
            title: "Deleted keyword: {$kwText}",
            description: "Removed '{$kwText}' from tracked keywords.",
            properties: [
                'keyword' => $kwText,
                'device' => $keyword->device,
            ],
            user: $request->user(),
        );

        return back()->with('success', "Tracked keyword [{$kwText}] removed.");
    }

    public function snapshots(Workspace $workspace, Project $project, TrackedKeyword $keyword): JsonResponse
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

        return response()->json([
            'keyword' => [
                'id' => $keyword->id,
                'keyword' => $keyword->keyword,
                'device' => $keyword->device,
                'location_code' => $keyword->location_code,
            ],
            'snapshots' => $snapshots,
        ]);
    }
}
