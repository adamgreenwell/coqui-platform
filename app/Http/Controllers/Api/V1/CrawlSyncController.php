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

        $maxUrls = (int) config('coqui.sync.max_urls_per_chunk', 1000);
        $maxLinks = (int) config('coqui.sync.max_links_per_chunk', 2500);
        $maxIssues = (int) config('coqui.sync.max_issues_per_chunk', 2500);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'urls' => ['sometimes', 'array', "max:{$maxUrls}"],
            'urls.*.id' => ['nullable', 'integer'],
            'urls.*.url' => ['required', 'string', 'max:4096'],
            'urls.*.status' => ['nullable', 'integer', 'min:0', 'max:999'],
            'urls.*.contentType' => ['nullable', 'string', 'max:160'],
            'urls.*.title' => ['nullable', 'string'],
            'urls.*.metaDescription' => ['nullable', 'string'],
            'urls.*.h1' => ['nullable', 'string'],
            'urls.*.h1Count' => ['nullable', 'integer', 'min:0'],
            'urls.*.canonical' => ['nullable', 'string'],
            'urls.*.metaRobots' => ['nullable', 'string', 'max:255'],
            'urls.*.redirectTarget' => ['nullable', 'string'],
            'urls.*.redirectChain' => ['nullable', 'array'],
            'urls.*.wordCount' => ['nullable', 'integer', 'min:0'],
            'urls.*.depth' => ['nullable', 'integer', 'min:0'],
            'urls.*.responseTimeMs' => ['nullable', 'integer', 'min:0'],
            'urls.*.sizeBytes' => ['nullable', 'integer', 'min:0'],
            'urls.*.error' => ['nullable', 'string'],
            'urls.*.discoveredAt' => ['nullable', 'integer', 'min:0'],

            'links' => ['sometimes', 'array', "max:{$maxLinks}"],
            'links.*.fromUrl' => ['required', 'string', 'max:4096'],
            'links.*.toUrl' => ['required', 'string', 'max:4096'],
            'links.*.anchor' => ['nullable', 'string'],
            'links.*.rel' => ['nullable', 'string', 'max:120'],
            'links.*.internal' => ['required', 'boolean'],

            'issues' => ['sometimes', 'array', "max:{$maxIssues}"],
            'issues.*.url' => ['required', 'string', 'max:4096'],
            'issues.*.rule' => ['required', 'string', 'max:96'],
            'issues.*.severity' => ['required', 'in:error,warning,opportunity'],
            'issues.*.detail' => ['nullable', 'string'],
        ]);

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
