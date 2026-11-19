<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Backlinks\Services\BacklinkProfileService;
use App\Domains\Backlinks\Services\BacklinkTimelineService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BacklinkController extends Controller
{
    public function __construct(
        private readonly BacklinkProfileService $backlinkService,
        private readonly BacklinkTimelineService $timelineService,
    ) {}

    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        Gate::authorize('view', $project);

        $days = (int) $request->input('days', 90);
        if ($days <= 0) {
            $days = 90;
        }

        $profile = $this->backlinkService->getBacklinkProfile($project, false);
        $timelineData = $this->timelineService->getTimelineData($project, $days, false);

        return Inertia::render('Backlinks/Index', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
                'canonicalUrl' => $project->canonical_url,
            ],
            'summary' => $profile['summary'],
            'referringDomains' => $profile['referring_domains'],
            'anchors' => $profile['anchors'],
            'topPages' => $profile['top_pages'],
            'brokenPages' => $profile['broken_pages_needing_redirects'],
            'crawlRun' => $profile['crawl_run'],
            'timeline' => $timelineData['timeline'],
            'newDomains' => $timelineData['new_domains'],
            'lostDomains' => $timelineData['lost_domains'],
            'velocity' => $timelineData['velocity'],
            'alerts' => $timelineData['alerts'],
            'selectedDays' => $days,
        ]);
    }

    public function refresh(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $days = (int) $request->input('days', 90);
        if ($days <= 0) {
            $days = 90;
        }

        $profile = $this->backlinkService->getBacklinkProfile($project, true);
        $this->timelineService->syncHistorySnapshots($project, $days);

        ProjectActivity::log(
            project: $project,
            eventType: 'backlinks.refreshed',
            title: 'Backlink profile refreshed',
            description: sprintf(
                'Refreshed backlink intelligence for %s (%d backlinks, %d referring domains).',
                $project->domain,
                $profile['summary']['backlinks'] ?? 0,
                $profile['summary']['referring_domains'] ?? 0
            ),
            properties: [
                'domain' => $project->domain,
                'backlinks' => $profile['summary']['backlinks'] ?? 0,
                'referring_domains' => $profile['summary']['referring_domains'] ?? 0,
                'rank' => $profile['summary']['rank'] ?? 0,
            ],
            user: $request->user(),
        );

        $redirectParams = [$workspace, $project];
        if ($request->has('days')) {
            $redirectParams['days'] = $days;
        }

        return redirect()->route('workspaces.projects.backlinks.index', $redirectParams)
            ->with('success', sprintf('Backlink profile and history timeline for %s successfully refreshed.', $project->domain));
    }
}
