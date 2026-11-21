<?php

declare(strict_types=1);

namespace App\Domains\Serp\Services;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\DataForSeo\DataForSeoKeywordParser;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Domains\Serp\SeRanking\SeRankingAcceptanceRule;
use App\Domains\Serp\SeRanking\SeRankingClient;
use App\Domains\Serp\SpendRefusedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Routes SEO lookups across SE Ranking and DataForSEO based on endpoint
 * availability and unit economics.
 *
 * - Keyword bulk screening: SE Ranking flat-batch (`/v1/keywords/export`) for
 *   accepted ASCII keywords, with DataForSEO `keyword_overview` for non-ASCII
 *   or numeric-only keywords, unconfigured setups, and any keywords SE Ranking
 *   failed on (an error, a batch it kept rejecting, its credit cap). With only
 *   SE Ranking configured, keywords it can't take come back as `unavailable`
 *   (unless DataForSEO has them cached). When nothing can be looked up it
 *   throws ProviderNotConfiguredException.
 * - Backlinks & SERP Rank Tracking: always DataForSEO (SE Ranking's standalone
 *   Data API does not expose backlink endpoints without a regular plan).
 */
class SeoProviderRouter
{
    public function __construct(
        private readonly SeRankingClient $seRanking,
        private readonly SeRankingAcceptanceRule $acceptanceRule,
        private readonly DataForSeoClient $dataForSeo,
    ) {}

    /**
     * `keywords` has one normalized row per keyword with data, tagged with
     * the provider that answered (internal only: don't show it to users).
     * `unavailable` lists keywords no configured provider could look up, so
     * callers don't report them as having no data. `fallback` lists keywords
     * sent to DataForSEO because SE Ranking failed on them. `cost` is what
     * this lookup spent (cached answers cost nothing). Throws
     * ProviderNotConfiguredException when no keyword could be looked up.
     *
     * @param  list<string>  $keywords
     * @return array{
     *     partition: array{accepted: list<string>, rejected: list<string>},
     *     providers: array{seranking: list<array<string, mixed>>, dataforseo: array<string, mixed>},
     *     keywords: list<array{keyword: string, search_volume: int|null, cpc: float|null, competition: float|null, keyword_difficulty: int|null, provider: string}>,
     *     unavailable: list<string>,
     *     fallback: list<string>,
     *     cost: array{seranking_credits: int, dataforseo_usd: float}
     * }
     */
    public function lookupKeywords(
        array $keywords,
        ?Project $project = null,
        int $locationCode = 2840,
        string $languageCode = 'en',
        string $seRankingSource = 'us',
    ): array {
        $partition = $this->acceptanceRule->partition($keywords, $seRankingSource);

        $seRankingRows = [];
        $seRankingCredits = 0;
        $fallback = [];
        $seRankingRan = $this->seRanking->isConfigured() && $partition['accepted'] !== [];

        if ($seRankingRan) {
            try {
                $export = $this->seRanking->exportKeywordsDetailed(
                    keywords: $partition['accepted'],
                    source: $seRankingSource,
                    project: $project,
                );
                $seRankingRows = $export['rows'];
                $seRankingCredits = $export['credits'];
                $fallback = $export['failed'];
            } catch (SpendRefusedException) {
                // SE Ranking's cap is reached; DataForSEO has its own budget.
                $fallback = $partition['accepted'];
            }

            $dataForSeoKeywords = array_values(array_unique([...$partition['rejected'], ...$fallback]));
        } else {
            $dataForSeoKeywords = array_values(array_unique([
                ...$partition['accepted'],
                ...$partition['rejected'],
            ]));
        }

        $dataForSeoPayload = [];
        $dataForSeoUsd = 0.0;
        $unavailable = [];
        if ($dataForSeoKeywords !== []) {
            try {
                // Cached results are served even without credentials; only a
                // cache miss needs them.
                $cached = $this->dataForSeo->keywordOverviewIsCached($dataForSeoKeywords, $locationCode, $languageCode);
                $dataForSeoPayload = $this->dataForSeo->keywordOverview(
                    keywords: $dataForSeoKeywords,
                    project: $project,
                    locationCode: $locationCode,
                    languageCode: $languageCode,
                );
                $dataForSeoUsd = $cached ? 0.0 : (float) ($dataForSeoPayload['cost'] ?? 0);
            } catch (ProviderNotConfiguredException $e) {
                if (! $seRankingRan) {
                    // Nothing could be looked up at all.
                    throw $e;
                }

                $unavailable = $dataForSeoKeywords;
            } catch (SpendRefusedException|ConnectionException|RequestException $e) {
                if ($seRankingRows === []) {
                    throw $e;
                }

                // Keep what SE Ranking returned (already paid for) rather
                // than failing the whole lookup.
                $unavailable = $dataForSeoKeywords;
            }
        }

        return [
            'partition' => $partition,
            'providers' => [
                'seranking' => $seRankingRows,
                'dataforseo' => $dataForSeoPayload,
            ],
            'keywords' => $this->normalize($seRankingRows, $dataForSeoPayload, $locationCode, $languageCode),
            'unavailable' => $unavailable,
            'fallback' => $fallback,
            'cost' => [
                'seranking_credits' => $seRankingCredits,
                'dataforseo_usd' => round($dataForSeoUsd, 6),
            ],
        ];
    }

    /**
     * One row per keyword in the same shape whichever provider answered.
     *
     * @param  list<array<string, mixed>>  $seRankingRows
     * @param  array<string, mixed>  $dataForSeoPayload
     * @return list<array{keyword: string, search_volume: int|null, cpc: float|null, competition: float|null, keyword_difficulty: int|null, provider: string}>
     */
    private function normalize(array $seRankingRows, array $dataForSeoPayload, int $locationCode, string $languageCode): array
    {
        $rows = [];

        foreach ($seRankingRows as $row) {
            $keyword = mb_strtolower(trim((string) ($row['keyword'] ?? '')));
            if ($keyword === '' || ($row['is_data_found'] ?? true) === false) {
                continue;
            }

            $rows[$keyword] = [
                'keyword' => $keyword,
                'search_volume' => isset($row['volume']) ? (int) $row['volume'] : null,
                'cpc' => isset($row['cpc']) ? (float) $row['cpc'] : null,
                'competition' => isset($row['competition']) ? (float) $row['competition'] : null,
                'keyword_difficulty' => isset($row['difficulty']) ? (int) $row['difficulty'] : null,
                'provider' => 'seranking',
            ];
        }

        if ($dataForSeoPayload !== []) {
            foreach (DataForSeoKeywordParser::overviewItems($dataForSeoPayload, $locationCode, $languageCode) as $keyword => $row) {
                $rows[$keyword] ??= [
                    'keyword' => $keyword,
                    'search_volume' => isset($row['search_volume']) ? (int) $row['search_volume'] : null,
                    'cpc' => isset($row['cpc']) ? (float) $row['cpc'] : null,
                    'competition' => isset($row['competition']) ? (float) $row['competition'] : null,
                    'keyword_difficulty' => isset($row['keyword_difficulty']) ? (int) $row['keyword_difficulty'] : null,
                    'provider' => 'dataforseo',
                ];
            }
        }

        return array_values($rows);
    }
}
