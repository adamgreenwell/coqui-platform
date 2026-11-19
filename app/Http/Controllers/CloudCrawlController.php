<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Crawls\Jobs\DispatchCloudCrawlJob;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CloudCrawlController extends Controller
{
    public function dispatch(Request $request, Workspace $workspace, Project $project): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $project);

        $hasActiveRun = $project->crawlRuns()
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($hasActiveRun) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'A crawl run is already pending or running for this project.',
                ], 409);
            }

            return back()->with('error', 'A crawl run is already pending or running for this project.');
        }

        $workspaceLimit = $workspace->cloud_crawl_url_limit;
        $defaultLimit = (int) config('coqui.worker.default_max_urls', 1000);
        $urlLimit = $workspaceLimit > 0 ? $workspaceLimit : $defaultLimit;

        $requestedLimit = $request->input('max_urls');
        if ($requestedLimit !== null && is_numeric((string) $requestedLimit)) {
            $urlLimit = min(max((int) $requestedLimit, 10), $urlLimit);
        }

        $crawlId = (string) Str::uuid();

        /** @var CrawlRun $run */
        $run = CrawlRun::query()->create([
            'project_id' => $project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'worker',
            'seed' => $project->canonical_url,
            'seed_kind' => 'url',
            'status' => 'pending',
            'config' => [
                'maxDepth' => 3,
                'maxUrls' => $urlLimit,
                'sameHostOnly' => true,
                'respectRobots' => true,
            ],
            'total_urls' => 0,
            'error_count' => 0,
            'warning_count' => 0,
            'opportunity_count' => 0,
        ]);

        DispatchCloudCrawlJob::dispatch($project, $crawlId, [
            'maxUrls' => $urlLimit,
            'seed' => $project->canonical_url,
        ])->onQueue('crawls');

        ProjectActivity::log(
            project: $project,
            eventType: 'crawl.dispatched',
            title: 'Cloud audit dispatched',
            description: sprintf('Cloud crawl [%s] queued (cap: %d URLs).', $crawlId, $urlLimit),
            properties: [
                'crawl_id' => $crawlId,
                'max_urls' => $urlLimit,
            ],
        );

        if ($request->wantsJson()) {
            return response()->json([
                'crawlId' => $crawlId,
                'status' => 'pending',
                'maxUrls' => $urlLimit,
                'message' => 'Cloud crawl successfully dispatched.',
            ], 202);
        }

        return back()->with('success', sprintf('Cloud crawl queued (cap: %d URLs).', $urlLimit));
    }
}
