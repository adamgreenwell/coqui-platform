<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Str;

class RunnrDeployWebhookHandler
{
    public function __construct(
        private readonly CoquiWorkerClient $worker,
    ) {}

    public function handleDeploy(string $runnrSiteId, ?string $deployUrl = null): ?CrawlRun
    {
        $project = Project::query()->where('runnr_site_id', $runnrSiteId)->first();
        if ($project === null) {
            return null;
        }

        $crawlId = (string) Str::uuid();
        $seed = $deployUrl ?? $project->canonical_url;

        /** @var CrawlRun $run */
        $run = CrawlRun::query()->create([
            'project_id' => $project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'deploy_smoke',
            'seed' => $seed,
            'seed_kind' => 'url',
            'status' => 'pending',
            'config' => [
                'maxDepth' => 2,
                'maxUrls' => (int) config('coqui.worker.smoke_max_urls', 100),
                'concurrency' => 4,
                'respectRobots' => true,
            ],
        ]);

        $this->worker->dispatchCrawl($project, $crawlId, [
            'seed' => $seed,
            'maxDepth' => 2,
            'maxUrls' => (int) config('coqui.worker.smoke_max_urls', 100),
        ]);

        return $run;
    }
}
