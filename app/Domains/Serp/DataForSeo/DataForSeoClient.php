<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\ProviderNotConfiguredException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DataForSeoClient
{
    public function __construct(
        private readonly DataForSeoCacheRepository $cache,
        private readonly DataForSeoSpendGuard $spendGuard,
        private readonly DataForSeoResponseArchive $archive,
    ) {}

    /**
     * Whether login and password are set. Without them every call throws
     * ProviderNotConfiguredException.
     */
    public function isConfigured(): bool
    {
        return trim((string) config('coqui.dataforseo.login')) !== ''
            && trim((string) config('coqui.dataforseo.password')) !== '';
    }

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
     * Whether keywordOverview would answer these keywords from cache (and so
     * cost nothing).
     *
     * @param  list<string>  $keywords
     */
    public function keywordOverviewIsCached(array $keywords, int $locationCode = 2840, string $languageCode = 'en'): bool
    {
        [$endpoint, $signature] = $this->keywordOverviewCacheKey($keywords, $locationCode, $languageCode);

        return $this->cache->get($endpoint, $signature) !== null;
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
        [$endpoint, $signature] = $this->keywordOverviewCacheKey($keywords, $locationCode, $languageCode);
        /** @var list<string> $normalizedKeywords */
        $normalizedKeywords = $signature['keywords'];

        if (! $bypassCache) {
            $cached = $this->cache->get($endpoint, $signature);
            if ($cached !== null) {
                return $cached;
            }
        }

        $estimatedCost = (float) config('coqui.dataforseo.prices.labs_keyword_overview_task', 0.012)
            + (count($normalizedKeywords) * (float) config('coqui.dataforseo.prices.labs_keyword_overview_item', 0.00012));

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
     * @param  list<string>  $keywords
     * @return array{0: string, 1: array{keywords: list<string>, location_code: int, language_code: string}}
     */
    private function keywordOverviewCacheKey(array $keywords, int $locationCode, string $languageCode): array
    {
        $normalizedKeywords = array_values(array_unique(array_filter(array_map(
            static fn (string $k): string => strtolower(trim($k)),
            $keywords,
        ))));

        return ['/v3/dataforseo_labs/google/keyword_overview/live', [
            'keywords' => $normalizedKeywords,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
        ]];
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
     * Fetch live Google organic SERP advanced results. Cached across projects
     * for `cache_ttl_hours.serp_snapshot`; rank tracking uses the standard
     * queue, not this.
     *
     * @return array<string, mixed>
     */
    public function serpLive(
        string $keyword,
        Project $project,
        int $locationCode = 2840,
        string $languageCode = 'en',
        bool $bypassCache = false,
    ): array {
        $endpoint = '/v3/serp/google/organic/live/advanced';
        $signature = [
            'keyword' => strtolower(trim($keyword)),
            'location_code' => $locationCode,
            'language_code' => strtolower(trim($languageCode)),
        ];

        if (! $bypassCache) {
            $cached = $this->cache->get($endpoint, $signature);
            if ($cached !== null) {
                return $cached;
            }
        }

        $estimatedCost = (float) config('coqui.dataforseo.prices.serp_live_task', 0.002);

        $payload = [
            [
                'keyword' => $keyword,
                'location_code' => $locationCode,
                'language_code' => $languageCode,
            ],
        ];

        $response = $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );

        if ($this->tasksSucceeded($response)) {
            $ttl = (int) config('coqui.dataforseo.cache_ttl_hours.serp_snapshot', 24 * 7);
            $this->cache->put($endpoint, $signature, $response, $ttl);
        }

        return $response;
    }

    /**
     * Fetch backlink profile summary metrics for a target domain.
     *
     * @return array<string, mixed>
     */
    public function backlinksSummary(
        string $target,
        Project $project,
    ): array {
        $endpoint = '/v3/backlinks/summary/live';
        $estimatedCost = (float) config('coqui.dataforseo.prices.backlinks_task', 0.024);

        $payload = [
            [
                'target' => $target,
                'include_subdomains' => true,
            ],
        ];

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );
    }

    /**
     * Fetch referring domains for a target domain.
     *
     * @return array<string, mixed>
     */
    public function backlinksReferringDomains(
        string $target,
        Project $project,
        int $limit = 100,
        ?string $orderBy = 'rank,desc',
    ): array {
        $endpoint = '/v3/backlinks/referring_domains/live';
        $estimatedCost = (float) config('coqui.dataforseo.prices.backlinks_task', 0.024);

        $payload = [
            [
                'target' => $target,
                'limit' => $limit,
                'order_by' => [$orderBy ?? 'rank,desc'],
                'include_subdomains' => true,
            ],
        ];

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );
    }

    /**
     * Fetch anchor texts distribution for a target domain.
     *
     * @return array<string, mixed>
     */
    public function backlinksAnchors(
        string $target,
        Project $project,
        int $limit = 100,
        ?string $orderBy = 'backlinks,desc',
    ): array {
        $endpoint = '/v3/backlinks/anchors/live';
        $estimatedCost = (float) config('coqui.dataforseo.prices.backlinks_task', 0.024);

        $payload = [
            [
                'target' => $target,
                'limit' => $limit,
                'order_by' => [$orderBy ?? 'backlinks,desc'],
            ],
        ];

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );
    }

    /**
     * Fetch top linked pages for a target domain.
     *
     * @return array<string, mixed>
     */
    public function backlinksPages(
        string $target,
        Project $project,
        int $limit = 100,
        ?string $orderBy = 'rank,desc',
    ): array {
        $endpoint = '/v3/backlinks/pages/live';
        $estimatedCost = (float) config('coqui.dataforseo.prices.backlinks_task', 0.024);

        $payload = [
            [
                'target' => $target,
                'limit' => $limit,
                'order_by' => [$orderBy ?? 'rank,desc'],
            ],
        ];

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );
    }

    /**
     * Fetch historical backlinks and referring domain timeseries.
     *
     * @return array<string, mixed>
     */
    public function backlinksHistory(
        string $target,
        Project $project,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $endpoint = '/v3/backlinks/history/live';
        $estimatedCost = (float) config('coqui.dataforseo.prices.backlinks_task', 0.024);

        $payload = [
            [
                'target' => $target,
                'date_from' => $dateFrom ?? now()->subMonths(6)->toDateString(),
                'date_to' => $dateTo ?? now()->toDateString(),
            ],
        ];

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );
    }

    /**
     * Fetch individual backlinks for a target domain or page.
     *
     * @return array<string, mixed>
     */
    public function backlinksBacklinks(
        string $target,
        Project $project,
        int $limit = 100,
        ?string $orderBy = 'rank,desc',
    ): array {
        $endpoint = '/v3/backlinks/backlinks/live';
        $estimatedCost = (float) config('coqui.dataforseo.prices.backlinks_task', 0.024);

        $payload = [
            [
                'target' => $target,
                'limit' => min(1000, max(1, $limit)),
                'order_by' => [$orderBy ?? 'rank,desc'],
            ],
        ];

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );
    }

    /**
     * Discover referring domains linking to competitor targets while excluding project domain.
     * Cached for 14 days per competitor set.
     *
     * @param  array<string|int, string>  $targets  Map of index to competitor domain, e.g. ["1" => "comp1.com", "2" => "comp2.com"]
     * @param  list<string>  $excludeTargets  List of domains to exclude, e.g. [$projectDomain]
     * @return array<string, mixed>
     */
    public function backlinksDomainIntersection(
        array $targets,
        array $excludeTargets,
        Project $project,
        int $limit = 100,
        bool $forceFresh = false,
    ): array {
        $endpoint = '/v3/backlinks/domain_intersection/live';
        $estimatedCost = (float) config('coqui.dataforseo.prices.backlinks_task', 0.024);

        // Order-independent: the same competitor set in any order is one
        // request and one cache entry. Targets are re-indexed "1", "2", ... in
        // sorted order, which callers must use to read the intersection map.
        $targets = self::canonicalIntersectionTargets($targets);
        $excludeTargets = array_values(array_unique(array_map(static fn (string $t): string => strtolower(trim($t)), $excludeTargets)));
        sort($excludeTargets);

        $payload = [
            [
                'targets' => $targets,
                'exclude_targets' => $excludeTargets,
                'limit' => min(500, max(1, $limit)),
                'order_by' => ['rank,desc'],
            ],
        ];

        $cacheParams = [
            'targets' => $targets,
            'exclude_targets' => $excludeTargets,
            'limit' => $limit,
        ];

        if (! $forceFresh) {
            $cached = $this->cache->get($endpoint, $cacheParams);
            if ($cached !== null) {
                return $cached;
            }
        }

        $response = $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );

        $ttl = (int) config('coqui.dataforseo.cache_ttl_hours.backlinks_intersection', 24 * 14);
        $this->cache->put($endpoint, $cacheParams, $response, $ttl);

        return $response;
    }

    /**
     * Competitor domains lowercased, de-duplicated, sorted and keyed 1, 2, ...
     * (encoded as the provider's "1", "2", ... target map).
     *
     * @param  array<array-key, string>  $targets
     * @return array<int, string>
     */
    public static function canonicalIntersectionTargets(array $targets): array
    {
        $domains = array_values(array_unique(array_filter(array_map(
            static fn (string $t): string => strtolower(trim($t)),
            $targets,
        ), static fn (string $t): bool => $t !== '')));
        sort($domains);

        $keyed = [];
        foreach ($domains as $i => $domain) {
            $keyed[$i + 1] = $domain;
        }

        return $keyed;
    }

    /**
     * Discover broken (404/5xx) pages receiving backlinks on competitor domains for reclaim outreach.
     *
     * @return array<string, mixed>
     */
    public function backlinksBrokenPages(
        string $target,
        Project $project,
        int $limit = 100,
        bool $forceFresh = false,
    ): array {
        // Backlinks pointing at pages of the target that answer 4xx/5xx, so
        // only broken pages come back (the top pages by backlinks rarely
        // include any).
        $endpoint = '/v3/backlinks/backlinks/live';
        $estimatedCost = (float) config('coqui.dataforseo.prices.backlinks_task', 0.024);

        $payload = [
            [
                'target' => $target,
                'mode' => 'as_is',
                'filters' => ['is_broken', '=', true],
                'limit' => min(1000, max(1, $limit)),
                'order_by' => ['rank,desc'],
            ],
        ];

        $cacheParams = [
            'target' => strtolower(trim($target)),
            'limit' => $limit,
            'type' => 'broken_backlinks',
        ];

        if (! $forceFresh) {
            $cached = $this->cache->get($endpoint, $cacheParams);
            if ($cached !== null) {
                return $cached;
            }
        }

        $response = $this->postPaid(
            endpoint: $endpoint,
            payload: $payload,
            mode: 'live',
            estimatedCostUsd: $estimatedCost,
            project: $project,
        );

        $ttl = (int) config('coqui.dataforseo.cache_ttl_hours.backlinks_pages', 24 * 14);
        $this->cache->put($endpoint, $cacheParams, $response, $ttl);

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

        return $this->postPaid(
            endpoint: $endpoint,
            payload: $tasks,
            mode: 'standard',
            estimatedCostUsd: $estimatedCost,
            project: $project,
            isScheduled: $isScheduled,
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
        } catch (Throwable) {
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
     * The estimated cost is reserved in the ledger (atomically with the cap
     * check) before the request goes out and settled from the response's
     * `cost` afterwards. A request that never gets a response keeps the
     * estimate, since it may still have been billed.
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
        bool $isScheduled = false,
    ): array {
        // Throws when credentials are missing, before anything is reserved.
        $request = $this->http();

        $ledgerId = $this->spendGuard->reserve(
            endpoint: $endpoint,
            mode: $mode,
            estimatedCostUsd: $estimatedCostUsd,
            workspace: $project?->workspace,
            projectId: $project?->id,
            isScheduled: $isScheduled,
        );

        try {
            $response = $request->post($this->url($endpoint), $payload);
        } catch (Throwable $e) {
            $this->spendGuard->settle($ledgerId, DataForSeoSpendGuard::STATUS_UNKNOWN);
            $this->archive->record($ledgerId, $endpoint, $payload, null, null, $project, $e->getMessage());

            throw $e;
        }

        $status = $response->status();

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];
        $actualCost = isset($decoded['cost']) ? (float) $decoded['cost'] : $estimatedCostUsd;
        $taskId = $decoded['tasks'][0]['id'] ?? null;

        $this->spendGuard->settle(
            ledgerId: $ledgerId,
            status: DataForSeoSpendGuard::STATUS_SETTLED,
            actualCostUsd: $actualCost,
            httpStatus: $status,
            externalTaskId: is_string($taskId) ? $taskId : null,
        );
        $this->archive->record($ledgerId, $endpoint, $payload, $decoded, $status, $project);

        $response->throw();

        return $decoded;
    }

    /**
     * Whether a 200 response's tasks all succeeded (a failed task still comes
     * back as HTTP 200), so failures aren't cached.
     *
     * @param  array<string, mixed>  $response
     */
    private function tasksSucceeded(array $response): bool
    {
        if ((int) ($response['status_code'] ?? 20000) >= 40000) {
            return false;
        }

        $tasks = is_array($response['tasks'] ?? null) ? $response['tasks'] : [];
        if ($tasks === []) {
            return false;
        }

        foreach ($tasks as $task) {
            if (! is_array($task) || (int) ($task['status_code'] ?? 20000) >= 40000) {
                return false;
            }
        }

        return true;
    }

    private function http(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new ProviderNotConfiguredException('dataforseo');
        }

        $login = (string) config('coqui.dataforseo.login');
        $password = (string) config('coqui.dataforseo.password');

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
