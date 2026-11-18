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

        $allDomains = array_unique(array_merge(
            [$this->normalizeDomain($projectDomain)],
            array_map(fn (string $d) => $this->normalizeDomain($d), $competitorDomains)
        ));

        $snapshots = [];

        foreach ($allDomains as $domain) {
            $matchingItem = $this->findBestMatchingItem($items, $domain);

            $hasAiOverview = in_array($domain, $aiOverviewCitedDomains, true);
            $hasFeaturedSnippet = in_array($domain, $featuredSnippetOwners, true);

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
                    'item_type' => $matchingItem['type'] ?? null,
                ],
            ];
        }

        return [
            'detected_features' => $detectedFeatures,
            'domain_snapshots' => $snapshots,
        ];
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
        $bestItem = null;
        $bestRank = PHP_INT_MAX;

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

            $rankGroup = isset($item['rank_group']) ? (int) $item['rank_group'] : (int) ($item['rank_absolute'] ?? 100);

            if ($rankGroup < $bestRank) {
                $bestRank = $rankGroup;
                $bestItem = [
                    'type' => $type,
                    'rank_group' => isset($item['rank_group']) ? (int) $item['rank_group'] : $rankGroup,
                    'rank_absolute' => isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : null,
                    'url' => $itemUrl !== '' ? $itemUrl : null,
                    'title' => isset($item['title']) ? (string) $item['title'] : null,
                ];
            }
        }

        return $bestItem;
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
