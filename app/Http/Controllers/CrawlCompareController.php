<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CrawlDiffService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CrawlCompareController extends Controller
{
    public function __construct(
        private readonly CrawlDiffService $diffService,
    ) {}

    public function show(Request $request, Workspace $workspace, Project $project): Response
    {
        Gate::authorize('view', $project);

        $availableRuns = $project->crawlRuns()
            ->latest('id')
            ->limit(30)
            ->get(['id', 'external_crawl_id', 'source', 'status', 'total_urls', 'error_count', 'warning_count', 'created_at', 'finalized_at'])
            ->map(fn (CrawlRun $r): array => [
                'id' => $r->external_crawl_id,
                'externalCrawlId' => $r->external_crawl_id,
                'source' => $r->source,
                'status' => $r->status,
                'totalUrls' => $r->total_urls,
                'errorCount' => $r->error_count,
                'warningCount' => $r->warning_count,
                'createdAt' => $r->created_at?->toIso8601String(),
                'finalizedAt' => $r->finalized_at?->toIso8601String(),
                'label' => sprintf('%s (%s) - %s URLs', $r->created_at?->format('M j, Y H:i') ?? 'Crawl', ucfirst(str_replace('_', ' ', $r->source)), number_format($r->total_urls)),
            ])
            ->all();

        $baselineParam = $request->input('baseline');
        $targetParam = $request->input('target');

        /** @var CrawlRun|null $baselineRun */
        $baselineRun = null;
        /** @var CrawlRun|null $targetRun */
        $targetRun = null;

        if ($baselineParam !== null && $targetParam !== null) {
            // Runs are addressed by their external UUID only; sequential IDs match nothing.
            $baselineRun = $this->findRun($project, $baselineParam);
            $targetRun = $this->findRun($project, $targetParam);
        } elseif (count($availableRuns) >= 2) {
            // Default target is latest run, baseline is previous run
            $targetRun = $project->crawlRuns()->latest('id')->first();
            $baselineRun = $targetRun !== null
                ? $project->crawlRuns()->where('id', '<', $targetRun->id)->latest('id')->first()
                : null;
        }

        $diff = null;
        if ($baselineRun !== null && $targetRun !== null) {
            $diff = $this->diffService->compare($baselineRun, $targetRun);
        }

        return Inertia::render('Crawls/Compare', [
            'workspace' => [
                'id' => $workspace->uuid,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'project' => [
                'id' => $project->uuid,
                'name' => $project->name,
                'domain' => $project->domain,
            ],
            'availableRuns' => $availableRuns,
            'selectedBaselineId' => $baselineRun?->external_crawl_id,
            'selectedTargetId' => $targetRun?->external_crawl_id,
            'diff' => $diff,
            'isReadOnly' => false,
        ]);
    }

    public function share(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'baseline_id' => ['required', 'uuid'],
            'target_id' => ['required', 'uuid'],
            'title' => ['nullable', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'in:7,30,90'],
        ]);

        $baselineRun = $this->findRun($project, $validated['baseline_id']) ?? abort(404);
        $targetRun = $this->findRun($project, $validated['target_id']) ?? abort(404);

        /** @var User|null $user */
        $user = $request->user();

        $expiresInDays = isset($validated['expires_in_days']) ? (int) $validated['expires_in_days'] : null;
        $title = isset($validated['title']) ? (string) $validated['title'] : null;

        $report = $this->diffService->createShareableReport(
            project: $project,
            baselineRun: $baselineRun,
            targetRun: $targetRun,
            title: $title,
            expiresInDays: $expiresInDays,
            user: $user,
        );

        $shareUrl = route('reports.crawls.diff', $report->share_token);

        return response()->json([
            'shareToken' => $report->share_token,
            'shareUrl' => $shareUrl,
            'expiresAt' => $report->expires_at?->toIso8601String(),
        ], 201);
    }

    private function findRun(Project $project, mixed $externalCrawlId): ?CrawlRun
    {
        if (! is_string($externalCrawlId) || ! Str::isUuid($externalCrawlId)) {
            return null;
        }

        /** @var CrawlRun|null */
        return $project->crawlRuns()->where('external_crawl_id', $externalCrawlId)->first();
    }
}
