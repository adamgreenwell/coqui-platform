<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function create(Workspace $workspace): Response
    {
        Gate::authorize('createProjects', $workspace);

        return Inertia::render('Projects/Create', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('createProjects', $workspace);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'location_code' => ['nullable', 'integer', 'min:1'],
            'language_code' => ['nullable', 'string', 'max:16'],
            'crawl_cadence' => ['nullable', 'string', 'in:weekly,daily,monthly,manual'],
            'competitors' => ['nullable', 'array', 'max:5'],
            'competitors.*' => ['string', 'max:255'],
        ]);

        $domain = Project::normalizeDomain((string) $validated['url']);
        $canonicalUrl = Project::normalizeCanonicalUrl((string) $validated['url']);

        if ($domain === '') {
            throw ValidationException::withMessages([
                'url' => 'A valid domain or website URL is required.',
            ]);
        }

        if ($workspace->projects()->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages([
                'url' => "A project with domain [{$domain}] already exists in this workspace.",
            ]);
        }

        /** @var Project $project */
        $project = DB::transaction(function () use ($workspace, $validated, $domain, $canonicalUrl): Project {
            /** @var Project $proj */
            $proj = $workspace->projects()->create([
                'name' => (string) $validated['name'],
                'domain' => $domain,
                'canonical_url' => $canonicalUrl,
                'location_code' => (int) ($validated['location_code'] ?? 2840),
                'language_code' => (string) ($validated['language_code'] ?? 'en'),
                'crawl_cadence' => (string) ($validated['crawl_cadence'] ?? 'weekly'),
            ]);

            /** @var list<string> $rawCompetitors */
            $rawCompetitors = ! empty($validated['competitors']) ? (array) $validated['competitors'] : [];
            $sortOrder = 1;

            foreach ($rawCompetitors as $rawComp) {
                $compDomain = Project::normalizeDomain($rawComp);
                if ($compDomain === '' || $compDomain === $domain) {
                    continue;
                }

                if (! $proj->competitors()->where('domain', $compDomain)->exists() && $sortOrder <= 5) {
                    $proj->competitors()->create([
                        'domain' => $compDomain,
                        'sort_order' => $sortOrder++,
                    ]);
                }
            }

            return $proj;
        });

        return redirect()->route('workspaces.projects.show', [$workspace, $project])
            ->with('success', "Project [{$project->name}] created successfully.");
    }

    public function show(Request $request, Workspace $workspace, Project $project): Response
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        /** @var User $user */
        $user = $request->user();

        /** @var CrawlRun|null $latestCrawl */
        $latestCrawl = $project->crawlRuns()->latest('id')->first();

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

        return Inertia::render('Projects/Show', [
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
                'crawl_cadence' => $project->crawl_cadence,
                'runnr_site_id' => $project->runnr_site_id,
                'last_crawled_at' => $project->last_crawled_at?->toIso8601String(),
                'created_at' => $project->created_at?->toIso8601String(),
            ],
            'latestCrawl' => $latestCrawl !== null ? [
                'id' => $latestCrawl->id,
                'externalCrawlId' => $latestCrawl->external_crawl_id,
                'status' => $latestCrawl->status,
                'source' => $latestCrawl->source,
                'totalUrls' => $latestCrawl->total_urls,
                'errorCount' => $latestCrawl->error_count,
                'warningCount' => $latestCrawl->warning_count,
                'opportunityCount' => $latestCrawl->opportunity_count,
                'finalizedAt' => $latestCrawl->finalized_at?->toIso8601String(),
                'createdAt' => $latestCrawl->created_at?->toIso8601String(),
            ] : null,
            'trackedKeywordsCount' => $project->trackedKeywords()->count(),
            'monitoredBacklinksCount' => $project->monitoredBacklinks()->count(),
            'competitors' => $competitors,
            'canEdit' => $user->can('update', $project),
            'canDelete' => $user->can('delete', $project),
        ]);
    }

    public function edit(Workspace $workspace, Project $project): Response
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

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

        return Inertia::render('Projects/Settings', [
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
                'crawl_cadence' => $project->crawl_cadence,
            ],
            'competitors' => $competitors,
        ]);
    }

    public function update(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location_code' => ['required', 'integer', 'min:1'],
            'language_code' => ['required', 'string', 'max:16'],
            'crawl_cadence' => ['required', 'string', 'in:weekly,daily,monthly,manual'],
        ]);

        $project->update([
            'name' => (string) $validated['name'],
            'location_code' => (int) $validated['location_code'],
            'language_code' => (string) $validated['language_code'],
            'crawl_cadence' => (string) $validated['crawl_cadence'],
        ]);

        return back()->with('success', 'Project settings updated successfully.');
    }

    public function destroy(Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('delete', $project);

        $name = $project->name;
        $project->delete();

        return redirect()->route('workspaces.show', $workspace)
            ->with('success', "Project [{$name}] deleted.");
    }
}
