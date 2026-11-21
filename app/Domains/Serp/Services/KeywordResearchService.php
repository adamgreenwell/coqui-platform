<?php

declare(strict_types=1);

namespace App\Domains\Serp\Services;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\DataForSeo\DataForSeoClient;

class KeywordResearchService
{
    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly DataForSeoCacheRepository $cache,
    ) {}

    /**
     * Look up keyword overview with granular caching and 700-keyword chunking.
     *
     * @param  list<string>  $keywords
     * @return array{
     *     keywords: list<array<string, mixed>>,
     *     summary: array{
     *         total_keywords: int,
     *         total_volume: int,
     *         avg_difficulty: int|null,
     *         cached_count: int,
     *         fetched_count: int,
     *         intent_breakdown: array<string, int>,
     *     }
     * }
     */
    public function overview(
        array $keywords,
        ?Project $project = null,
        int $locationCode = 2840,
        string $languageCode = 'en',
        bool $bypassCache = false,
    ): array {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $k): string => strtolower(trim($k)),
            $keywords,
        ))));

        if ($normalized === []) {
            return [
                'keywords' => [],
                'summary' => [
                    'total_keywords' => 0,
                    'total_volume' => 0,
                    'avg_difficulty' => null,
                    'cached_count' => 0,
                    'fetched_count' => 0,
                    'intent_breakdown' => [
                        'informational' => 0,
                        'commercial' => 0,
                        'transactional' => 0,
                        'navigational' => 0,
                    ],
                ],
            ];
        }

        $hits = [];
        if (! $bypassCache) {
            $hits = $this->cache->getKeywordMetricsBatch($normalized, $locationCode, $languageCode);
        }

        $misses = array_values(array_diff($normalized, array_keys($hits)));
        $newMetrics = [];
        $noData = [];

        if ($misses !== []) {
            // DataForSEO Labs keyword_overview accepts up to 700 keywords per payload task
            foreach (array_chunk($misses, 700) as $chunk) {
                $response = $this->client->keywordOverviewLive($chunk, $project, $locationCode, $languageCode);
                $parsed = $this->parseOverviewItems($response, $locationCode, $languageCode);
                foreach ($parsed as $kw => $metric) {
                    $newMetrics[$kw] = $metric;
                }

                // A successful lookup that returned nothing for a keyword is
                // remembered too, so asking again doesn't pay for it again.
                if ($this->succeeded($response)) {
                    foreach ($chunk as $kw) {
                        if (! isset($parsed[$kw])) {
                            $noData[$kw] = $this->emptyMetrics($kw, $locationCode, $languageCode) + [
                                'no_data' => true,
                                'updated_at' => now()->toIso8601String(),
                            ];
                        }
                    }
                }
            }

            if ($newMetrics !== []) {
                $this->cache->putKeywordMetricsBatch($newMetrics, $locationCode, $languageCode);
            }
            if ($noData !== []) {
                $this->cache->putKeywordMetricsBatch(
                    $noData,
                    $locationCode,
                    $languageCode,
                    (int) config('coqui.dataforseo.cache_ttl_hours.keyword_no_data', 24 * 7),
                );
            }
        }

        $all = [];
        foreach ($normalized as $kw) {
            if (isset($hits[$kw])) {
                $all[] = array_merge($hits[$kw], ['cached' => true]);
            } elseif (isset($newMetrics[$kw])) {
                $all[] = array_merge($newMetrics[$kw], ['cached' => false]);
            } else {
                $all[] = $this->emptyMetrics($kw, $locationCode, $languageCode) + ['cached' => false];
            }
        }

        return [
            'keywords' => $all,
            'summary' => $this->calculateSummary($all, count($hits), count($misses)),
        ];
    }

    /**
     * Metrics for a keyword with no data.
     *
     * @return array<string, mixed>
     */
    private function emptyMetrics(string $keyword, int $locationCode, string $languageCode): array
    {
        return [
            'keyword' => $keyword,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
            'search_volume' => null,
            'cpc' => null,
            'competition' => null,
            'competition_level' => null,
            'keyword_difficulty' => null,
            'main_intent' => null,
            'secondary_intents' => [],
            'monthly_searches' => [],
        ];
    }

    /**
     * Whether the request and every task in it succeeded, so a keyword
     * missing from the results genuinely has no data.
     *
     * @param  array<string, mixed>  $response
     */
    private function succeeded(array $response): bool
    {
        $tasks = $response['tasks'] ?? null;
        if ((int) ($response['status_code'] ?? 0) !== 20000 || ! is_array($tasks) || $tasks === []) {
            return false;
        }

        foreach ($tasks as $task) {
            if (! is_array($task) || (int) ($task['status_code'] ?? 0) !== 20000) {
                return false;
            }
        }

        return true;
    }

    /**
     * Search related keywords and suggestions from a seed keyword.
     *
     * @return array{
     *     seed: string,
     *     keywords: list<array<string, mixed>>,
     *     summary: array{
     *         total_keywords: int,
     *         total_volume: int,
     *         avg_difficulty: int|null,
     *         cached_count: int,
     *         fetched_count: int,
     *         intent_breakdown: array<string, int>,
     *     }
     * }
     */
    public function related(
        string $keyword,
        ?Project $project = null,
        int $locationCode = 2840,
        string $languageCode = 'en',
        int $limit = 100,
        int $depth = 1,
        bool $bypassCache = false,
    ): array {
        $normalized = strtolower(trim($keyword));
        $response = $this->client->relatedKeywords(
            keyword: $normalized,
            project: $project,
            locationCode: $locationCode,
            languageCode: $languageCode,
            limit: $limit,
            depth: $depth,
            bypassCache: $bypassCache,
        );

        $parsed = $this->parseRelatedItems($response, $locationCode, $languageCode);

        // Populate individual keyword metrics into cache for future overview lookups
        if ($parsed !== []) {
            $metricsMap = [];
            foreach ($parsed as $item) {
                $kw = (string) ($item['keyword'] ?? '');
                if ($kw !== '') {
                    $metricsMap[$kw] = $item;
                }
            }
            if ($metricsMap !== []) {
                $this->cache->putKeywordMetricsBatch($metricsMap, $locationCode, $languageCode);
            }
        }

        return [
            'seed' => $normalized,
            'keywords' => $parsed,
            'summary' => $this->calculateSummary($parsed, 0, count($parsed)),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array<string, mixed>> Map of normalized_keyword => metrics
     */
    public function parseOverviewItems(array $response, int $locationCode, string $languageCode): array
    {
        $parsed = [];
        /** @var list<array<string, mixed>> $tasks */
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            /** @var list<array<string, mixed>> $results */
            $results = $task['result'] ?? [];
            foreach ($results as $result) {
                /** @var list<array<string, mixed>> $items */
                $items = $result['items'] ?? [];
                foreach ($items as $item) {
                    $keyword = strtolower(trim((string) ($item['keyword'] ?? '')));
                    if ($keyword === '') {
                        continue;
                    }

                    $info = is_array($item['keyword_info'] ?? null) ? $item['keyword_info'] : [];
                    $props = is_array($item['keyword_properties'] ?? null) ? $item['keyword_properties'] : [];
                    $intent = is_array($item['search_intent_info'] ?? null) ? $item['search_intent_info'] : [];

                    $parsed[$keyword] = [
                        'keyword' => $keyword,
                        'location_code' => $locationCode,
                        'language_code' => $languageCode,
                        'search_volume' => isset($info['search_volume']) ? (int) $info['search_volume'] : null,
                        'cpc' => isset($info['cpc']) ? (float) $info['cpc'] : null,
                        'competition' => isset($info['competition']) ? (float) $info['competition'] : null,
                        'competition_level' => isset($info['competition_level']) ? (string) $info['competition_level'] : null,
                        'keyword_difficulty' => isset($props['keyword_difficulty']) ? (int) $props['keyword_difficulty'] : null,
                        'main_intent' => isset($intent['main_intent']) ? strtolower((string) $intent['main_intent']) : null,
                        'secondary_intents' => is_array($intent['secondary_intents'] ?? null) ? $intent['secondary_intents'] : [],
                        'monthly_searches' => is_array($info['monthly_searches'] ?? null) ? $info['monthly_searches'] : [],
                        'updated_at' => now()->toIso8601String(),
                    ];
                }
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    public function parseRelatedItems(array $response, int $locationCode, string $languageCode): array
    {
        $parsed = [];
        /** @var list<array<string, mixed>> $tasks */
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            /** @var list<array<string, mixed>> $results */
            $results = $task['result'] ?? [];
            foreach ($results as $result) {
                /** @var list<array<string, mixed>> $items */
                $items = $result['items'] ?? [];
                foreach ($items as $item) {
                    $data = is_array($item['keyword_data'] ?? null) ? $item['keyword_data'] : $item;
                    $keyword = strtolower(trim((string) ($data['keyword'] ?? '')));
                    if ($keyword === '') {
                        continue;
                    }

                    $info = is_array($data['keyword_info'] ?? null) ? $data['keyword_info'] : [];
                    $props = is_array($data['keyword_properties'] ?? null) ? $data['keyword_properties'] : [];
                    $intent = is_array($data['search_intent_info'] ?? null) ? $data['search_intent_info'] : [];

                    $parsed[] = [
                        'keyword' => $keyword,
                        'location_code' => $locationCode,
                        'language_code' => $languageCode,
                        'search_volume' => isset($info['search_volume']) ? (int) $info['search_volume'] : null,
                        'cpc' => isset($info['cpc']) ? (float) $info['cpc'] : null,
                        'competition' => isset($info['competition']) ? (float) $info['competition'] : null,
                        'competition_level' => isset($info['competition_level']) ? (string) $info['competition_level'] : null,
                        'keyword_difficulty' => isset($props['keyword_difficulty']) ? (int) $props['keyword_difficulty'] : null,
                        'main_intent' => isset($intent['main_intent']) ? strtolower((string) $intent['main_intent']) : null,
                        'secondary_intents' => is_array($intent['secondary_intents'] ?? null) ? $intent['secondary_intents'] : [],
                        'monthly_searches' => is_array($info['monthly_searches'] ?? null) ? $info['monthly_searches'] : [],
                        'updated_at' => now()->toIso8601String(),
                        'cached' => false,
                    ];
                }
            }
        }

        return $parsed;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *     total_keywords: int,
     *     total_volume: int,
     *     avg_difficulty: int|null,
     *     cached_count: int,
     *     fetched_count: int,
     *     intent_breakdown: array<string, int>,
     * }
     */
    private function calculateSummary(array $items, int $cachedCount, int $fetchedCount): array
    {
        $totalVolume = 0;
        $difficultySum = 0;
        $difficultyCount = 0;
        $intentCounts = [
            'informational' => 0,
            'commercial' => 0,
            'transactional' => 0,
            'navigational' => 0,
        ];

        foreach ($items as $item) {
            $volume = $item['search_volume'] ?? null;
            if (is_numeric($volume)) {
                $totalVolume += (int) $volume;
            }

            $kd = $item['keyword_difficulty'] ?? null;
            if (is_numeric($kd)) {
                $difficultySum += (int) $kd;
                $difficultyCount++;
            }

            $intent = strtolower((string) ($item['main_intent'] ?? ''));
            if (isset($intentCounts[$intent])) {
                $intentCounts[$intent]++;
            }
        }

        return [
            'total_keywords' => count($items),
            'total_volume' => $totalVolume,
            'avg_difficulty' => $difficultyCount > 0 ? (int) round($difficultySum / $difficultyCount) : null,
            'cached_count' => $cachedCount,
            'fetched_count' => $fetchedCount,
            'intent_breakdown' => $intentCounts,
        ];
    }
}
