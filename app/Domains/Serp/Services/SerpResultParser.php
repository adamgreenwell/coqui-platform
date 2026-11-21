<?php

declare(strict_types=1);

namespace App\Domains\Serp\Services;

class SerpResultParser
{
    /**
     * Parse SERP result items for target project domain and configured competitor domains.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $payload
     * @param  list<string>  $competitorDomains
     * @return array{
     *     detected_features: list<string>,
     *     ai_overview: array{present: bool, position: int|null, citations: list<array{position: int, domain: string, url: string|null, title: string|null}>},
     *     local_pack: list<array{rank: int|null, title: string, domain: string|null, url: string|null, rating: float|null}>,
     *     people_also_ask: list<string>,
     *     domain_snapshots: array<string, array{
     *         domain: string,
     *         rank_group: int|null,
     *         rank_absolute: int|null,
     *         ranking_url: string|null,
     *         ranking_title: string|null,
     *         has_ai_overview: bool,
     *         has_featured_snippet: bool,
     *         serp_features: array<string, mixed>
     *     }>
     * }
     */
    public function parse(array $payload, string $projectDomain, array $competitorDomains = []): array
    {
        $items = $this->extractItems($payload);
        $detectedFeatures = $this->detectFeatures($items, $payload);
        $aiOverviewCitedDomains = $this->extractAiOverviewCitedDomains($items);
        $featuredSnippetOwners = $this->extractFeaturedSnippetOwners($items);
        $aiOverview = $this->extractAiOverview($items);
        $localPack = $this->extractLocalPack($items);
        $peopleAlsoAsk = $this->extractPeopleAlsoAsk($items);

        $allDomains = array_unique(array_merge(
            [$this->normalizeDomain($projectDomain)],
            array_map(fn (string $d) => $this->normalizeDomain($d), $competitorDomains)
        ));

        $snapshots = [];

        foreach ($allDomains as $domain) {
            $matchingItem = $this->findBestMatchingItem($items, $domain);

            $hasAiOverview = false;
            foreach ($aiOverviewCitedDomains as $citedDomain) {
                if ($this->domainMatches($citedDomain, $domain)) {
                    $hasAiOverview = true;
                    break;
                }
            }

            $hasFeaturedSnippet = false;
            foreach ($featuredSnippetOwners as $snippetDomain) {
                if ($this->domainMatches($snippetDomain, $domain)) {
                    $hasFeaturedSnippet = true;
                    break;
                }
            }

            $citationPosition = null;
            foreach ($aiOverview['citations'] as $citation) {
                if ($this->domainMatches($citation['domain'], $domain)) {
                    $citationPosition = $citation['position'];
                    break;
                }
            }

            $localPackOwned = false;
            foreach ($localPack as $listing) {
                if ($listing['domain'] !== null && $this->domainMatches($listing['domain'], $domain)) {
                    $localPackOwned = true;
                    break;
                }
            }

            $snapshots[$domain] = [
                'domain' => $domain,
                'rank_group' => $matchingItem['rank_group'] ?? null,
                'rank_absolute' => $matchingItem['rank_absolute'] ?? null,
                'ranking_url' => $matchingItem['url'] ?? null,
                'ranking_title' => $matchingItem['title'] ?? null,
                'has_ai_overview' => $hasAiOverview,
                'has_featured_snippet' => $hasFeaturedSnippet,
                'serp_features' => [
                    'page_features' => $detectedFeatures,
                    'ai_overview_cited' => $hasAiOverview,
                    'featured_snippet_owned' => $hasFeaturedSnippet,
                    // 1-based position among the AI Overview's citations.
                    'ai_overview_citation_position' => $citationPosition,
                    'local_pack_owned' => $localPackOwned,
                    'item_type' => $matchingItem['type'] ?? null,
                ],
            ];
        }

        return [
            'detected_features' => $detectedFeatures,
            'ai_overview' => $aiOverview,
            'local_pack' => $localPack,
            'people_also_ask' => $peopleAlsoAsk,
            'domain_snapshots' => $snapshots,
        ];
    }

