<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Jobs\GenerateContentBriefJob;
use App\Domains\Serp\Models\ContentBrief;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentBriefController extends Controller
{
    public function show(Request $request, Workspace $workspace, Project $project): Response
    {
        Gate::authorize('view', $project);

        $keyword = trim((string) $request->input('keyword', ''));

        /** @var ContentBrief|null $brief */
        $brief = null;
        if ($keyword !== '') {
            $brief = $project->contentBriefs()
                ->where('keyword', strtolower($keyword))
                ->latest('id')
                ->first();
            // Generation is paid and only happens via POST (requires the update policy).
        }

        $recentBriefs = $project->contentBriefs()
            ->latest('id')
            ->limit(10)
            ->get(['id', 'keyword', 'search_volume', 'recommended_words_min', 'recommended_words_max', 'status', 'created_at'])
            ->map(fn (ContentBrief $b): array => [
                'id' => $b->id,
                'keyword' => $b->keyword,
                'searchVolume' => $b->search_volume,
                'wordRange' => $b->recommended_words_min !== null && $b->recommended_words_max !== null
                    ? "{$b->recommended_words_min} – {$b->recommended_words_max}"
                    : null,
                'status' => $b->status,
                'createdAt' => $b->created_at?->format('M j, Y'),
            ])
            ->all();

        return Inertia::render('Keywords/ContentBrief', [
            'workspace' => [
                'id' => $workspace->uuid,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'project' => [
                'id' => $project->uuid,
                'name' => $project->name,
                'domain' => $project->domain,
                'canonicalUrl' => $project->canonical_url,
                'locationCode' => $project->location_code,
                'languageCode' => $project->language_code,
            ],
            'selectedKeyword' => $keyword,
            'brief' => $brief !== null ? [
                'id' => $brief->id,
                'keyword' => $brief->keyword,
                'searchVolume' => $brief->search_volume,
                'cpc' => $brief->cpc,
                'recommendedWordsMin' => $brief->recommended_words_min,
                'recommendedWordsMax' => $brief->recommended_words_max,
                'data' => $brief->data !== [] ? $brief->data : null,
                'markdown' => $brief->markdown,
                'status' => $brief->status,
                'error' => $brief->error,
                'createdAt' => $brief->created_at?->toIso8601String(),
            ] : null,
            'recentBriefs' => $recentBriefs,
        ]);
    }

    public function generate(Request $request, Workspace $workspace, Project $project): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $project);
        ProviderNotConfiguredException::throwUnlessSeoDataConfigured();

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:255'],
            'fresh' => ['nullable', 'boolean'],
        ]);

        $keyword = strtolower(trim((string) $validated['keyword']));
        /** @var User|null $user */
        $user = $request->user();
        // A fresh lookup skips the shared cache, so it's for members who can spend.
        $fresh = (bool) ($validated['fresh'] ?? false) && Gate::allows('spend', $project);

        // The SERP lookup and competitor fetches run in a queued job, so the
        // request returns at once. The lock and the pending check stop a
        // double submit from paying for the same brief twice.
        $brief = Cache::lock("content-brief:{$project->id}:".md5($keyword), 10)->get(function () use ($project, $keyword, $user, $fresh): ContentBrief {
            /** @var ContentBrief|null $brief */
            $brief = $project->contentBriefs()->where('keyword', $keyword)->latest('id')->first();

            $inFlight = $brief !== null
                && $brief->status === ContentBrief::STATUS_PENDING
                && $brief->updated_at !== null
                && $brief->updated_at->greaterThan(now()->subMinutes(5));
            if ($inFlight) {
                return $brief;
            }

            if ($brief === null) {
                /** @var ContentBrief $brief */
                $brief = $project->contentBriefs()->create([
                    'keyword' => $keyword,
                    'location_code' => (int) ($project->location_code ?? 2840),
                    'language_code' => strtolower((string) ($project->language_code ?? 'en')),
                    'recommended_words_min' => null,
                    'recommended_words_max' => null,
                    'data' => [],
                    'markdown' => '',
                    'status' => ContentBrief::STATUS_PENDING,
                ]);
            } else {
                // A refresh keeps the previous brief visible until the new one lands.
                $brief->update(['status' => ContentBrief::STATUS_PENDING, 'error' => null]);
            }

            GenerateContentBriefJob::dispatch($brief, $user, $fresh);

            return $brief;
        });

        if (! $brief instanceof ContentBrief) {
            // Another request holds the lock and is starting this brief.
            /** @var ContentBrief|null $brief */
            $brief = $project->contentBriefs()->where('keyword', $keyword)->latest('id')->first();
        }

        $brief?->refresh();

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $brief?->id,
                'keyword' => $keyword,
                'status' => $brief->status ?? ContentBrief::STATUS_PENDING,
                'error' => $brief?->error,
                'data' => $brief?->data,
                'markdown' => $brief?->markdown,
            ], 202);
        }

        $redirect = redirect()->route('workspaces.projects.keywords.brief', [$workspace, $project, 'keyword' => $keyword]);

        return match ($brief?->status) {
            ContentBrief::STATUS_COMPLETED => $redirect->with('success', sprintf('Content brief generated for "%s".', $keyword)),
            ContentBrief::STATUS_FAILED => $redirect->with('error', (string) $brief->error),
            default => $redirect->with('success', sprintf('Generating the content brief for "%s". It will appear here in a few seconds.', $keyword)),
        };
    }

    public function download(Request $request, Workspace $workspace, Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        $keyword = trim((string) $request->input('keyword', ''));

        /** @var ContentBrief $brief */
        $brief = $project->contentBriefs()
            ->where('keyword', strtolower($keyword))
            ->latest('id')
            ->firstOrFail();

        $filename = sprintf('content-brief-%s.md', Str::slug($brief->keyword));

        return response()->streamDownload(
            function () use ($brief): void {
                echo $brief->markdown;
            },
            $filename,
            [
                'Content-Type' => 'text/markdown; charset=UTF-8',
            ]
        );
    }
}
