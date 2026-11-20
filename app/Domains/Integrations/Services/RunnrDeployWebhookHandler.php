<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Billing\PlanFeature;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Support\Str;

class RunnrDeployWebhookHandler
{
    public function __construct(
        private readonly CoquiWorkerClient $worker,
    ) {}

    /**
     * @param  array{
     *     deploy_id?: string|null,
     *     environment?: string|null,
     *     commit_sha?: string|null,
     *     callback_url?: string|null,
     * }  $deployMetadata
     */
    public function handleDeploy(
        string $runnrSiteId,
        ?string $deployUrl = null,
        array $deployMetadata = [],
    ): ?CrawlRun {
        $project = Project::query()->with('workspace')->where('runnr_site_id', $runnrSiteId)->first();
        if ($project === null) {
            return null;
        }

        // Smoke crawls cost worker time, so they only run for entitled workspaces.
        if ($project->workspace?->hasFeature(PlanFeature::RunnrSmokeTests) !== true) {
            return null;
        }

        $crawlId = (string) Str::uuid();
        $seed = $deployUrl ?? $project->canonical_url;
        $smokeMaxUrls = (int) config('coqui.worker.smoke_max_urls', 100);

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
                'maxUrls' => $smokeMaxUrls,
                'concurrency' => 4,
                'respectRobots' => true,
                'deploy' => [
                    'deploy_id' => $deployMetadata['deploy_id'] ?? null,
                    'environment' => $deployMetadata['environment'] ?? 'production',
                    'commit_sha' => $deployMetadata['commit_sha'] ?? null,
                    'callback_url' => $deployMetadata['callback_url'] ?? null,
                ],
            ],
        ]);

        ProjectActivity::log(
            project: $project,
            eventType: 'deploy.smoke_triggered',
            title: 'Runnr deploy smoke test triggered',
            description: 'Smoke crawl started for deploy '.($deployMetadata['deploy_id'] ?? $crawlId)." against {$seed}",
            properties: [
                'crawl_id' => $run->id,
                'external_crawl_id' => $crawlId,
                'deploy_id' => $deployMetadata['deploy_id'] ?? null,
                'environment' => $deployMetadata['environment'] ?? 'production',
                'commit_sha' => $deployMetadata['commit_sha'] ?? null,
                'seed' => $seed,
            ],
        );

        $this->worker->dispatchCrawl($project, $crawlId, [
            'seed' => $seed,
            'maxDepth' => 2,
            'maxUrls' => $smokeMaxUrls,
        ]);

        return $run;
    }
}
