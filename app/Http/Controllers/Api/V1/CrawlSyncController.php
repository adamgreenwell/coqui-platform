<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Crawls\Actions\IngestDesktopCrawlSnapshot;
use App\Domains\Crawls\DTOs\CrawlSyncPayload;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CrawlDiffService;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CrawlSyncController extends Controller
{
    public function __construct(
        private readonly IngestDesktopCrawlSnapshot $ingest,
        private readonly CrawlDiffService $diffService,
    ) {}

    public function compare(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $baselineParam = $request->input('baseline');
        $targetParam = $request->input('target');

        if ($baselineParam === null || $targetParam === null) {
            return response()->json([
                'message' => 'Both baseline and target crawl parameters are required.',
            ], 422);
        }

        /** @var CrawlRun $baselineRun */
        $baselineRun = $project->crawlRuns()
            ->where(function ($q) use ($baselineParam): void {
                $q->where('external_crawl_id', $baselineParam);
                if (ctype_digit((string) $baselineParam)) {
                    $q->orWhere('id', (int) $baselineParam);
                }
            })
            ->firstOrFail();

        /** @var CrawlRun $targetRun */
        $targetRun = $project->crawlRuns()
            ->where(function ($q) use ($targetParam): void {
                $q->where('external_crawl_id', $targetParam);
                if (ctype_digit((string) $targetParam)) {
                    $q->orWhere('id', (int) $targetParam);
                }
            })
            ->firstOrFail();

        $diff = $this->diffService->compare($baselineRun, $targetRun);

        return response()->json($diff);
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $source = $request->input('source');
        $status = $request->input('status');

        $query = $project->crawlRuns()->latest('id');

        if (is_string($source) && in_array($source, ['desktop', 'worker', 'deploy_smoke'], true)) {
            $query->where('source', $source);
        }

        if (is_string($status) && in_array($status, ['pending', 'running', 'paused', 'completed', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        $runs = $paginator->getCollection()->map(fn (CrawlRun $r): array => [
            'id' => $r->id,
            'externalCrawlId' => $r->external_crawl_id,
            'source' => $r->source,
            'seed' => $r->seed,
            'seedKind' => $r->seed_kind,
            'status' => $r->status,
            'totalUrls' => $r->total_urls,
            'errorCount' => $r->error_count,
            'warningCount' => $r->warning_count,
            'opportunityCount' => $r->opportunity_count,
            'clientCreatedAtMs' => $r->client_created_at_ms,
            'clientStartedAtMs' => $r->client_started_at_ms,
            'clientFinishedAtMs' => $r->client_finished_at_ms,
            'finalizedAt' => $r->finalized_at?->toIso8601String(),
            'createdAt' => $r->created_at?->toIso8601String(),
            'updatedAt' => $r->updated_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $runs,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, Project $project, string $crawlId): JsonResponse
    {
        Gate::authorize('view', $project);

        /** @var CrawlRun $run */
        $run = $project->crawlRuns()
            ->where(function ($q) use ($crawlId): void {
                $q->where('external_crawl_id', $crawlId);
                if (ctype_digit($crawlId)) {
                    $q->orWhere('id', (int) $crawlId);
                }
            })
            ->firstOrFail();

        $issueSummary = DB::table('crawl_issues')
            ->select('severity', 'rule', DB::raw('count(*) as count'))
            ->where('crawl_run_id', $run->id)
            ->groupBy('severity', 'rule')
            ->orderByDesc('count')
            ->get()
            ->map(fn (object $row): array => [
                'severity' => (string) $row->severity,
                'rule' => (string) $row->rule,
                'count' => (int) $row->count,
            ])
            ->all();

        return response()->json([
            'id' => $run->id,
            'externalCrawlId' => $run->external_crawl_id,
            'projectId' => $run->project_id,
            'source' => $run->source,
            'seed' => $run->seed,
            'seedKind' => $run->seed_kind,
            'status' => $run->status,
            'config' => $run->config,
            'totalUrls' => $run->total_urls,
            'errorCount' => $run->error_count,
            'warningCount' => $run->warning_count,
            'opportunityCount' => $run->opportunity_count,
            'clientCreatedAtMs' => $run->client_created_at_ms,
            'clientStartedAtMs' => $run->client_started_at_ms,
            'clientFinishedAtMs' => $run->client_finished_at_ms,
            'finalizedAt' => $run->finalized_at?->toIso8601String(),
            'issueSummary' => $issueSummary,
            'createdAt' => $run->created_at?->toIso8601String(),
            'updatedAt' => $run->updated_at?->toIso8601String(),
        ]);
    }

    public function sync(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'id' => ['required', 'uuid'],
            'seed' => ['required', 'string', 'max:2048'],
            'seedKind' => ['required', 'in:url,sitemap,list'],
            'status' => ['required', 'in:pending,running,paused,completed,failed,cancelled'],
            'config' => ['required', 'array'],
            'createdAt' => ['required', 'integer', 'min:0'],
            'startedAt' => ['nullable', 'integer', 'min:0'],
            'finishedAt' => ['nullable', 'integer', 'min:0'],
            'totalUrls' => ['nullable', 'integer', 'min:0'],
            'source' => ['nullable', 'in:desktop,worker,deploy_smoke'],
        ]);

        $run = $this->ingest->upsertRun($project, CrawlSyncPayload::fromValidated($validated));

        return response()->json([
            'id' => $run->id,
            'externalCrawlId' => $run->external_crawl_id,
            'status' => $run->status,
        ], 201);
    }

    public function ingestChunk(Request $request, Project $project, string $crawlId): JsonResponse
    {
        Gate::authorize('update', $project);

        $run = $project->crawlRuns()
            ->where('external_crawl_id', $crawlId)
            ->firstOrFail();

        /** @var array<string, mixed> $validated */
        $validated = $request->validate(CrawlSyncPayload::chunkRules());

        /** @var list<array<string, mixed>> $urls */
        $urls = $validated['urls'] ?? [];
        /** @var list<array<string, mixed>> $links */
        $links = $validated['links'] ?? [];
        /** @var list<array<string, mixed>> $issues */
        $issues = $validated['issues'] ?? [];

        $counts = $this->ingest->ingestChunk($run, $urls, $links, $issues);

        return response()->json([
            'externalCrawlId' => $run->external_crawl_id,
            'ingested' => $counts,
        ]);
    }

    public function finalize(Request $request, Project $project, string $crawlId): JsonResponse
    {
        Gate::authorize('update', $project);

        $run = $project->crawlRuns()
            ->where('external_crawl_id', $crawlId)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['nullable', 'in:completed,paused,failed,cancelled'],
        ]);

        $finalized = $this->ingest->finalize($run, $validated['status'] ?? null);

        return response()->json([
            'externalCrawlId' => $finalized->external_crawl_id,
            'status' => $finalized->status,
            'totalUrls' => $finalized->total_urls,
            'errors' => $finalized->error_count,
            'warnings' => $finalized->warning_count,
            'opportunities' => $finalized->opportunity_count,
        ]);
    }
}
