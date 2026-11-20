<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Backlinks\Services\BacklinkVerificationService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BacklinkWatchlistController extends Controller
{
    public function __construct(
        private readonly BacklinkVerificationService $verificationService,
    ) {}

    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        Gate::authorize('view', $project);

        $links = $project->monitoredBacklinks()
            ->orderBy('created_at', 'desc')
            ->get();

        $metrics = [
            'total' => $links->count(),
            'active' => $links->where('state', 'active')->count(),
            'lost' => $links->where('state', 'lost')->count(),
            'rel_changed' => $links->where('state', 'rel_changed')->count(),
            'error' => $links->where('state', 'error')->count(),
            'noindex' => $links->where('last_page_noindex', true)->count(),
        ];

        return Inertia::render('Backlinks/Watchlist', [
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
            'links' => $links->map(fn (MonitoredBacklink $link): array => [
                'id' => $link->uuid,
                'source_url' => $link->source_url,
                'target_url' => $link->target_url,
                'expected_anchor' => $link->expected_anchor,
                'expected_rel' => $link->expected_rel,
                'state' => $link->state,
                'last_http_status' => $link->last_http_status,
                'last_observed_rel' => $link->last_observed_rel,
                'last_page_noindex' => $link->last_page_noindex,
                'last_verified_at' => $link->last_verified_at?->toIso8601String(),
                'created_at' => $link->created_at?->toIso8601String(),
            ])->all(),
            'metrics' => $metrics,
        ]);
    }

    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'source_url' => ['required', 'url', 'max:2048'],
            'target_url' => ['nullable', 'url', 'max:2048'],
            'expected_anchor' => ['nullable', 'string', 'max:255'],
            'expected_rel' => ['nullable', 'string', 'in:dofollow,nofollow,sponsored,ugc'],
        ]);

        $targetUrl = ! empty($validated['target_url'])
            ? (string) $validated['target_url']
            : (string) $project->canonical_url;

        $link = $this->verificationService->addSingleLink(
            project: $project,
            sourceUrl: (string) $validated['source_url'],
            targetUrl: $targetUrl,
            expectedAnchor: ! empty($validated['expected_anchor']) ? (string) $validated['expected_anchor'] : null,
            expectedRel: ! empty($validated['expected_rel']) ? (string) $validated['expected_rel'] : 'dofollow',
        );

        // Verify immediately in background or synchronously
        $this->verificationService->verifySingleLink($link);

        ProjectActivity::log(
            project: $project,
            eventType: 'backlink.monitored_added',
            title: 'Added backlink to watchlist',
            description: sprintf('Added %s to link-rot monitor (target: %s).', $link->source_url, $link->target_url),
            properties: [
                'source_url' => $link->source_url,
                'target_url' => $link->target_url,
            ],
            user: $request->user(),
        );

        return redirect()->route('workspaces.projects.backlinks.watchlist.index', [$workspace, $project])
            ->with('success', sprintf('Added %s to active backlink watchlist.', $link->source_url));
    }

    public function verifyAll(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $updated = $this->verificationService->verifyProjectWatchlist($project);

        ProjectActivity::log(
            project: $project,
            eventType: 'backlinks.watchlist_verified',
            title: 'Watchlist verification ran',
            description: sprintf('Verified %d monitored backlinks for link rot, rel changes, and noindex tags.', $updated),
            properties: [
                'verified_count' => $updated,
            ],
            user: $request->user(),
        );

        return redirect()->route('workspaces.projects.backlinks.watchlist.index', [$workspace, $project])
            ->with('success', sprintf('Successfully verified %d monitored backlinks.', $updated));
    }

    public function verifySingle(Request $request, Workspace $workspace, Project $project, MonitoredBacklink $monitoredBacklink): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($monitoredBacklink->project_id === $project->id, 404);

        $state = $this->verificationService->verifySingleLink($monitoredBacklink);

        return redirect()->route('workspaces.projects.backlinks.watchlist.index', [$workspace, $project])
            ->with('success', sprintf('Verified backlink: status is now %s.', $state));
    }

    public function importDfs(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $count = $this->verificationService->importFromDataForSeo($project, 100);

        ProjectActivity::log(
            project: $project,
            eventType: 'backlinks.imported_from_dfs',
            title: 'Imported backlinks into watchlist',
            description: sprintf('Imported %d high-value backlinks into active link-rot monitoring.', $count),
            properties: [
                'imported_count' => $count,
            ],
            user: $request->user(),
        );

        return redirect()->route('workspaces.projects.backlinks.watchlist.index', [$workspace, $project])
            ->with('success', sprintf('Successfully imported %d backlinks into the watchlist.', $count));
    }

    public function importCsv(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $request->validate([
            'csv_file' => ['required', 'file', 'max:5120'],
        ]);

        $file = $request->file('csv_file');
        if ($file === null) {
            return redirect()->route('workspaces.projects.backlinks.watchlist.index', [$workspace, $project])
                ->with('error', 'No file was uploaded.');
        }

        $content = file_get_contents($file->getRealPath());
        if ($content === false || trim($content) === '') {
            return redirect()->route('workspaces.projects.backlinks.watchlist.index', [$workspace, $project])
                ->with('error', 'The uploaded CSV file was empty.');
        }

        $count = $this->verificationService->importFromCsv($project, $content);

        ProjectActivity::log(
            project: $project,
            eventType: 'backlinks.imported_csv',
            title: 'Imported backlinks from CSV',
            description: sprintf('Imported %d backlinks from CSV into active link-rot monitor.', $count),
            properties: [
                'imported_count' => $count,
            ],
            user: $request->user(),
        );

        return redirect()->route('workspaces.projects.backlinks.watchlist.index', [$workspace, $project])
            ->with('success', sprintf('Successfully imported %d backlinks from CSV into watchlist.', $count));
    }

    public function destroy(Request $request, Workspace $workspace, Project $project, MonitoredBacklink $monitoredBacklink): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($monitoredBacklink->project_id === $project->id, 404);

        $sourceUrl = $monitoredBacklink->source_url;
        $monitoredBacklink->delete();

        ProjectActivity::log(
            project: $project,
            eventType: 'backlink.monitored_deleted',
            title: 'Removed backlink from watchlist',
            description: sprintf('Removed %s from link-rot monitor.', $sourceUrl),
            properties: [
                'source_url' => $sourceUrl,
            ],
            user: $request->user(),
        );

        return redirect()->route('workspaces.projects.backlinks.watchlist.index', [$workspace, $project])
            ->with('success', 'Backlink removed from watchlist.');
    }
}
