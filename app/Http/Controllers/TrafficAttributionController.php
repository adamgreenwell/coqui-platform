<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Integrations\Services\TrafficAttributionService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TrafficAttributionController extends Controller
{
    public function __construct(
        private readonly TrafficAttributionService $trafficService,
    ) {}

    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        $days = (int) $request->query('days', 30);
        if (! in_array($days, [7, 14, 30, 90], true)) {
            $days = 30;
        }

        $report = $this->trafficService->getPagePerformanceReport($project, $days);

        $ga4 = $project->integrations()->where('provider', 'ga4')->first();
        $plausible = $project->integrations()->where('provider', 'plausible')->first();

        return Inertia::render('Analytics/Performance', [
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
                ...(RunnrIntegration::enabled() ? ['runnr_site_id' => $project->runnr_site_id] : []),
            ],
            'days' => $days,
            'kpis' => $report['kpis'],
            'pages' => $report['pages'],
            'integrations' => [
                'ga4' => $ga4 !== null ? [
                    'id' => $ga4->uuid,
                    'status' => $ga4->status,
                    'propertyId' => $ga4->external_identifier,
                    'lastSyncedAt' => $ga4->last_synced_at instanceof CarbonInterface ? $ga4->last_synced_at->toIso8601String() : null,
                ] : null,
                'plausible' => $plausible !== null ? [
                    'id' => $plausible->uuid,
                    'status' => $plausible->status,
                    'siteId' => $plausible->external_identifier,
                    'lastSyncedAt' => $plausible->last_synced_at instanceof CarbonInterface ? $plausible->last_synced_at->toIso8601String() : null,
                ] : null,
            ],
            'canEdit' => $request->user()?->can('update', $project) ?? false,
        ]);
    }

    public function sync(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $result = $this->trafficService->syncProjectTraffic($project, 30);

        if ($result['success']) {
            return redirect()
                ->back()
                ->with('success', "Traffic attribution synchronized: {$result['records_synced']} snapshots updated.");
        }

        $errorMsg = implode(', ', $result['errors']);

        return redirect()
            ->back()
            ->with('error', "Traffic synchronization encountered issues: {$errorMsg}");
    }
}
