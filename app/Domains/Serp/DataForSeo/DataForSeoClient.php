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
     * Direct live keyword overview post without whole-batch cache (used by KeywordResearchService).
     *
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    public function keywordOverviewLive(
        array $keywords,
        ?Project $project = null,
        int $locationCode = 2840,
        string $languageCode = 'en',
    ): array {
        $endpoint = '/v3/dataforseo_labs/google/keyword_overview/live';
        $normalizedKeywords = array_values(array_unique(array_filter(array_map(
            static fn (string $k): string => strtolower(trim($k)),
            $keywords,
        ))));

        if ($normalizedKeywords === []) {
            return [];
        }

        $estimatedCost = (float) config('coqui.dataforseo.prices.labs_keyword_overview_task', 0.012)
            + (count($normalizedKeywords) * (float) config('coqui.dataforseo.prices.labs_keyword_overview_item', 0.00012));

        $this->spendGuard->assertCanSpend($estimatedCost, $project?->workspace);

        $payload = [[
            'keywords' => $normalizedKeywords,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
        ]];

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );
    }

    /**
     * DataForSEO Labs related keywords lookup for a seed keyword.
     *
     * @return array<string, mixed>
     */
    public function relatedKeywords(
        string $keyword,
        ?Project $project = null,
        int $locationCode = 2840,
        string $languageCode = 'en',
        int $limit = 100,
        int $depth = 1,
        bool $bypassCache = false,
    ): array {
        $endpoint = '/v3/dataforseo_labs/google/related_keywords/live';
        $normalizedKeyword = strtolower(trim($keyword));

        $signature = [
            'keyword' => $normalizedKeyword,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
            'limit' => $limit,
            'depth' => $depth,
        ];

        if (! $bypassCache) {
            $cached = $this->cache->get($endpoint, $signature);
            if ($cached !== null) {
                return $cached;
            }
        }

        $estimatedCost = (float) config('coqui.dataforseo.prices.labs_related_keywords_task', 0.012)
            + ($limit * (float) config('coqui.dataforseo.prices.labs_related_keywords_item', 0.00012));

        $this->spendGuard->assertCanSpend($estimatedCost, $project?->workspace);

        $payload = [[
            'keyword' => $normalizedKeyword,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
            'limit' => $limit,
            'depth' => $depth,
        ]];

        $response = $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );

        $ttl = (int) config('coqui.dataforseo.cache_ttl_hours.related_keywords', 720);
        $this->cache->put($endpoint, $signature, $response, $ttl);

        return $response;
    }

    /**
     * Domain intersection live lookup comparing two domains (target1 and target2).
     *
     * @return array<string, mixed>
     */
    public function domainIntersectionLive(
        string $target1,
        string $target2,
        ?Project $project = null,
        int $locationCode = 2840,
        string $languageCode = 'en',
        bool $intersections = true,
        int $limit = 100,
        bool $bypassCache = false,
    ): array {
        $endpoint = '/v3/dataforseo_labs/google/domain_intersection/live';
        $normTarget1 = Project::normalizeDomain($target1);
        $normTarget2 = Project::normalizeDomain($target2);

        $signature = [
            'target1' => $normTarget1,
            'target2' => $normTarget2,
            'location_code' => $locationCode,
            'language_code' => strtolower(trim($languageCode)),
            'intersections' => $intersections,
            'item_types' => ['organic'],
            'limit' => $limit,
        ];

        if (! $bypassCache) {
            $cached = $this->cache->get($endpoint, $signature);
            if ($cached !== null) {
                $cached['_from_cache'] = true;

                return $cached;
            }
        }

        $estimatedCost = (float) config('coqui.dataforseo.prices.labs_domain_intersection_task', 0.012)
            + ($limit * (float) config('coqui.dataforseo.prices.labs_domain_intersection_item', 0.00012));

        $this->spendGuard->assertCanSpend($estimatedCost, $project?->workspace);

        $payload = [[
            'target1' => $normTarget1,
            'target2' => $normTarget2,
            'location_code' => $locationCode,
            'language_code' => strtolower(trim($languageCode)),
            'intersections' => $intersections,
            'item_types' => ['organic'],
            'limit' => $limit,
            'order_by' => ['keyword_data.keyword_info.search_volume,desc'],
        ]];

        $response = $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );

        $statusCode = (int) ($response['status_code'] ?? 0);
        $firstTask = isset($response['tasks'][0]) && is_array($response['tasks'][0]) ? $response['tasks'][0] : [];
        $taskStatusCode = (int) ($firstTask['status_code'] ?? 0);

        if ($statusCode !== 20000 || $taskStatusCode !== 20000) {
            $errorMsg = (string) ($firstTask['status_message'] ?? $response['status_message'] ?? 'DataForSEO domain intersection request failed.');

            throw new RuntimeException($errorMsg);
        }

        $ttl = (int) config('coqui.dataforseo.cache_ttl_hours.domain_intersection', 24 * 14);
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
