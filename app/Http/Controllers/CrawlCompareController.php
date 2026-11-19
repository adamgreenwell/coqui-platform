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
                'id' => $r->id,
                'externalCrawlId' => $r->external_crawl_id,
                'source' => $r->source,
                'status' => $r->status,
                'totalUrls' => $r->total_urls,
                'errorCount' => $r->error_count,
                'warningCount' => $r->warning_count,
                'createdAt' => $r->created_at?->toIso8601String(),
                'finalizedAt' => $r->finalized_at?->toIso8601String(),
                'label' => sprintf('#%d (%s) - %s URLs', $r->id, $r->created_at?->format('M j, Y H:i') ?? '', number_format($r->total_urls)),
            ])
            ->all();

        $baselineParam = $request->input('baseline');
        $targetParam = $request->input('target');

        /** @var CrawlRun|null $baselineRun */
        $baselineRun = null;
        /** @var CrawlRun|null $targetRun */
        $targetRun = null;

        if ($baselineParam !== null && $targetParam !== null) {
            $baselineRun = $project->crawlRuns()
                ->where(function ($q) use ($baselineParam): void {
                    $q->where('external_crawl_id', $baselineParam);
                    if (ctype_digit((string) $baselineParam)) {
                        $q->orWhere('id', (int) $baselineParam);
                    }
                })
                ->first();

            $targetRun = $project->crawlRuns()
                ->where(function ($q) use ($targetParam): void {
                    $q->where('external_crawl_id', $targetParam);
                    if (ctype_digit((string) $targetParam)) {
                        $q->orWhere('id', (int) $targetParam);
                    }
                })
                ->first();
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
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
            ],
            'availableRuns' => $availableRuns,
            'selectedBaselineId' => $baselineRun?->id,
            'selectedTargetId' => $targetRun?->id,
            'diff' => $diff,
            'isReadOnly' => false,
        ]);
    }

    public function share(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'baseline_id' => ['required', 'integer'],
            'target_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'in:7,30,90'],
        ]);

        /** @var CrawlRun $baselineRun */
        $baselineRun = $project->crawlRuns()->findOrFail((int) $validated['baseline_id']);
        /** @var CrawlRun $targetRun */
        $targetRun = $project->crawlRuns()->findOrFail((int) $validated['target_id']);

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
}
