<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Crawls\Jobs\DispatchCloudCrawlJob;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunScheduledCrawlsCommand extends Command
{
    protected $signature = 'coqui:run-scheduled-crawls
        {--project= : Optional project ID to dispatch immediately}
        {--force : Force dispatch even if not due according to cadence}';

    protected $description = 'Dispatch scheduled cloud site audits to coqui-worker.';

    public function handle(): int
    {
        $projectId = $this->option('project');
        $force = (bool) $this->option('force');

        $query = Project::query()->with('workspace');

        if ($projectId !== null) {
            $query->whereKey($projectId);
        } else {
            $query->where('crawl_cadence', '!=', 'manual');
        }

        $dispatched = 0;
        $skipped = 0;

        /** @var Project $project */
        foreach ($query->cursor() as $project) {
            if (! $force && ! $this->isDueForCrawl($project)) {
                $skipped++;

                continue;
            }

            // Check if there is already an active run
            $hasActiveRun = $project->crawlRuns()
                ->whereIn('status', ['pending', 'running'])
                ->exists();

            if ($hasActiveRun) {
                $this->line(sprintf('Project #%d [%s] already has an active crawl. Skipping.', $project->id, $project->domain));
                $skipped++;

                continue;
            }

            $workspaceLimit = $project->workspace?->cloud_crawl_url_limit;
            $defaultLimit = (int) config('coqui.worker.default_max_urls', 1000);
            $urlLimit = $workspaceLimit !== null && $workspaceLimit > 0 ? $workspaceLimit : $defaultLimit;

            $crawlId = (string) Str::uuid();

            CrawlRun::query()->create([
                'project_id' => $project->id,
                'external_crawl_id' => $crawlId,
                'source' => 'worker',
                'seed' => $project->canonical_url,
                'seed_kind' => 'url',
                'status' => 'pending',
                'config' => [
                    'maxDepth' => 3,
                    'maxUrls' => $urlLimit,
                    'sameHostOnly' => true,
                    'respectRobots' => true,
                ],
                'total_urls' => 0,
                'error_count' => 0,
                'warning_count' => 0,
                'opportunity_count' => 0,
            ]);

            DispatchCloudCrawlJob::dispatch($project, $crawlId, [
                'maxUrls' => $urlLimit,
                'seed' => $project->canonical_url,
            ])->onQueue('crawls');

            ProjectActivity::log(
                project: $project,
                action: 'crawl.scheduled',
                title: 'Scheduled cloud crawl queued',
                description: sprintf('Queued %s cloud crawl capped at %d URLs.', $project->crawl_cadence, $urlLimit),
                metadata: [
                    'crawl_id' => $crawlId,
                    'max_urls' => $urlLimit,
                    'cadence' => $project->crawl_cadence,
                ],
            );

            $this->info(sprintf('Dispatched cloud crawl [%s] for %s (cap: %d URLs)', $crawlId, $project->domain, $urlLimit));
            $dispatched++;
        }

        $this->info(sprintf('Finished: %d crawls dispatched, %d skipped.', $dispatched, $skipped));

        return self::SUCCESS;
    }

    private function isDueForCrawl(Project $project): bool
    {
        if ($project->last_crawled_at === null) {
            return true;
        }

        $now = Carbon::now();

        return match ($project->crawl_cadence) {
            'daily' => $project->last_crawled_at->lessThanOrEqualTo($now->subDay()),
            'monthly' => $project->last_crawled_at->lessThanOrEqualTo($now->subMonth()),
            default => $project->last_crawled_at->lessThanOrEqualTo($now->subWeek()), // weekly
        };
    }
}
