<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\ContentBrief;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Domains\Serp\Services\ContentBriefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentBriefController extends Controller
{
    public function __construct(
        private readonly ContentBriefService $briefService,
    ) {}

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
            ->get(['id', 'keyword', 'search_volume', 'recommended_words_min', 'recommended_words_max', 'created_at'])
            ->map(fn (ContentBrief $b): array => [
                'id' => $b->id,
                'keyword' => $b->keyword,
                'searchVolume' => $b->search_volume,
                'wordRange' => "{$b->recommended_words_min} – {$b->recommended_words_max}",
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
                'data' => $brief->data,
                'markdown' => $brief->markdown,
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
        ]);

        $keyword = (string) $validated['keyword'];
        $brief = $this->briefService->buildBrief($project, $keyword);

        ProjectActivity::log(
            project: $project,
            eventType: 'content_brief.generated',
            title: sprintf('Content brief generated for "%s"', Str::limit($brief->keyword, 50)),
            description: sprintf('Generated SERP content brief for "%s" with recommendations and competitor analysis.', $brief->keyword),
            properties: [
                'keyword' => $brief->keyword,
                'search_volume' => $brief->search_volume,
                'recommended_words_min' => $brief->recommended_words_min,
                'recommended_words_max' => $brief->recommended_words_max,
            ],
            user: $request->user(),
        );

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $brief->id,
                'keyword' => $brief->keyword,
                'data' => $brief->data,
                'markdown' => $brief->markdown,
            ], 201);
        }

        return redirect()->route('workspaces.projects.keywords.brief', [
            $workspace,
            $project,
            'keyword' => $brief->keyword,
        ])->with('success', sprintf('Content brief generated for "%s".', $brief->keyword));
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
