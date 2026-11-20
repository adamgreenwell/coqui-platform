<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Backlinks\Services\BacklinkIntersectService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\ReadOnlySpendScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BacklinkIntersectController extends Controller
{
    public function __construct(
        private readonly BacklinkIntersectService $intersectService,
    ) {}

    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        Gate::authorize('view', $project);

        $rawCompetitors = $request->input('competitors');
        /** @var list<string> $competitors */
        $competitors = [];
        if (is_array($rawCompetitors)) {
            $competitors = array_values(array_filter(array_map('strval', $rawCompetitors)));
        } elseif (is_string($rawCompetitors) && trim($rawCompetitors) !== '') {
            $competitors = array_values(array_filter(array_map('trim', explode(',', $rawCompetitors))));
        }

        $minRank = max(0, min(1000, (int) $request->input('min_rank', 0)));
        $maxSpam = max(0, min(100, (int) $request->input('max_spam', 100)));
        $minIntersections = max(1, min(3, (int) $request->input('min_intersections', 2)));

        $lookups = fn (): array => [
            $this->intersectService->getIntersectData(
                project: $project,
                competitors: $competitors,
                minRank: $minRank,
                maxSpam: $maxSpam,
                minIntersections: $minIntersections,
                fresh: false,
            ),
            $this->intersectService->getBrokenReclaimOpportunities(
                project: $project,
                competitors: $competitors,
                fresh: false,
            ),
        ];

        // Page views by read-only members never trigger new paid lookups.
        [$intersectData, $brokenReclaim] = Gate::allows('spend', $project)
            ? $lookups()
            : ReadOnlySpendScope::run($lookups);

        $configuredCompetitors = $project->competitors()->pluck('domain')->all();

        return Inertia::render('Backlinks/Intersect', [
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
            ],
            'configuredCompetitors' => $configuredCompetitors,
            'activeCompetitors' => $intersectData['competitors'],
            'hasCompetitors' => $intersectData['has_competitors'],
            'opportunities' => $intersectData['opportunities'],
            'brokenReclaim' => $brokenReclaim,
            'metrics' => $intersectData['metrics'],
            'filters' => [
                'competitors' => $intersectData['competitors'],
                'min_rank' => $minRank,
                'max_spam' => $maxSpam,
                'min_intersections' => $minIntersections,
            ],
        ]);
    }

    public function refresh(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $rawCompetitors = $request->input('competitors');
        /** @var list<string> $competitors */
        $competitors = [];
        if (is_array($rawCompetitors)) {
            $competitors = array_values(array_filter(array_map('strval', $rawCompetitors)));
        } elseif (is_string($rawCompetitors) && trim($rawCompetitors) !== '') {
            $competitors = array_values(array_filter(array_map('trim', explode(',', $rawCompetitors))));
        }

        $minRank = max(0, min(1000, (int) $request->input('min_rank', 0)));
        $maxSpam = max(0, min(100, (int) $request->input('max_spam', 100)));
        $minIntersections = max(1, min(3, (int) $request->input('min_intersections', 2)));

        $intersectData = $this->intersectService->getIntersectData(
            project: $project,
            competitors: $competitors,
            minRank: $minRank,
            maxSpam: $maxSpam,
            minIntersections: $minIntersections,
            fresh: true,
        );

        $this->intersectService->getBrokenReclaimOpportunities(
            project: $project,
            competitors: $competitors,
            fresh: true,
        );

        ProjectActivity::log(
            project: $project,
            eventType: 'backlinks.intersect_refreshed',
            title: 'Refreshed competitor link intersect',
            description: sprintf(
                'Queried fresh competitor backlink intersection for %s across %d competitors (%d opportunities found).',
                $project->domain,
                count($intersectData['competitors']),
                $intersectData['metrics']['total_opportunities']
            ),
            properties: [
                'competitors' => $intersectData['competitors'],
                'opportunities_count' => $intersectData['metrics']['total_opportunities'],
            ],
            user: $request->user(),
        );

        $redirectParams = [$workspace, $project];
        if ($competitors !== []) {
            $redirectParams['competitors'] = implode(',', $competitors);
        }

        return redirect()->route('workspaces.projects.backlinks.intersect.index', $redirectParams)
            ->with('success', 'Refreshed live competitor link intersection.');
    }
}
