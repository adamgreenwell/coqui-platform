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
use App\Domains\Serp\Services\KeywordResearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class KeywordExplorerController extends Controller
{
    /**
     * Read-only members may only receive cached results (no new paid lookups).
     *
     * @template T
     *
     * @param  callable(): T  $lookup
     * @return T
     */
    private function withSpendPolicy(bool $canSpend, callable $lookup): mixed
    {
        return $canSpend ? $lookup() : ReadOnlySpendScope::run($lookup);
    }

    public function index(Workspace $workspace, Project $project): Response
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

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

        return Inertia::render('Keywords/Explorer', [
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
            'trackedKeywords' => $trackedKeywords,
            'defaultLocationCode' => $project->location_code ?: 2840,
            'defaultLanguageCode' => $project->language_code ?: 'en',
        ]);
    }

    public function overview(
        Workspace $workspace,
        Project $project,
        Request $request,
        KeywordResearchService $service,
    ): JsonResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'keywords' => ['required'],
            'location_code' => ['nullable', 'integer'],
            'language_code' => ['nullable', 'string', 'max:16'],
            'bypass_cache' => ['nullable', 'boolean'],
        ]);

        $rawKeywords = $validated['keywords'];
        $keywordsList = [];

        if (is_string($rawKeywords)) {
            $lines = preg_split('/[\r\n,]+/', $rawKeywords) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $keywordsList[] = $trimmed;
                }
            }
        } elseif (is_array($rawKeywords)) {
            foreach ($rawKeywords as $item) {
                if (is_string($item)) {
                    $trimmed = trim($item);
                    if ($trimmed !== '') {
                        $keywordsList[] = $trimmed;
                    }
                }
            }
        }

        $keywordsList = array_values(array_unique($keywordsList));

        if ($keywordsList === []) {
            throw ValidationException::withMessages([
                'keywords' => 'Please provide at least one valid keyword.',
            ]);
        }

        if (count($keywordsList) > 700) {
            throw ValidationException::withMessages([
                'keywords' => 'The maximum allowed batch size is 700 keywords.',
            ]);
        }

        foreach ($keywordsList as $kw) {
            if (mb_strlen($kw) > 255) {
                throw ValidationException::withMessages([
                    'keywords' => "The keyword '{$kw}' exceeds 255 characters.",
                ]);
            }
        }

        $locationCode = (int) ($validated['location_code'] ?? ($project->location_code ?: 2840));
        $languageCode = (string) ($validated['language_code'] ?? ($project->language_code ?: 'en'));
        $bypassCache = (bool) ($validated['bypass_cache'] ?? false);

        $canSpend = Gate::allows('spend', $project);

        try {
            $result = $this->withSpendPolicy($canSpend, fn (): array => $service->overview(
                keywords: $keywordsList,
                project: $project,
                locationCode: $locationCode,
                languageCode: $languageCode,
                bypassCache: $bypassCache && $canSpend,
            ));
        } catch (DataForSeoReadOnlyException $e) {
            return response()->json([
                'message' => $e->publicMessage(),
                'error' => 'read_only',
            ], 403);
        } catch (DataForSeoBudgetException $e) {
            return response()->json([
                'message' => $e->publicMessage(),
                'error' => 'budget_exceeded',
            ], 422);
        }

        return response()->json($result);
    }

    public function related(
        Workspace $workspace,
        Project $project,
        Request $request,
        KeywordResearchService $service,
    ): JsonResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:255'],
            'location_code' => ['nullable', 'integer'],
            'language_code' => ['nullable', 'string', 'max:16'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:700'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:4'],
            'bypass_cache' => ['nullable', 'boolean'],
        ]);

        $seed = trim((string) $validated['keyword']);
        if ($seed === '') {
            throw ValidationException::withMessages([
                'keyword' => 'Please provide a seed keyword.',
            ]);
        }

        $locationCode = (int) ($validated['location_code'] ?? ($project->location_code ?: 2840));
        $languageCode = (string) ($validated['language_code'] ?? ($project->language_code ?: 'en'));
        $limit = (int) ($validated['limit'] ?? 100);
        $depth = (int) ($validated['depth'] ?? 1);
        $bypassCache = (bool) ($validated['bypass_cache'] ?? false);

        $canSpend = Gate::allows('spend', $project);

        try {
            $result = $this->withSpendPolicy($canSpend, fn (): array => $service->related(
                keyword: $seed,
                project: $project,
                locationCode: $locationCode,
                languageCode: $languageCode,
                limit: $limit,
                depth: $depth,
                bypassCache: $bypassCache && $canSpend,
            ));
        } catch (DataForSeoReadOnlyException $e) {
            return response()->json([
                'message' => $e->publicMessage(),
                'error' => 'read_only',
            ], 403);
        } catch (DataForSeoBudgetException $e) {
            return response()->json([
                'message' => $e->publicMessage(),
                'error' => 'budget_exceeded',
            ], 422);
        }

        return response()->json($result);
    }

    public function track(
        Workspace $workspace,
        Project $project,
        Request $request,
        DataForSeoCacheRepository $cacheRepo,
    ): JsonResponse|RedirectResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'keywords' => ['required', 'array', 'min:1', 'max:700'],
            'device' => ['nullable', 'string', 'in:desktop,mobile'],
            'cadence' => ['nullable', 'string', 'in:daily,weekly'],
            'location_code' => ['nullable', 'integer'],
            'language_code' => ['nullable', 'string', 'max:16'],
        ]);

        $device = (string) ($validated['device'] ?? 'desktop');
        $cadence = (string) ($validated['cadence'] ?? 'weekly');
        $locationCode = (int) ($validated['location_code'] ?? ($project->location_code ?: 2840));
        $languageCode = (string) ($validated['language_code'] ?? ($project->language_code ?: 'en'));

        /** @var list<mixed> $items */
        $items = $validated['keywords'];
        $trackedKeywords = [];

        DB::transaction(function () use ($items, $project, $device, $cadence, $locationCode, $languageCode, $cacheRepo, &$trackedKeywords): void {
            foreach ($items as $item) {
                $kw = '';
                $searchVolume = null;
                $keywordDifficulty = null;

                if (is_string($item)) {
                    $kw = trim($item);
                } elseif (is_array($item)) {
                    $kw = trim((string) ($item['keyword'] ?? ''));
                    $searchVolume = isset($item['search_volume']) && is_numeric($item['search_volume'])
                        ? (int) $item['search_volume']
                        : null;
                    $keywordDifficulty = isset($item['keyword_difficulty']) && is_numeric($item['keyword_difficulty'])
                        ? (int) $item['keyword_difficulty']
                        : null;
                }

                if ($kw === '' || mb_strlen($kw) > 255) {
                    continue;
                }

                $itemLocation = is_array($item) && isset($item['location_code']) && is_numeric($item['location_code'])
                    ? (int) $item['location_code']
                    : $locationCode;
                $itemLanguage = is_array($item) && isset($item['language_code']) && is_string($item['language_code']) && trim($item['language_code']) !== ''
                    ? trim($item['language_code'])
                    : $languageCode;

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

        ProjectActivity::log(
            project: $project,
            eventType: 'keyword.created',
            title: Str::limit("Added {$count} keyword(s) from Keyword Explorer", 255, ''),
            description: "Added {$count} keyword(s) for {$device} rank tracking with search volume & difficulty metrics.",
            properties: [
                'count' => $count,
                'device' => $device,
                'location_code' => $locationCode,
                'cadence' => $cadence,
                'source' => 'keyword_explorer',
            ],
            user: $request->user(),
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'count' => $count,
                'tracked_keywords' => $trackedKeywords,
            ]);
        }

        return back()->with('success', "Successfully added {$count} keyword(s) to Rank Tracker.");
    }
}
