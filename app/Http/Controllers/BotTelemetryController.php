<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Integrations\Services\BotTelemetryService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BotTelemetryController extends Controller
{
    public function __construct(
        private readonly BotTelemetryService $telemetry,
    ) {}

    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        /** @var User $user */
        $user = $request->user();

        $days = max(1, min(90, (int) $request->query('days', 14)));
        $dashboard = $this->telemetry->getTelemetryDashboard($project, $days);

        return Inertia::render('Projects/BotLogs', [
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
                'runnr_site_id' => $project->runnr_site_id,
            ],
            'days' => $days,
            'kpis' => $dashboard['kpis'],
            'dailyTimeseries' => $dashboard['dailyTimeseries'],
            'botFamilyBreakdown' => $dashboard['botFamilyBreakdown'],
            'statusBreakdown' => $dashboard['statusBreakdown'],
            'flagged404s' => $dashboard['flagged404s'],
            'canEdit' => $user->can('update', $project),
        ]);
    }

    public function remediate(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'source_path' => ['required', 'string', 'max:2048'],
            'target_path' => ['required', 'string', 'max:2048'],
        ]);

        $sourcePath = (string) $validated['source_path'];
        $targetPath = (string) $validated['target_path'];

        /** @var User|null $user */
        $user = $request->user();

        ProjectActivity::log(
            project: $project,
            eventType: 'redirect.created',
            title: '301 Redirect Remediated',
            description: "Configured 301 redirect for {$sourcePath} -> {$targetPath} to reclaim wasted crawl budget.",
            properties: [
                'source_path' => $sourcePath,
                'target_path' => $targetPath,
            ],
            user: $user,
        );

        return redirect()->back()->with('success', "301 Redirect created: {$sourcePath} -> {$targetPath}");
    }
}
