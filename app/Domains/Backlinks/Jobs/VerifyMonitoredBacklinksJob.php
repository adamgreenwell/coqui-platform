<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Jobs;

use App\Domains\Backlinks\Services\BacklinkVerificationService;
use App\Domains\Projects\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Verifies a project's monitored backlinks a batch at a time. Each run checks
 * up to BATCH links within a time budget and queues the rest as the next
 * run, so no run outlives the queue's retry window and one project's slow
 * pages don't hold a worker for the whole watchlist.
 */
class VerifyMonitoredBacklinksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'backlinks';

    public const BATCH = 50;

    /**
     * Seconds from the start of a run (worker request included) after which
     * no new direct check starts. Pacing never waits past it, and a check
     * started just before it takes at most
     * BacklinkVerificationService::DIRECT_INSPECTION_MAX_SECONDS, so a run
     * ends inside $timeout and the queue's 90-second retry_after.
     */
    public const BUDGET_SECONDS = 45;

    public int $tries = 1;

    public int $timeout = 85;

    /**
     * @param  list<int>  $linkIds  Links still to verify, in order.
     */
    public function __construct(
        public readonly int $projectId,
        public readonly array $linkIds,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Queue verification of every monitored link in a project.
     *
     * @return int The number of links queued.
     */
    public static function dispatchForProject(Project $project): int
    {
        $ids = $project->monitoredBacklinks()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($ids !== []) {
            self::dispatch($project->id, $ids);
        }

        return count($ids);
    }

    public function handle(BacklinkVerificationService $verifier): void
    {
        $project = Project::query()->find($this->projectId);
        if (! $project instanceof Project) {
            return;
        }

        $startedAt = microtime(true);
        $batchIds = array_slice($this->linkIds, 0, self::BATCH);
        $rest = array_slice($this->linkIds, self::BATCH);

        $links = $project->monitoredBacklinks()->whereIn('id', $batchIds)->orderBy('id')->get();
        $unverified = $verifier->verifyLinks($project, $links, $startedAt + self::BUDGET_SECONDS);

        $next = array_merge(
            $unverified->map(static fn ($link): int => (int) $link->id)->values()->all(),
            $rest,
        );

        if ($next !== []) {
            self::dispatch($this->projectId, $next);
        }
    }
}
