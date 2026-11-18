<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use App\Domains\Projects\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DataForSeoClient
{
    public function __construct(
        private readonly DataForSeoCacheRepository $cache,
        private readonly DataForSeoSpendGuard $spendGuard,
    ) {}

    /**
     * Free account diagnostics endpoint (`v3/appendix/user_data`).
     *
     * @return array<string, mixed>
     */
    public function userData(): array
    {
        return $this->getFree('/v3/appendix/user_data');
    }

    /**
     * Cached DataForSEO Labs keyword overview lookup (max 700 keywords per task).
     *
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    public function keywordOverview(
        array $keywords,
        ?Project $project = null,
        int $locationCode = 2840,
        string $languageCode = 'en',
        bool $bypassCache = false,
    ): array {
        $endpoint = '/v3/dataforseo_labs/google/keyword_overview/live';
        $normalizedKeywords = array_values(array_unique(array_filter(array_map(
            static fn (string $k): string => strtolower(trim($k)),
            $keywords,
        ))));

        $signature = [
            'keywords' => $normalizedKeywords,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
        ];

        if (! $bypassCache) {
            $cached = $this->cache->get($endpoint, $signature);
            if ($cached !== null) {
                return $cached;
            }
        }

        $estimatedCost = (float) config('coqui.dataforseo.prices.labs_keyword_overview_task', 0.012)
            + (count($normalizedKeywords) * (float) config('coqui.dataforseo.prices.labs_keyword_overview_item', 0.00012));

        $this->spendGuard->assertCanSpend($estimatedCost, $project?->workspace);

        $payload = [[
            'keywords' => $normalizedKeywords,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
        ]];

        $response = $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );

        $ttl = (int) config('coqui.dataforseo.cache_ttl_hours.keyword_overview', 720);
        $this->cache->put($endpoint, $signature, $response, $ttl);

        return $response;
    }

    /**
     * Submit Standard-queue SERP tasks with a signed postback URL.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    public function submitStandardSerpTasks(
        array $tasks,
        Project $project,
        bool $isScheduled = false,
    ): array {
        $endpoint = '/v3/serp/google/organic/task_post';
        $unitCost = (float) config('coqui.dataforseo.prices.serp_standard_task', 0.0006);
        $estimatedCost = count($tasks) * $unitCost;

        $this->spendGuard->assertCanSpend($estimatedCost, $project->workspace, $isScheduled);

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $tasks,
            mode: 'standard',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );
    }

    /**
     * Free task collection call for Standard-queue results (`tasks_ready` or `task_get`).
     *
     * @return array<string, mixed>
     */
    public function getFree(string $endpoint): array
    {
        $retries = (int) config('coqui.dataforseo.free_read_retries', 1);
        $response = $this->http()
            ->retry($retries, 300)
            ->get($this->url($endpoint));

        $response->throw();

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        return $decoded;
    }

    /**
     * Retrieve completed task results for a Standard-queue task (free read).
     *
     * @return array<string, mixed>|null Returns decoded response if ready, null if still in progress.
     */
    public function getTaskResult(string $externalTaskId, string $endpoint = '/v3/serp/google/organic/task_get/advanced'): ?array
    {
        $path = rtrim($endpoint, '/').'/'.$externalTaskId;

        try {
            $response = $this->getFree($path);
        } catch (\Throwable) {
            return null;
        }

        $statusCode = (int) ($response['status_code'] ?? 0);
        if ($statusCode === 20000) {
            return $response;
        }

        return null;
    }

    /**
     * Never retry paid POST calls automatically on timeouts to prevent double-billing.
     *
     * @param  array<int, array<string, mixed>>  $payload
     * @return array<string, mixed>
     */
    private function postPaid(
        string $endpoint,
        array $payload,
        string $mode,
        float $estimatedCostUsd,
        ?Project $project = null,
    ): array {
        $response = $this->http()->post($this->url($endpoint), $payload);
        $status = $response->status();

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];
        $actualCost = isset($decoded['cost']) ? (float) $decoded['cost'] : $estimatedCostUsd;

        $this->spendGuard->recordSpend(
            endpoint: $endpoint,
            mode: $mode,
            estimatedCostUsd: $estimatedCostUsd,
            actualCostUsd: $actualCost,
            workspaceId: $project?->workspace_id,
            projectId: $project?->id,
            externalTaskId: $decoded['tasks'][0]['id'] ?? null,
            httpStatus: $status,
        );

        $response->throw();

        return $decoded;
    }

    private function http(): PendingRequest
    {
        $login = (string) config('coqui.dataforseo.login');
        $password = (string) config('coqui.dataforseo.password');

        if ($login === '' || $password === '') {
            throw new RuntimeException('DataForSEO credentials are not configured.');
        }

        return Http::withBasicAuth($login, $password)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('coqui.dataforseo.timeout', 60));
    }

    private function url(string $endpoint): string
    {
        $base = rtrim((string) config('coqui.dataforseo.base_url'), '/');

        return $base.'/'.ltrim($endpoint, '/');
    }
}
