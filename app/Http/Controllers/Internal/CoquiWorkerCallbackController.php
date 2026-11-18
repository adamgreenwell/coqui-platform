<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domains\Crawls\Actions\IngestDesktopCrawlSnapshot;
use App\Domains\Crawls\DTOs\CrawlSyncPayload;
use App\Domains\Crawls\Models\CrawlRun;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives progress chunks and terminal status from the headless `coqui-worker` sidecar.
 *
 * Authenticated by the VerifyCoquiWorkerToken middleware (shared COQUI_WORKER_TOKEN).
 * Only cloud-originated runs (`worker`, `deploy_smoke`) can be written through this
 * endpoint; desktop-synced runs are never mutable by the worker.
 */
class CoquiWorkerCallbackController extends Controller
{
    private const array WORKER_SOURCES = ['worker', 'deploy_smoke'];

    private const array TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

    public function __construct(
        private readonly IngestDesktopCrawlSnapshot $ingest,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'crawlId' => ['required', 'uuid'],
            'status' => ['required', 'in:running,completed,failed,cancelled'],
            ...CrawlSyncPayload::chunkRules(),
        ]);

        /** @var CrawlRun|null $run */
        $run = CrawlRun::query()
            ->where('external_crawl_id', (string) $validated['crawlId'])
            ->whereIn('source', self::WORKER_SOURCES)
            ->first();

        if ($run === null) {
            return response()->json(['message' => 'Crawl run not found.'], 404);
        }

        if ($run->finalized_at !== null) {
            return response()->json([
                'message' => 'Crawl run is already finalized.',
                'externalCrawlId' => $run->external_crawl_id,
                'status' => $run->status,
            ], 409);
        }

        /** @var list<array<string, mixed>> $urls */
        $urls = $validated['urls'] ?? [];
        /** @var list<array<string, mixed>> $links */
        $links = $validated['links'] ?? [];
        /** @var list<array<string, mixed>> $issues */
        $issues = $validated['issues'] ?? [];

        $counts = $this->ingest->ingestChunk($run, $urls, $links, $issues);

        $status = (string) $validated['status'];

        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            $run = $this->ingest->finalize($run, $status);
        } elseif ($run->status !== $status) {
            $run->update(['status' => $status]);
        }

        return response()->json([
            'externalCrawlId' => $run->external_crawl_id,
            'status' => $run->status,
            'ingested' => $counts,
            'totalUrls' => $run->total_urls,
            'errors' => $run->error_count,
            'warnings' => $run->warning_count,
            'opportunities' => $run->opportunity_count,
        ]);
    }
}