    /**
     * The AI Overview's SERP position and its citations in order, one per
     * URL (references on the overview and on its elements).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{present: bool, position: int|null, citations: list<array{position: int, domain: string, url: string|null, title: string|null}>}
     */
    private function extractAiOverview(array $items): array
    {
        $present = false;
        $position = null;
        $citations = [];
        $seen = [];

        $add = function (mixed $ref) use (&$citations, &$seen): void {
            if (! is_array($ref)) {
                return;
            }

            $url = isset($ref['url']) && is_string($ref['url']) && $ref['url'] !== '' ? $ref['url'] : null;
            $domain = (string) ($ref['domain'] ?? '');
            if ($domain === '' && $url !== null) {
                $domain = (string) parse_url($url, PHP_URL_HOST);
            }
            $domain = $this->normalizeDomain($domain);
            if ($domain === '') {
                return;
            }

            $key = $url ?? $domain;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $title = isset($ref['title']) && is_string($ref['title']) && trim($ref['title']) !== '' ? trim($ref['title']) : (isset($ref['source']) && is_string($ref['source']) ? $ref['source'] : null);
            $citations[] = [
                'position' => count($citations) + 1,
                'domain' => $domain,
                'url' => $url,
                'title' => $title !== null ? mb_substr($title, 0, 500) : null,
            ];
        };

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'ai_overview') {
                continue;
            }

            $present = true;
            $position ??= isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : null;

