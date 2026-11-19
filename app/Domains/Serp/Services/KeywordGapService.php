<?php

declare(strict_types=1);

namespace App\Domains\Serp\Services;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use InvalidArgumentException;

class KeywordGapService
{
    public function __construct(
        private readonly DataForSeoClient $client,
    ) {}

    /**
     * Analyze competitor keyword gaps against 1 to 3 competitors.
     *
     * @param  list<string>  $competitorDomains
     * @return array{
     *     keywords: list<array<string, mixed>>,
     *     competitors: list<string>,
     *     summary: array{
     *         total_keywords: int,
     *         missing_count: int,
     *         weak_count: int,
     *         shared_count: int,
     *         strong_count: int,
     *         total_volume: int,
     *         avg_difficulty: int|null,
     *         cached_tasks_count: int,
     *         total_tasks_count: int,
     *     }
     * }
     */
    public function analyze(
        Project $project,
        array $competitorDomains,
        int $locationCode = 2840,
        string $languageCode = 'en',
        string $gapMode = 'all',
        int $limitPerCompetitor = 100,
        bool $bypassCache = false,
    ): array {
        $normalizedCompetitors = array_values(array_unique(array_filter(array_map(
            static fn (string $d): string => Project::normalizeDomain($d),
            $competitorDomains,
        ))));

        if (count($normalizedCompetitors) < 1 || count($normalizedCompetitors) > 3) {
            throw new InvalidArgumentException('Keyword gap analysis requires between 1 and 3 competitor domains.');
        }

        $keywordsMap = [];
        $cachedTasks = 0;
        $totalTasks = 0;

        foreach ($normalizedCompetitors as $compDomain) {
            if ($compDomain === $project->domain) {
                continue;
            }

            // 1. Missing keywords where competitor ranks and project domain does not
            if ($gapMode === 'all' || $gapMode === 'missing') {
                $totalTasks++;
                $missingResponse = $this->client->domainIntersectionLive(
                    target1: $compDomain,
                    target2: $project->domain,
                    project: $project,
                    locationCode: $locationCode,
                    languageCode: $languageCode,
                    intersections: false,
                    limit: $limitPerCompetitor,
                    bypassCache: $bypassCache,
                );

                if (! empty($missingResponse['_from_cache'])) {
                    $cachedTasks++;
                }

                $this->ingestDomainIntersectionItems(
                    items: $this->extractItemsFromResponse($missingResponse),
                    keywordsMap: $keywordsMap,
                    compDomain: $compDomain,
                    isMissingQuery: true,
                    locationCode: $locationCode,
                    languageCode: $languageCode,
                );
            }

            // 2. Overlapping keywords where both project and competitor rank
            if ($gapMode === 'all' || in_array($gapMode, ['weak', 'shared', 'strong'], true)) {
                $totalTasks++;
                $intersectResponse = $this->client->domainIntersectionLive(
                    target1: $project->domain,
                    target2: $compDomain,
                    project: $project,
                    locationCode: $locationCode,
                    languageCode: $languageCode,
                    intersections: true,
                    limit: $limitPerCompetitor,
                    bypassCache: $bypassCache,
                );

                if (! empty($intersectResponse['_from_cache'])) {
                    $cachedTasks++;
                }

                $this->ingestDomainIntersectionItems(
                    items: $this->extractItemsFromResponse($intersectResponse),
                    keywordsMap: $keywordsMap,
                    compDomain: $compDomain,
                    isMissingQuery: false,
                    locationCode: $locationCode,
                    languageCode: $languageCode,
                );
            }
        }

        // Post-process, compute opportunities, and classify gap types
        $allProcessed = [];
        $missingCount = 0;
        $weakCount = 0;
        $sharedCount = 0;
        $strongCount = 0;
        $totalVolume = 0;
        $difficultySum = 0;
        $difficultyCount = 0;

        foreach ($keywordsMap as $entry) {
            $projectRank = $entry['project_rank'];
            $compRanks = array_filter(
                array_column($entry['competitor_ranks'], 'rank'),
                static fn (?int $r): bool => $r !== null,
            );

            $bestCompRank = $compRanks !== [] ? min($compRanks) : null;
            $entry['best_competitor_rank'] = $bestCompRank;

            // Classify Gap Type
            if ($projectRank === null || $projectRank > 100) {
                if ($bestCompRank !== null && $bestCompRank <= 20) {
                    $gapType = 'missing';
                } else {
                    $gapType = 'untapped';
                }
            } elseif ($bestCompRank !== null && $bestCompRank < $projectRank) {
                $gapType = 'weak';
            } elseif ($bestCompRank !== null && $bestCompRank === $projectRank) {
                $gapType = 'shared';
            } else {
                $gapType = 'strong';
            }

            $entry['gap_type'] = $gapType;

            // Compute Opportunity Score
            $opportunity = self::calculateOpportunityScore(
                volume: $entry['search_volume'] ?? 0,
                kd: $entry['keyword_difficulty'],
                bestCompRank: $bestCompRank ?? 100,
            );
            $entry['opportunity_score'] = $opportunity;

            // Global counts
            if ($gapType === 'missing') {
                $missingCount++;
            } elseif ($gapType === 'weak') {
                $weakCount++;
            } elseif ($gapType === 'shared') {
                $sharedCount++;
            } elseif ($gapType === 'strong') {
                $strongCount++;
            }

            if (($entry['search_volume'] ?? 0) > 0) {
                $totalVolume += (int) $entry['search_volume'];
            }
            if ($entry['keyword_difficulty'] !== null) {
                $difficultySum += (int) $entry['keyword_difficulty'];
                $difficultyCount++;
            }

            $allProcessed[] = $entry;
        }

        // Filter by gapMode if specific mode requested
        $filtered = array_values(array_filter($allProcessed, static function (array $item) use ($gapMode): bool {
            if ($gapMode === 'all') {
                return true;
            }
            if ($gapMode === 'missing') {
                return in_array($item['gap_type'], ['missing', 'untapped'], true);
            }

            return $item['gap_type'] === $gapMode;
        }));

        // Sort by opportunity score descending by default
        usort($filtered, static function (array $a, array $b): int {
            if ($b['opportunity_score'] !== $a['opportunity_score']) {
                return $b['opportunity_score'] <=> $a['opportunity_score'];
            }

            return ($b['search_volume'] ?? 0) <=> ($a['search_volume'] ?? 0);
        });

        return [
            'keywords' => $filtered,
            'competitors' => $normalizedCompetitors,
            'summary' => [
                'total_keywords' => count($filtered),
                'missing_count' => $missingCount,
                'weak_count' => $weakCount,
                'shared_count' => $sharedCount,
                'strong_count' => $strongCount,
                'total_volume' => $totalVolume,
                'avg_difficulty' => $difficultyCount > 0 ? (int) round($difficultySum / $difficultyCount) : null,
                'cached_tasks_count' => $cachedTasks,
                'total_tasks_count' => $totalTasks,
            ],
        ];
    }

