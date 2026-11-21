<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Jobs;

use App\Domains\Billing\PlanFeature;
use App\Domains\Crawls\Events\CrawlProgressUpdated;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Integrations\Services\RunnrDeploySmokeService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DispatchCloudCrawlJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'crawls';

    /**
     * Served by its own Horizon supervisor, so priority crawls never wait
     * behind the shared crawl backlog.
     */
    public const PRIORITY_QUEUE = 'crawls-priority';

    /**
     * One attempt: a failed dispatch marks the run failed, and a retry would
     * start a crawl the user has already been told failed.
     */
    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public readonly Project $project,
        public readonly string $crawlId,
        public readonly array $overrides = [],
    ) {
        $this->onQueue(self::queueFor($project));
    }

    /**
     * Workspaces whose plan includes the priority queue feature get the
     * dedicated crawl queue; everyone else shares the regular one.
     */
    public static function queueFor(Project $project): string
    {
        $workspace = $project->workspace;

        return $workspace !== null && $workspace->hasFeature(PlanFeature::PriorityQueue)
            ? self::PRIORITY_QUEUE
            : self::QUEUE;
    }

    public function handle(CoquiWorkerClient $workerClient): void
    {
        /** @var CrawlRun|null $run */
        $run = CrawlRun::query()
            ->where('external_crawl_id', $this->crawlId)
            ->first();

        if ($run === null) {
            return;
        }

        if ($run->status === 'pending') {
            $run->update(['status' => 'running']);

            rescue(fn () => broadcast(new CrawlProgressUpdated(
                projectId: $this->project->id,
                externalCrawlId: $this->crawlId,
                crawledUrls: (int) $run->total_urls,
                totalUrls: (int) $run->total_urls,
                errorCount: (int) $run->error_count,
                warningCount: (int) $run->warning_count,
                opportunityCount: (int) $run->opportunity_count,
                status: 'running',
            )));
        }

        try {
            $workerClient->dispatchCrawl($this->project, $this->crawlId, $this->overrides);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finalized_at' => now()]);

            $reason = $e instanceof ConnectionException
                ? 'The crawl worker could not be reached.'
                : $e->getMessage();

            ProjectActivity::log(
                project: $this->project,
                eventType: 'crawl.failed',
                title: 'Cloud crawl dispatch failed',
                description: sprintf('Failed to dispatch cloud crawl [%s]: %s', $this->crawlId, $reason),
                properties: [
                    'crawl_id' => $this->crawlId,
                    'error' => $e->getMessage(),
                ],
            );

            rescue(fn () => broadcast(new CrawlProgressUpdated(
                projectId: $this->project->id,
                externalCrawlId: $this->crawlId,
                crawledUrls: (int) $run->total_urls,
                totalUrls: (int) $run->total_urls,
                errorCount: (int) $run->error_count,
                warningCount: (int) $run->warning_count,
                opportunityCount: (int) $run->opportunity_count,
                status: 'failed',
            )));

            if ($run->source === 'deploy_smoke') {
                app(RunnrDeploySmokeService::class)->reportFailure($run, $reason);
            }

            throw $e;
        }
    }
}
