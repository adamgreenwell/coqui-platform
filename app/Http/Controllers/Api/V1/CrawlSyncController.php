<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Crawls\Actions\IngestDesktopCrawlSnapshot;
use App\Domains\Crawls\DTOs\CrawlSyncPayload;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CrawlSyncController extends Controller
{
    public function __construct(
        private readonly IngestDesktopCrawlSnapshot $ingest,
    ) {}

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
