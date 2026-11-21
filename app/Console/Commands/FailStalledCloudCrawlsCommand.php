<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Crawls\Events\CrawlProgressUpdated;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Integrations\Services\RunnrDeploySmokeService;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Console\Command;

/**
 * Closes cloud crawls the worker stopped reporting on.
 *
 * The worker posts progress, or a heartbeat at least once a minute, for every
 * crawl it has accepted, and each callback bumps the run's updated_at. A run
 * that has gone quiet for longer than `coqui.worker.stall_minutes` was lost:
 * the worker restarted or crashed, or the queued dispatch never ran. Left
 * alone it would show as running forever and block the project's next crawl.
 */
class FailStalledCloudCrawlsCommand extends Command
{
    protected $signature = 'coqui:fail-stalled-crawls';

    protected $description = 'Mark cloud crawls the worker has stopped reporting on as failed.';

    public function handle(): int
    {
        $minutes = max(5, (int) config('coqui.worker.stall_minutes', 30));
        $cutoff = now()->subMinutes($minutes);

        $failed = 0;

        // By id, since closing a run takes it out of the filter as we go.
        $stalled = CrawlRun::query()
            ->with('project')
            ->whereIn('source', ['worker', 'deploy_smoke'])
            ->whereIn('status', ['pending', 'running'])
            ->whereNull('finalized_at')
            ->where('updated_at', '<', $cutoff)
            ->lazyById(100);

        $stalled->each(function (CrawlRun $run) use ($cutoff, $minutes, &$failed): void {
            // Only close it if no callback has landed since the query ran.
            $updated = CrawlRun::query()
                ->whereKey($run->id)
                ->whereIn('status', ['pending', 'running'])
                ->whereNull('finalized_at')
                ->where('updated_at', '<', $cutoff)
                ->update(['status' => 'failed', 'finalized_at' => now(), 'updated_at' => now()]);

            if ($updated === 0) {
                return;
            }

            $failed++;
            $run->refresh();

            rescue(fn () => broadcast(new CrawlProgressUpdated(
                projectId: $run->project_id,
                externalCrawlId: $run->external_crawl_id,
                crawledUrls: (int) $run->total_urls,
                totalUrls: (int) $run->total_urls,
                errorCount: (int) $run->error_count,
                warningCount: (int) $run->warning_count,
                opportunityCount: (int) $run->opportunity_count,
                status: 'failed',
            )));

            if ($run->project !== null) {
                ProjectActivity::log(
                    project: $run->project,
                    eventType: 'crawl.failed',
                    title: 'Cloud crawl stopped responding',
                    description: sprintf(
                        'Cloud crawl [%s] sent no progress for %d minutes and was marked failed. You can start a new crawl.',
                        $run->external_crawl_id,
                        $minutes,
                    ),
                    properties: [
                        'crawl_id' => $run->external_crawl_id,
                        'reason' => 'stalled',
                    ],
                );
            }

            if ($run->source === 'deploy_smoke') {
                app(RunnrDeploySmokeService::class)->reportFailure(
                    $run,
                    "The crawl worker sent no progress for {$minutes} minutes.",
                );
            }
        });

        $this->info(sprintf('Marked %d stalled cloud crawl(s) as failed.', $failed));

        return self::SUCCESS;
    }
}
