<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectCompetitorController extends Controller
{
    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        if ($project->competitors()->count() >= 5) {
            return back()->withErrors([
                'domain' => 'Projects can track at most 5 competitors.',
            ]);
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $compDomain = Project::normalizeDomain((string) $validated['domain']);

        if ($compDomain === '') {
            return back()->withErrors([
                'domain' => 'Please provide a valid competitor domain or URL (e.g. competitor.com).',
            ]);
        }

        if ($compDomain === $project->domain) {
            return back()->withErrors([
                'domain' => 'A project cannot track its own domain as a competitor.',
            ]);
        }

        if ($project->competitors()->where('domain', $compDomain)->exists()) {
            return back()->withErrors([
                'domain' => "Competitor [{$compDomain}] is already being tracked.",
            ]);
        }

        $sortOrder = (int) (($project->competitors()->max('sort_order') ?? 0) + 1);

        $project->competitors()->create([
            'domain' => $compDomain,
            'label' => ! empty($validated['label']) ? (string) $validated['label'] : null,
            'sort_order' => $sortOrder,
        ]);

        /** @var User|null $user */
        $user = $request->user();

        ProjectActivity::log(
            project: $project,
            eventType: 'competitor.added',
            title: 'Competitor added',
            description: "Added competitor domain {$compDomain}.",
            properties: [
                'domain' => $compDomain,
                'label' => ! empty($validated['label']) ? (string) $validated['label'] : null,
            ],
            user: $user,
        );

        return back()->with('success', "Competitor [{$compDomain}] added.");
    }

    public function reorder(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        /** @var list<int> $order */
        $order = (array) $validated['order'];

        foreach ($order as $index => $competitorId) {
            $project->competitors()
                ->where('id', $competitorId)
                ->update(['sort_order' => $index + 1]);
        }

        /** @var User|null $user */
        $user = $request->user();

        ProjectActivity::log(
            project: $project,
            eventType: 'competitor.reordered',
            title: 'Competitors reordered',
            description: 'Competitor tracking priority reordered.',
            properties: ['order' => $order],
            user: $user,
        );

        return back()->with('success', 'Competitor order updated.');
    }

    public function destroy(Request $request, Workspace $workspace, Project $project, ProjectCompetitor $competitor): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id || $competitor->project_id !== $project->id, 404);
        Gate::authorize('update', $project);

        $domain = $competitor->domain;
        $competitor->delete();

        /** @var User|null $user */
        $user = $request->user();

        ProjectActivity::log(
            project: $project,
            eventType: 'competitor.removed',
            title: 'Competitor removed',
            description: "Removed competitor domain {$domain}.",
            properties: ['domain' => $domain],
            user: $user,
        );

        return back()->with('success', "Competitor [{$domain}] removed.");
    }
}
