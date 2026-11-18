<?php

declare(strict_types=1);

namespace App\Domains\Serp\Services;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\SeRanking\SeRankingAcceptanceRule;
use App\Domains\Serp\SeRanking\SeRankingClient;

/**
 * Routes SEO lookups across SE Ranking and DataForSEO based on endpoint
 * availability and unit economics.
 *
 * - Keyword bulk screening: SE Ranking flat-batch (`/v1/keywords/export`) for
 *   accepted ASCII keywords, falling back to DataForSEO `keyword_overview` for
 *   non-ASCII, numeric-only, or unconfigured setups.
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
     * @param  list<string>  $keywords
     * @return array{
     *     partition: array{accepted: list<string>, rejected: list<string>},
     *     providers: array{seranking: list<array<string, mixed>>, dataforseo: array<string, mixed>}
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
        $dataForSeoKeywords = $partition['rejected'];

        if ($this->seRanking->isConfigured() && $partition['accepted'] !== []) {
            $seRankingRows = $this->seRanking->exportKeywords(
                keywords: $partition['accepted'],
                source: $seRankingSource,
                project: $project,
            );
        } else {
            $dataForSeoKeywords = array_values(array_unique([
                ...$partition['accepted'],
                ...$partition['rejected'],
            ]));
        }

        $dataForSeoPayload = [];
        if ($dataForSeoKeywords !== []) {
            $dataForSeoPayload = $this->dataForSeo->keywordOverview(
                keywords: $dataForSeoKeywords,
                project: $project,
                locationCode: $locationCode,
                languageCode: $languageCode,
            );
        }

        return [
            'partition' => $partition,
            'providers' => [
                'seranking' => $seRankingRows,
                'dataforseo' => $dataForSeoPayload,
            ],
        ];
    }
}
