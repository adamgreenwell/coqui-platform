<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Billing\PlanFeature;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
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
                'id' => $workspace->uuid,
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
        if ($domain === '') {
            throw ValidationException::withMessages([
                'url' => 'Please provide a valid website domain or URL (e.g. example.com).',
            ]);
        }

        $canonicalUrl = Project::normalizeCanonicalUrl((string) $validated['url']);

        /** @var Project|null $existingProject */
        $existingProject = $workspace->projects()->withTrashed()->where('domain', $domain)->first();
        if ($existingProject !== null) {
            if ($existingProject->trashed()) {
                DB::transaction(function () use ($existingProject, $validated, $domain, $canonicalUrl): void {
                    $existingProject->restore();
                    $existingProject->update([
                        'name' => (string) $validated['name'],
                        'canonical_url' => $canonicalUrl,
                        'location_code' => (int) ($validated['location_code'] ?? 2840),
                        'language_code' => (string) ($validated['language_code'] ?? 'en'),
                        'crawl_cadence' => (string) ($validated['crawl_cadence'] ?? 'weekly'),
                    ]);

                    /** @var list<string> $rawCompetitors */
                    $rawCompetitors = ! empty($validated['competitors']) ? (array) $validated['competitors'] : [];
                    $currentCount = $existingProject->competitors()->count();
                    $maxOrder = (int) ($existingProject->competitors()->max('sort_order') ?? 0);

                    foreach ($rawCompetitors as $rawComp) {
                        if ($currentCount >= 5) {
                            break;
                        }

                        $compDomain = Project::normalizeDomain($rawComp);
                        if ($compDomain === '' || $compDomain === $domain) {
                            continue;
                        }

                        if (! $existingProject->competitors()->where('domain', $compDomain)->exists()) {
                            $existingProject->competitors()->create([
                                'domain' => $compDomain,
                                'sort_order' => ++$maxOrder,
                            ]);
                            $currentCount++;
                        }
                    }
                });

                /** @var User|null $user */
                $user = $request->user();

                ProjectActivity::log(
                    project: $existingProject,
                    eventType: 'project.restored',
                    title: 'Project restored',
                    description: "Project {$existingProject->name} was restored from trash.",
                    properties: ['domain' => $existingProject->domain],
                    user: $user,
                );

                return redirect()->route('workspaces.projects.show', [$workspace, $existingProject])
                    ->with('success', "Project [{$existingProject->name}] restored successfully.");
            }

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

        /** @var User|null $user */
        $user = $request->user();

        ProjectActivity::log(
            project: $project,
            eventType: 'project.created',
            title: 'Project created',
            description: "Project {$project->name} registered with domain {$project->domain}.",
            properties: [
                'domain' => $project->domain,
                'canonical_url' => $project->canonical_url,
                'location_code' => $project->location_code,
                'language_code' => $project->language_code,
            ],
            user: $user,
        );

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

        /** @var CrawlRun|null $latestSmoke */
        $latestSmoke = $workspace->hasFeature(PlanFeature::RunnrSmokeTests)
            ? $project->crawlRuns()
                ->where('source', 'deploy_smoke')
                ->latest('id')
                ->first()
            : null;

        $deploySmoke = null;
        if ($latestSmoke !== null) {
            $smokeConfig = $latestSmoke->config;
            /** @var array<string, mixed>|null $smokeResults */
            $smokeResults = is_array($smokeConfig['smoke_results'] ?? null) ? $smokeConfig['smoke_results'] : null;
            /** @var array<string, mixed>|null $smokeDeploy */
            $smokeDeploy = is_array($smokeConfig['deploy'] ?? null) ? $smokeConfig['deploy'] : null;

            $deploySmoke = [
                'id' => $latestSmoke->external_crawl_id,
                'externalCrawlId' => $latestSmoke->external_crawl_id,
                'status' => $latestSmoke->status,
                'passed' => $smokeResults['passed'] ?? ($latestSmoke->status === 'completed' && $latestSmoke->error_count === 0),
                'smokeStatus' => $smokeResults['status'] ?? ($latestSmoke->status === 'completed' ? 'passed' : 'failed'),
                'summary' => $smokeResults['summary'] ?? "Deploy smoke crawl {$latestSmoke->status}",
                'regressions' => $smokeResults['regressions'] ?? [],
                'totalUrlsChecked' => $smokeResults['total_urls_checked'] ?? $latestSmoke->total_urls,
                'deploy' => $smokeDeploy,
                'createdAt' => $latestSmoke->created_at instanceof CarbonInterface ? $latestSmoke->created_at->toIso8601String() : null,
                'finalizedAt' => $latestSmoke->finalized_at instanceof CarbonInterface ? $latestSmoke->finalized_at->toIso8601String() : null,
            ];
        }

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

        /** @var list<array{id: int, eventType: string, title: string, description: string|null, properties: array<string, mixed>|null, userName: string|null, createdAt: string}> $activities */
        $activities = $project->activities()
            ->with('user')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ProjectActivity $a): array => [
                'id' => $a->id,
                'eventType' => $a->event_type,
                'title' => $a->title,
                'description' => $a->description,
                'properties' => $a->properties,
                'userName' => $a->user?->name,
                'createdAt' => $a->created_at->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Projects/Show', [
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
                'crawl_cadence' => $project->crawl_cadence,
                'runnr_site_id' => $project->runnr_site_id,
                'last_crawled_at' => $project->last_crawled_at?->toIso8601String(),
                'created_at' => $project->created_at?->toIso8601String(),
            ],
            'latestCrawl' => $latestCrawl !== null ? [
                'id' => $latestCrawl->external_crawl_id,
                'externalCrawlId' => $latestCrawl->external_crawl_id,
                'status' => $latestCrawl->status,
                'source' => $latestCrawl->source,
                'totalUrls' => $latestCrawl->total_urls,
                'errorCount' => $latestCrawl->error_count,
                'warningCount' => $latestCrawl->warning_count,
                'opportunityCount' => $latestCrawl->opportunity_count,
                'finalizedAt' => $latestCrawl->finalized_at instanceof CarbonInterface ? $latestCrawl->finalized_at->toIso8601String() : null,
                'createdAt' => $latestCrawl->created_at instanceof CarbonInterface ? $latestCrawl->created_at->toIso8601String() : null,
            ] : null,
            'trackedKeywordsCount' => $project->trackedKeywords()->count(),
            'monitoredBacklinksCount' => $project->monitoredBacklinks()->count(),
            'competitors' => $competitors,
            'integrations' => $project->integrations()
                ->select(['id', 'uuid', 'provider', 'site_url', 'external_identifier', 'status', 'last_synced_at'])
                ->get()
                ->map(fn (ProjectIntegration $i): array => [
                    'id' => $i->uuid,
                    'provider' => $i->provider,
                    'site_url' => $i->site_url,
                    'status' => $i->status,
                    'last_synced_at' => $i->last_synced_at instanceof CarbonInterface ? $i->last_synced_at->toIso8601String() : null,
                ])
                ->all(),
            'deploySmoke' => $deploySmoke,
            'runnrWebhookUrl' => url('/api/webhooks/runnr/deploy'),
            'runnrBadgeUrl' => url('/api/v1/deploy-smoke/badge/'.$project->deployBadgeToken()),
            'activities' => $activities,
            'canEdit' => $user->can('update', $project),
            'canDelete' => $user->can('delete', $project),
        ]);
    }

    public function edit(Workspace $workspace, Project $project): Response
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

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

        return Inertia::render('Projects/Settings', [
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

        /** @var User|null $user */
        $user = $request->user();

        ProjectActivity::log(
            project: $project,
            eventType: 'project.updated',
            title: 'Settings updated',
            description: 'Project configuration updated.',
            properties: [
                'name' => $project->name,
                'location_code' => $project->location_code,
                'language_code' => $project->language_code,
                'crawl_cadence' => $project->crawl_cadence,
            ],
            user: $user,
        );

        return back()->with('success', 'Project settings updated successfully.');
    }

    public function activities(Workspace $workspace, Project $project): JsonResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        $activities = $project->activities()
            ->with('user')
            ->latest('id')
            ->paginate(20);

        return response()->json($activities);
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