    /**
     * Estimated organic click-through-rate based on competitor search position.
     */
    public static function estimateCtr(int $rank): float
    {
        return match (true) {
            $rank <= 1 => 0.32,
            $rank <= 2 => 0.16,
            $rank <= 3 => 0.10,
            $rank <= 5 => 0.06,
            $rank <= 10 => 0.03,
            $rank <= 20 => 0.015,
            $rank <= 50 => 0.005,
            default => 0.001,
        };
    }

    /**
     * Opportunity score (0 - 100) combining search volume, competitor ranking, and keyword difficulty.
     */
    public static function calculateOpportunityScore(int $volume, ?int $kd, int $bestCompRank): int
    {
        if ($volume <= 0) {
            return 0;
        }

        $ctr = self::estimateCtr($bestCompRank);
        $estimatedClicks = $volume * $ctr;
        $kdScore = $kd ?? 50;
        $difficultyDiscount = max(0.1, (100 - $kdScore) / 100.0);

        $raw = sqrt($estimatedClicks) * $difficultyDiscount * 10.0;

        return (int) round(min(100.0, max(1.0, $raw)));
    }

    /**
     * Extract items array from DataForSEO response structure.
     *
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function extractItemsFromResponse(array $response): array
    {
        $tasks = $response['tasks'] ?? [];
        if (! is_array($tasks) || $tasks === []) {
            return [];
        }

        $results = $tasks[0]['result'] ?? [];
        if (! is_array($results) || $results === []) {
            return [];
        }

        $items = $results[0]['items'] ?? [];

        /** @var list<array<string, mixed>> */
        return is_array($items) ? $items : [];
    }

    /**
     * Ingest and normalize domain intersection items into the keyword map.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $keywordsMap
     */
    private function ingestDomainIntersectionItems(
        array $items,
        array &$keywordsMap,
        string $compDomain,
        bool $isMissingQuery,
        int $locationCode,
        string $languageCode,
    ): void {
        foreach ($items as $item) {
            $kwData = $item['keyword_data'] ?? [];
            if (! is_array($kwData)) {
                continue;
            }

            $kw = isset($kwData['keyword']) && is_string($kwData['keyword']) ? trim($kwData['keyword']) : '';
            if ($kw === '') {
                continue;
            }

            $normKw = strtolower($kw);

            $info = $kwData['keyword_info'] ?? [];
            $props = $kwData['keyword_properties'] ?? [];
            $intent = $kwData['search_intent_info'] ?? [];

            $searchVolume = isset($info['search_volume']) && is_numeric($info['search_volume'])
                ? (int) $info['search_volume']
                : null;
            $cpc = isset($info['cpc']) && is_numeric($info['cpc'])
                ? (float) $info['cpc']
                : null;
            $competition = isset($info['competition']) && is_numeric($info['competition'])
                ? (float) $info['competition']
                : null;
            $competitionLevel = isset($info['competition_level']) && is_string($info['competition_level'])
                ? $info['competition_level']
                : null;
            $kd = isset($props['keyword_difficulty']) && is_numeric($props['keyword_difficulty'])
                ? (int) $props['keyword_difficulty']
                : null;
            $mainIntent = isset($intent['main_intent']) && is_string($intent['main_intent'])
                ? strtolower($intent['main_intent'])
                : null;
            $monthlySearches = isset($info['monthly_searches']) && is_array($info['monthly_searches'])
                ? $info['monthly_searches']
                : [];

            // Interpret rank elements based on query type:
            // For missing query (intersections=false): target1 is competitor, target2 is project
            // For intersect query (intersections=true): target1 is project, target2 is competitor
            $firstElement = $item['first_domain_serp_element'] ?? null;
            $secondElement = $item['second_domain_serp_element'] ?? null;

            if ($isMissingQuery) {
                $compRank = is_array($firstElement) && isset($firstElement['rank_group']) && is_numeric($firstElement['rank_group'])
                    ? (int) $firstElement['rank_group']
                    : null;
                $compUrl = is_array($firstElement) && isset($firstElement['url']) && is_string($firstElement['url'])
                    ? $firstElement['url']
                    : null;

                $projectRank = null;
                $projectUrl = null;
            } else {
                $projectRank = is_array($firstElement) && isset($firstElement['rank_group']) && is_numeric($firstElement['rank_group'])
                    ? (int) $firstElement['rank_group']
                    : null;
                $projectUrl = is_array($firstElement) && isset($firstElement['url']) && is_string($firstElement['url'])
                    ? $firstElement['url']
                    : null;

                $compRank = is_array($secondElement) && isset($secondElement['rank_group']) && is_numeric($secondElement['rank_group'])
                    ? (int) $secondElement['rank_group']
                    : null;
                $compUrl = is_array($secondElement) && isset($secondElement['url']) && is_string($secondElement['url'])
                    ? $secondElement['url']
                    : null;
            }

            if (! isset($keywordsMap[$normKw])) {
                $keywordsMap[$normKw] = [
                    'keyword' => $kw,
                    'location_code' => $locationCode,
                    'language_code' => $languageCode,
                    'search_volume' => $searchVolume,
                    'cpc' => $cpc,
                    'competition' => $competition,
                    'competition_level' => $competitionLevel,
                    'keyword_difficulty' => $kd,
                    'main_intent' => $mainIntent,
                    'monthly_searches' => $monthlySearches,
                    'project_rank' => $projectRank,
                    'project_url' => $projectUrl,
                    'competitor_ranks' => [],
                ];
            } else {
                // If project rank was discovered from a later intersection query, record it
                if ($keywordsMap[$normKw]['project_rank'] === null && $projectRank !== null) {
                    $keywordsMap[$normKw]['project_rank'] = $projectRank;
                    $keywordsMap[$normKw]['project_url'] = $projectUrl;
                }
            }

            $keywordsMap[$normKw]['competitor_ranks'][$compDomain] = [
                'domain' => $compDomain,
                'rank' => $compRank,
                'url' => $compUrl,
            ];
        }
    }
}
