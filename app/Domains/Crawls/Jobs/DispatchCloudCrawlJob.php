<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Jobs;

use App\Domains\Crawls\Events\CrawlProgressUpdated;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DispatchCloudCrawlJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public readonly Project $project,
        public readonly string $crawlId,
        public readonly array $overrides = [],
    ) {
        $this->onQueue('crawls');
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
            $run->update(['status' => 'failed']);

            ProjectActivity::log(
                project: $this->project,
                action: 'crawl.failed',
                title: 'Cloud crawl dispatch failed',
                description: sprintf('Failed to dispatch cloud crawl [%s]: %s', $this->crawlId, $e->getMessage()),
                metadata: [
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

            throw $e;
        }
    }
}
