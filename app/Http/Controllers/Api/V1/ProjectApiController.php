<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $search = $request->input('search');
        $workspaceId = $request->input('workspace_id');

        $query = Project::query()
            ->whereHas('workspace', function ($wsQuery) use ($user): void {
                $wsQuery->where('owner_id', $user->id)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
            })
            ->with(['workspace:id,uuid,name,slug'])
            ->with(['crawlRuns' => function ($cQuery): void {
                $cQuery->latest('id')->limit(1);
            }]);

        if ($workspaceId !== null && $workspaceId !== '') {
            // Workspaces are filtered by public UUID; anything else matches nothing.
            if (is_string($workspaceId) && Str::isUuid($workspaceId)) {
                $query->whereRelation('workspace', 'uuid', $workspaceId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(domain) LIKE ?', [$term]);
            });
        }

        $paginator = $query->orderBy('name')->paginate($perPage);

        $projects = $paginator->getCollection()->map(function (Project $p): array {
            $latestCrawl = $p->crawlRuns->first();

            return [
                'id' => $p->uuid,
                'workspaceId' => $p->workspace?->uuid,
                'workspaceName' => $p->workspace?->name,
                'name' => $p->name,
                'domain' => $p->domain,
                'canonicalUrl' => $p->canonical_url,
                'locationCode' => $p->location_code,
                'languageCode' => $p->language_code,
                'crawlCadence' => $p->crawl_cadence,
                'lastCrawledAt' => $p->last_crawled_at?->toIso8601String(),
                'createdAt' => $p->created_at?->toIso8601String(),
                'updatedAt' => $p->updated_at?->toIso8601String(),
                'latestCrawl' => $latestCrawl !== null ? [
                    'id' => $latestCrawl->external_crawl_id,
                    'externalCrawlId' => $latestCrawl->external_crawl_id,
                    'source' => $latestCrawl->source,
                    'status' => $latestCrawl->status,
                    'totalUrls' => $latestCrawl->total_urls,
                    'errorCount' => $latestCrawl->error_count,
                    'warningCount' => $latestCrawl->warning_count,
                    'opportunityCount' => $latestCrawl->opportunity_count,
                    'finalizedAt' => $latestCrawl->finalized_at?->toIso8601String(),
                ] : null,
            ];
        });

        return response()->json([
            'data' => $projects,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'workspaceId' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255'],
            'canonicalUrl' => ['nullable', 'string', 'max:2048'],
            'locationCode' => ['nullable', 'integer', 'min:1'],
            'languageCode' => ['nullable', 'string', 'max:16'],
            'crawlCadence' => ['nullable', 'string', 'in:weekly,daily,monthly,manual'],
        ]);

        $workspaceId = $validated['workspaceId'] ?? null;
        if (is_string($workspaceId) && $workspaceId !== '') {
            /** @var Workspace $workspace */
            $workspace = Workspace::query()->where('uuid', $workspaceId)->firstOrFail();
        } else {
            /** @var Workspace|null $workspace */
            $workspace = Workspace::query()
                ->where('owner_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id))
                ->first();

            if ($workspace === null) {
                throw ValidationException::withMessages([
                    'workspaceId' => 'No accessible workspace found for this user.',
                ]);
            }
        }

        Gate::authorize('createProjects', $workspace);

        $rawDomain = (string) $validated['domain'];
        $domain = Project::normalizeDomain($rawDomain);
        if ($domain === '') {
            throw ValidationException::withMessages([
                'domain' => 'Please provide a valid website domain (e.g. example.com).',
            ]);
        }

        $canonicalUrl = "https://{$domain}/";
        if (! empty($validated['canonicalUrl'])) {
            $normalizedCanonical = Project::normalizeCanonicalUrl((string) $validated['canonicalUrl']);
            if ($normalizedCanonical === '') {
                throw ValidationException::withMessages([
                    'canonicalUrl' => 'Please provide a valid website canonical URL.',
                ]);
            }
            $canonicalUrl = $normalizedCanonical;
        }

        /** @var Project|null $existingProject */
        $existingProject = $workspace->projects()->withTrashed()->where('domain', $domain)->first();
        if ($existingProject !== null) {
            if ($existingProject->trashed()) {
                $existingProject->restore();
                $existingProject->update([
                    'name' => (string) $validated['name'],
                    'canonical_url' => $canonicalUrl,
                    'location_code' => (int) ($validated['locationCode'] ?? 2840),
                    'language_code' => (string) ($validated['languageCode'] ?? 'en'),
                    'crawl_cadence' => (string) ($validated['crawlCadence'] ?? 'weekly'),
                ]);

                ProjectActivity::log(
                    project: $existingProject,
                    eventType: 'project.restored',
                    title: 'Project restored via API',
                    description: "Project {$existingProject->name} was restored from trash.",
                    properties: [
                        'source' => 'desktop_api',
                        'domain' => $existingProject->domain,
                    ],
                );

                return response()->json([
                    'id' => $existingProject->uuid,
                    'workspaceId' => $workspace->uuid,
                    'workspaceName' => $workspace->name,
                    'name' => $existingProject->name,
                    'domain' => $existingProject->domain,
                    'canonicalUrl' => $existingProject->canonical_url,
                    'locationCode' => $existingProject->location_code,
                    'languageCode' => $existingProject->language_code,
                    'crawlCadence' => $existingProject->crawl_cadence,
                    'createdAt' => $existingProject->created_at?->toIso8601String(),
                    'restored' => true,
                ], 200);
            }

            throw ValidationException::withMessages([
                'domain' => "A project with domain [{$domain}] already exists in this workspace.",
            ]);
        }

        /** @var Project $project */
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => (string) $validated['name'],
            'domain' => $domain,
            'canonical_url' => $canonicalUrl,
            'location_code' => (int) ($validated['locationCode'] ?? 2840),
            'language_code' => (string) ($validated['languageCode'] ?? 'en'),
            'crawl_cadence' => (string) ($validated['crawlCadence'] ?? 'weekly'),
        ]);

        ProjectActivity::log(
            project: $project,
            eventType: 'project.created',
            title: 'Project created via API',
            description: "Registered {$project->name} ({$project->domain}) from Coqui Desktop.",
            properties: [
                'source' => 'desktop_api',
                'workspace_id' => $workspace->id,
            ],
        );

        return response()->json([
            'id' => $project->uuid,
            'workspaceId' => $workspace->uuid,
            'workspaceName' => $workspace->name,
            'name' => $project->name,
            'domain' => $project->domain,
            'canonicalUrl' => $project->canonical_url,
            'locationCode' => $project->location_code,
            'languageCode' => $project->language_code,
            'crawlCadence' => $project->crawl_cadence,
            'createdAt' => $project->created_at?->toIso8601String(),
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $project->load(['workspace:id,uuid,name,slug']);
        $latestCrawl = $project->crawlRuns()->latest('id')->first();
        $crawlCount = $project->crawlRuns()->count();

        return response()->json([
            'id' => $project->uuid,
            'workspaceId' => $project->workspace?->uuid,
            'workspaceName' => $project->workspace?->name,
            'name' => $project->name,
            'domain' => $project->domain,
            'canonicalUrl' => $project->canonical_url,
            'locationCode' => $project->location_code,
            'languageCode' => $project->language_code,
            'crawlCadence' => $project->crawl_cadence,
            'lastCrawledAt' => $project->last_crawled_at?->toIso8601String(),
            'crawlStats' => [
                'totalRuns' => $crawlCount,
                'latestRun' => $latestCrawl !== null ? [
                    'id' => $latestCrawl->external_crawl_id,
                    'externalCrawlId' => $latestCrawl->external_crawl_id,
                    'source' => $latestCrawl->source,
                    'status' => $latestCrawl->status,
                    'totalUrls' => $latestCrawl->total_urls,
                    'errorCount' => $latestCrawl->error_count,
                    'warningCount' => $latestCrawl->warning_count,
                    'opportunityCount' => $latestCrawl->opportunity_count,
                    'finalizedAt' => $latestCrawl->finalized_at?->toIso8601String(),
                ] : null,
            ],
            'createdAt' => $project->created_at?->toIso8601String(),
            'updatedAt' => $project->updated_at?->toIso8601String(),
        ]);
    }
}