            foreach (is_array($item['references'] ?? null) ? $item['references'] : [] as $ref) {
                $add($ref);
            }
            foreach (is_array($item['items'] ?? null) ? $item['items'] : [] as $element) {
                if (! is_array($element)) {
                    continue;
                }
                foreach (is_array($element['references'] ?? null) ? $element['references'] : [] as $ref) {
                    $add($ref);
                }
            }
        }

        return ['present' => $present, 'position' => $position, 'citations' => $citations];
    }

    /**
     * Local Pack listings: each is its own `local_pack` item.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{rank: int|null, title: string, domain: string|null, url: string|null, rating: float|null}>
     */
    private function extractLocalPack(array $items): array
    {
        $listings = [];
        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'local_pack') {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $url = isset($item['url']) && is_string($item['url']) && $item['url'] !== '' ? $item['url'] : null;
            $domain = (string) ($item['domain'] ?? '');
            if ($domain === '' && $url !== null) {
                $domain = (string) parse_url($url, PHP_URL_HOST);
            }
            $rating = $item['rating']['value'] ?? null;

            $listings[] = [
                'rank' => isset($item['rank_group']) ? (int) $item['rank_group'] : null,
                'title' => mb_substr($title, 0, 255),
                'domain' => $domain !== '' ? $this->normalizeDomain($domain) : null,
                'url' => $url,
                'rating' => is_numeric($rating) ? (float) $rating : null,
            ];
        }

        return $listings;
    }

    /**
     * People Also Ask questions, in SERP order.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function extractPeopleAlsoAsk(array $items): array
    {
        $questions = [];
        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'people_also_ask') {
                continue;
            }

            foreach (is_array($item['items'] ?? null) ? $item['items'] : [] as $element) {
                $question = is_array($element) ? trim((string) ($element['title'] ?? '')) : '';
                if ($question !== '') {
                    $questions[] = mb_substr($question, 0, 255);
                }
            }
        }

        return array_values(array_unique($questions));
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractItems(array $payload): array
    {
        // 1. Direct array of items
        if (array_is_list($payload) && ! empty($payload) && isset($payload[0]['type'])) {
            /** @var list<array<string, mixed>> $payload */
            return $payload;
        }

        // 2. DataForSEO tasks.0.result.0.items
        if (isset($payload['tasks'][0]['result'][0]['items']) && is_array($payload['tasks'][0]['result'][0]['items'])) {
            /** @var list<array<string, mixed>> $items */
            $items = $payload['tasks'][0]['result'][0]['items'];

            return $items;
        }

        // 3. result.0.items
        if (isset($payload['result'][0]['items']) && is_array($payload['result'][0]['items'])) {
            /** @var list<array<string, mixed>> $items */
            $items = $payload['result'][0]['items'];

            return $items;
        }

        // 4. items directly
        if (isset($payload['items']) && is_array($payload['items'])) {
            /** @var list<array<string, mixed>> $items */
            $items = $payload['items'];

            return $items;
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|list<array<string, mixed>>  $payload
     * @return list<string>
     */
    private function detectFeatures(array $items, array $payload): array
    {
        $features = [];

        // Check if item_types array is present on the task result
        if (isset($payload['tasks'][0]['result'][0]['item_types']) && is_array($payload['tasks'][0]['result'][0]['item_types'])) {
            foreach ($payload['tasks'][0]['result'][0]['item_types'] as $type) {
                if (is_string($type) && $type !== 'organic') {
                    $features[] = $type;
                }
            }
        }

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type !== '' && $type !== 'organic') {
                $features[] = $type;
            }
        }

        return array_values(array_unique($features));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function extractAiOverviewCitedDomains(array $items): array
    {
        $domains = [];

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type !== 'ai_overview') {
                continue;
            }

            // References list
            if (isset($item['references']) && is_array($item['references'])) {
                foreach ($item['references'] as $ref) {
                    if (is_array($ref)) {
                        $domain = (string) ($ref['domain'] ?? '');
                        if ($domain === '' && isset($ref['url'])) {
                            $domain = (string) parse_url((string) $ref['url'], PHP_URL_HOST);
                        }
                        if ($domain !== '') {
                            $domains[] = $this->normalizeDomain($domain);
                        }
                    }
                }
            }

            // Sub-items
            if (isset($item['items']) && is_array($item['items'])) {
                foreach ($item['items'] as $subItem) {
                    if (is_array($subItem)) {
                        $domain = (string) ($subItem['domain'] ?? '');
                        if ($domain === '' && isset($subItem['url'])) {
                            $domain = (string) parse_url((string) $subItem['url'], PHP_URL_HOST);
                        }
                        if ($domain !== '') {
                            $domains[] = $this->normalizeDomain($domain);
                        }

                        // Element-specific nested references array
                        if (isset($subItem['references']) && is_array($subItem['references'])) {
                            foreach ($subItem['references'] as $subRef) {
                                if (is_array($subRef)) {
                                    $refDomain = (string) ($subRef['domain'] ?? '');
                                    if ($refDomain === '' && isset($subRef['url'])) {
                                        $refDomain = (string) parse_url((string) $subRef['url'], PHP_URL_HOST);
                                    }
                                    if ($refDomain !== '') {
                                        $domains[] = $this->normalizeDomain($refDomain);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function extractFeaturedSnippetOwners(array $items): array
    {
        $domains = [];

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type !== 'featured_snippet') {
                continue;
            }

            $domain = (string) ($item['domain'] ?? '');
            if ($domain === '' && isset($item['url'])) {
                $domain = (string) parse_url((string) $item['url'], PHP_URL_HOST);
            }

            if ($domain !== '') {
                $domains[] = $this->normalizeDomain($domain);
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function findBestMatchingItem(array $items, string $targetDomain): ?array
    {
        $bestOrganicItem = null;
        $bestOrganicRank = PHP_INT_MAX;
        $bestSnippetItem = null;
        $bestSnippetRank = PHP_INT_MAX;

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            if (! in_array($type, ['organic', 'featured_snippet'], true)) {
                continue;
            }

            $itemDomain = (string) ($item['domain'] ?? '');
            $itemUrl = (string) ($item['url'] ?? '');

            if ($itemDomain === '' && $itemUrl !== '') {
                $itemDomain = (string) parse_url($itemUrl, PHP_URL_HOST);
            }

            if (! $this->domainMatches($itemDomain, $targetDomain)) {
                continue;
            }

            $rankAbsolute = isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : null;
            $rankGroup = isset($item['rank_group']) ? (int) $item['rank_group'] : $rankAbsolute;

            if ($type === 'organic') {
                $compRank = $rankGroup ?? $rankAbsolute ?? PHP_INT_MAX;
                if ($compRank < $bestOrganicRank) {
                    $bestOrganicRank = $compRank;
                    $bestOrganicItem = [
                        'type' => 'organic',
                        'rank_group' => $rankGroup,
                        'rank_absolute' => $rankAbsolute,
                        'url' => $itemUrl !== '' ? $itemUrl : null,
                        'title' => isset($item['title']) ? (string) $item['title'] : null,
                    ];
                }
            } elseif ($type === 'featured_snippet') {
                $compRank = $rankAbsolute ?? $rankGroup ?? PHP_INT_MAX;
                if ($compRank < $bestSnippetRank) {
                    $bestSnippetRank = $compRank;
                    $bestSnippetItem = [
                        'type' => 'featured_snippet',
                        'rank_group' => $rankAbsolute ?? $rankGroup,
                        'rank_absolute' => $rankAbsolute,
                        'url' => $itemUrl !== '' ? $itemUrl : null,
                        'title' => isset($item['title']) ? (string) $item['title'] : null,
                    ];
                }
            }
        }

        return $bestOrganicItem ?? $bestSnippetItem;
    }

    private function domainMatches(string $candidate, string $target): bool
    {
        $candNorm = $this->normalizeDomain($candidate);
        $targetNorm = $this->normalizeDomain($target);

        if ($candNorm === '' || $targetNorm === '') {
            return false;
        }

        return $candNorm === $targetNorm
            || str_ends_with($candNorm, '.'.$targetNorm);
    }

    private function normalizeDomain(string $domain): string
    {
        $lower = strtolower(trim($domain));
        $host = (string) parse_url('https://'.$lower, PHP_URL_HOST);

        if ($host !== '') {
            $lower = $host;
        }

        if (str_starts_with($lower, 'www.')) {
            $lower = substr($lower, 4);
        }

        return $lower;
    }
}
