<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoBudgetException;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\DataForSeo\DataForSeoReadOnlyException;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\ReadOnlySpendScope;
use App\Domains\Serp\Services\KeywordGapService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class KeywordGapController extends Controller
{
    public function index(Workspace $workspace, Project $project): Response
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        $competitors = $project->competitors()
            ->orderBy('sort_order')
            ->get(['id', 'uuid', 'domain', 'label', 'sort_order']);

        /** @var list<string> $trackedKeywords */
        $trackedKeywords = $project->trackedKeywords()
            ->select(['keyword', 'device', 'location_code', 'language_code'])
            ->get()
            ->map(static fn (TrackedKeyword $k): string => sprintf(
                '%s:%s:%d:%s',
                strtolower(trim($k->keyword)),
                strtolower(trim($k->device)),
                $k->location_code,
                strtolower(trim($k->language_code)),
            ))
            ->unique()
            ->values()
            ->all();

        return Inertia::render('Keywords/Gap', [
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
            'trackedKeywords' => $trackedKeywords,
            'defaultLocationCode' => $project->location_code ?: 2840,
            'defaultLanguageCode' => $project->language_code ?: 'en',
        ]);
    }

    public function search(
        Workspace $workspace,
        Project $project,
        Request $request,
        KeywordGapService $service,
    ): JsonResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'competitors' => ['required', 'array', 'min:1', 'max:3'],
            'competitors.*' => ['required', 'string', 'max:255'],
            'location_code' => ['nullable', 'integer'],
            'language_code' => ['nullable', 'string', 'max:16'],
            'gap_mode' => ['nullable', 'string', 'in:all,missing,weak,shared,strong'],
            'bypass_cache' => ['nullable', 'boolean'],
        ]);

        /** @var list<string> $competitors */
        $competitors = (array) $validated['competitors'];
        $locationCode = isset($validated['location_code']) ? (int) $validated['location_code'] : ($project->location_code ?: 2840);
        $languageCode = isset($validated['language_code']) && is_string($validated['language_code']) && trim($validated['language_code']) !== ''
            ? trim($validated['language_code'])
            : ($project->language_code ?: 'en');
        $gapMode = isset($validated['gap_mode']) && is_string($validated['gap_mode']) ? $validated['gap_mode'] : 'all';
        $bypassCache = ! empty($validated['bypass_cache']);

        $canSpend = Gate::allows('spend', $project);
        $analyze = fn (): array => $service->analyze(
            project: $project,
            competitorDomains: $competitors,
            locationCode: $locationCode,
            languageCode: $languageCode,
            gapMode: $gapMode,
            bypassCache: $bypassCache && $canSpend,
        );

        try {
            // Read-only members only receive cached gap data (no new paid lookups).
            $result = $canSpend ? $analyze() : ReadOnlySpendScope::run($analyze);

            return response()->json($result);
        } catch (DataForSeoReadOnlyException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'read_only',
            ], 403);
        } catch (DataForSeoBudgetException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'budget_exceeded',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to analyze competitor keyword gaps: '.$e->getMessage(),
            ], 500);
        }
    }

    public function track(
        Workspace $workspace,
        Project $project,
        Request $request,
        DataForSeoCacheRepository $cacheRepo,
    ): JsonResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'keywords' => ['required', 'array', 'min:1', 'max:700'],
            'device' => ['required', 'string', 'in:desktop,mobile'],
            'cadence' => ['required', 'string', 'in:daily,weekly'],
            'location_code' => ['nullable', 'integer'],
            'language_code' => ['nullable', 'string', 'max:16'],
        ]);

        /** @var list<string|array<string, mixed>> $rawKeywords */
        $rawKeywords = $validated['keywords'];
        $device = (string) $validated['device'];
        $cadence = (string) $validated['cadence'];
        $locationCode = isset($validated['location_code']) ? (int) $validated['location_code'] : ($project->location_code ?: 2840);
        $languageCode = isset($validated['language_code']) && is_string($validated['language_code']) && trim($validated['language_code']) !== ''
            ? trim($validated['language_code'])
            : ($project->language_code ?: 'en');

        $trackedKeywords = [];

        DB::transaction(function () use (
            $rawKeywords,
            $project,
            $device,
            $cadence,
            $locationCode,
            $languageCode,
            $cacheRepo,
            &$trackedKeywords,
        ): void {
            foreach ($rawKeywords as $item) {
                if (is_string($item)) {
                    $kw = trim($item);
                    $searchVolume = null;
                    $keywordDifficulty = null;
                    $itemLocation = $locationCode;
                    $itemLanguage = $languageCode;
                } else {
                    $kw = isset($item['keyword']) && is_string($item['keyword']) ? trim($item['keyword']) : '';
                    $searchVolume = isset($item['search_volume']) && is_numeric($item['search_volume']) ? (int) $item['search_volume'] : null;
                    $keywordDifficulty = isset($item['keyword_difficulty']) && is_numeric($item['keyword_difficulty']) ? (int) $item['keyword_difficulty'] : null;
                    $itemLocation = isset($item['location_code']) && is_numeric($item['location_code'])
                        ? (int) $item['location_code']
                        : $locationCode;
                    $itemLanguage = isset($item['language_code']) && is_string($item['language_code']) && trim($item['language_code']) !== ''
                        ? trim($item['language_code'])
                        : $languageCode;
                }

                if ($kw === '') {
                    continue;
                }

                if ($searchVolume === null || $keywordDifficulty === null) {
                    $cached = $cacheRepo->getKeywordMetrics($kw, $itemLocation, $itemLanguage);
                    if ($cached !== null) {
                        $searchVolume ??= isset($cached['search_volume']) ? (int) $cached['search_volume'] : null;
                        $keywordDifficulty ??= isset($cached['keyword_difficulty']) ? (int) $cached['keyword_difficulty'] : null;
                    }
                }

                $existing = TrackedKeyword::query()
                    ->where('project_id', $project->id)
                    ->where('keyword', $kw)
                    ->where('device', $device)
                    ->where('location_code', $itemLocation)
                    ->where('language_code', $itemLanguage)
                    ->first();

                if ($existing !== null) {
                    $updates = [];
                    if ($searchVolume !== null && $existing->search_volume === null) {
                        $updates['search_volume'] = $searchVolume;
                    }
                    if ($keywordDifficulty !== null && $existing->keyword_difficulty === null) {
                        $updates['keyword_difficulty'] = $keywordDifficulty;
                    }
                    if (! $existing->is_active) {
                        $updates['is_active'] = true;
                    }
                    if ($updates !== []) {
                        $existing->update($updates);
                    }
                } else {
                    TrackedKeyword::query()->create([
                        'project_id' => $project->id,
                        'keyword' => $kw,
                        'device' => $device,
                        'location_code' => $itemLocation,
                        'language_code' => $itemLanguage,
                        'cadence' => $cadence,
                        'search_volume' => $searchVolume,
                        'keyword_difficulty' => $keywordDifficulty,
                        'is_active' => true,
                    ]);
                }

                $trackedKeywords[] = sprintf(
                    '%s:%s:%d:%s',
                    strtolower($kw),
                    strtolower($device),
                    $itemLocation,
                    strtolower($itemLanguage),
                );
            }
        });

        $count = count($trackedKeywords);

        /** @var User|null $user */
        $user = $request->user();

        ProjectActivity::log(
            project: $project,
            eventType: 'keyword.created',
            title: "Added {$count} keyword(s) from Keyword Gap",
            description: "Promoted {$count} keyword gap(s) to {$device} rank tracking with {$cadence} cadence.",
            properties: [
                'count' => $count,
                'device' => $device,
                'cadence' => $cadence,
                'source' => 'keyword_gap',
            ],
            user: $user,
        );

        return response()->json([
            'success' => true,
            'count' => $count,
            'tracked_keywords' => $trackedKeywords,
        ]);
    }
}
