<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Billing\PlanFeature;
use App\Domains\Crawls\CrawlSource;
use App\Domains\Crawls\Jobs\DispatchCloudCrawlJob;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Support\Str;

class RunnrDeployWebhookHandler
{
    public function __construct(
        private readonly CoquiWorkerClient $worker,
        private readonly RunnrDeploySmokeService $smoke,
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

        // A redelivered deploy (same deploy ID) gets the run already started
        // for it rather than a second crawl.
        $deployId = $deployMetadata['deploy_id'] ?? null;
        if ($deployId !== null && $deployId !== '') {
            /** @var CrawlRun|null $existing */
            $existing = CrawlRun::query()
                ->where('project_id', $project->id)
                ->where('source', CrawlSource::DeploySmoke->value)
                ->where('config->deploy->deploy_id', $deployId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $crawlId = (string) Str::uuid();
        $seed = $deployUrl ?? $project->canonical_url;
        $smokeMaxUrls = (int) config('coqui.worker.smoke_max_urls', 100);

        /** @var CrawlRun $run */
        $run = CrawlRun::query()->create([
            'project_id' => $project->id,
            'external_crawl_id' => $crawlId,
            'source' => CrawlSource::DeploySmoke->value,
            'seed' => $seed,
            'seed_kind' => 'url',
            'status' => 'pending',
            'config' => [
                'maxDepth' => 2,
                'maxUrls' => $smokeMaxUrls,
                'concurrency' => 4,
                // robots.txt is checked directly by the smoke evaluation, so
                // a deploy that blocks crawlers still gets its pages checked.
                'respectRobots' => false,
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

        // Runnr gets its 2xx straight away; a worker that's missing or down
        // fails the run and Runnr hears about it through the callback.
        if (! $this->worker->isConfigured()) {
            $run->update(['status' => 'failed', 'finalized_at' => now()]);
            $this->smoke->reportFailure($run, 'The crawl worker is not set up on this server.');

            return $run;
        }

        DispatchCloudCrawlJob::dispatch($project, $crawlId, [
            'seed' => $seed,
            'maxDepth' => 2,
            'maxUrls' => $smokeMaxUrls,
            'respectRobots' => false,
        ]);

        return $run;
    }
}
