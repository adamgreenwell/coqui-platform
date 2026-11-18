<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $workspaces = $this->workspacesForUser($user);

        return Inertia::render('Workspaces/Index', [
            'workspaces' => $workspaces,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Workspaces/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', Rule::unique('workspaces', 'slug')],
        ]);

        $name = (string) $validated['name'];
        $baseSlug = ! empty($validated['slug']) ? (string) $validated['slug'] : Str::slug($name);
        $slug = $baseSlug !== '' ? $baseSlug : 'workspace';

        if (Workspace::query()->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(6));
        }

        /** @var Workspace $workspace */
        $workspace = DB::transaction(function () use ($user, $name, $slug): Workspace {
            /** @var Workspace $ws */
            $ws = Workspace::query()->create([
                'name' => $name,
                'slug' => $slug,
                'owner_id' => $user->id,
            ]);

            $ws->members()->attach($user->id, ['role' => 'owner']);

            return $ws;
        });

        session(['current_workspace_id' => $workspace->id]);

        return redirect()->route('workspaces.show', $workspace)
            ->with('success', "Workspace [{$workspace->name}] created.");
    }

    public function show(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        session(['current_workspace_id' => $workspace->id]);

        /** @var User $user */
        $user = $request->user();

        $workspace->loadCount(['projects', 'members']);

        $role = $this->resolveUserRole($user, $workspace);

        return Inertia::render('Workspaces/Show', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'planTier' => $workspace->plan_tier,
                'monthlyDfsBudgetUsd' => (float) $workspace->monthly_dfs_budget_usd,
                'trackedKeywordLimit' => $workspace->tracked_keyword_limit,
                'cloudCrawlUrlLimit' => $workspace->cloud_crawl_url_limit,
                'projectsCount' => $workspace->projects_count,
                'membersCount' => $workspace->members_count,
                'isOwner' => $workspace->owner_id === $user->id,
                'userRole' => $role,
            ],
            'projects' => $workspace->projects()->latest('id')->take(10)->get([
                'id',
                'name',
                'domain',
                'canonical_url',
                'last_crawled_at',
            ]),
            'allWorkspaces' => $this->workspacesForUser($user),
        ]);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace->update([
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Workspace updated.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workspacesForUser(User $user): array
    {
        return Workspace::query()
            ->where('owner_id', $user->id)
            ->orWhereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'plan_tier'])
            ->toArray();
    }

    private function resolveUserRole(User $user, Workspace $workspace): string
    {
        if ($workspace->owner_id === $user->id) {
            return 'owner';
        }

        $membership = $workspace->members()->where('users.id', $user->id)->first();
        $role = $membership?->pivot?->getAttribute('role');

        return is_string($role) ? $role : 'viewer';
    }
}
