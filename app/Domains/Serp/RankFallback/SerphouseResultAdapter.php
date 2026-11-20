<?php

declare(strict_types=1);

namespace App\Domains\Serp\RankFallback;

/**
 * Turns SERPHouse result pages into the item list SerpResultParser reads,
 * so backup checks are recorded exactly like primary ones.
 */
class SerphouseResultAdapter
{
    /**
     * SERPHouse result blocks and the SERP feature names the parser uses.
     */
    private const FEATURES = [
        'people_also_ask' => 'people_also_ask',
        'related_search' => 'related_searches',
        'related_searches' => 'related_searches',
        'inline_videos' => 'video',
        'videos' => 'video',
        'short_videos' => 'short_videos',
        'top_stories' => 'top_stories',
        'local_pack' => 'local_pack',
        'local_results' => 'local_pack',
        'inline_shopping' => 'shopping',
        'images' => 'images',
        'knowledge_graph' => 'knowledge_graph',
    ];

    /**
     * Blocks that may hold a featured snippet. The docs don't show one, so
     * this accepts the names SERP APIs commonly use.
     */
    private const SNIPPET_BLOCKS = ['featured_snippet', 'answer_box', 'top_result'];

    /**
     * @param  list<array<string, mixed>>  $pages  responses in page order
     * @return list<array<string, mixed>>
     */
    public function toItems(array $pages): array
    {
        $items = [];
        $features = [];
        $rank = 0;

        foreach ($pages as $page) {
            $results = $this->results($page);

            // Organic results are numbered across pages by counting them, so
            // it doesn't matter whether SERPHouse restarts at 1 on each page.
            foreach ($this->rows($results['organic'] ?? null) as $row) {
                $url = (string) ($row['link'] ?? $row['url'] ?? '');
                if ($url === '') {
                    continue;
                }

                $rank++;
                $items[] = [
                    'type' => 'organic',
                    'rank_group' => $rank,
                    'rank_absolute' => $rank,
                    'url' => $url,
                    'domain' => (string) parse_url($url, PHP_URL_HOST),
                    'title' => isset($row['title']) ? (string) $row['title'] : null,
                ];
            }

            // The AI Overview appears on the first page only; keep one.
            if (! isset($features['ai_overview']) && is_array($results['ai_overview'] ?? null) && $results['ai_overview'] !== []) {
                $features['ai_overview'] = true;
                $items[] = [
                    'type' => 'ai_overview',
                    'references' => array_map(
                        fn (array $reference): array => ['url' => (string) ($reference['link'] ?? $reference['url'] ?? '')],
                        $this->rows($results['ai_overview']['references'] ?? null),
                    ),
                ];
            }

            foreach (self::SNIPPET_BLOCKS as $block) {
                $snippet = $results[$block] ?? null;
                $url = is_array($snippet) ? (string) ($snippet['link'] ?? $snippet['url'] ?? '') : '';
                if ($url !== '' && ! isset($features['featured_snippet'])) {
                    $features['featured_snippet'] = true;
                    $items[] = [
                        'type' => 'featured_snippet',
                        'rank_group' => 1,
                        'rank_absolute' => 1,
                        'url' => $url,
                        'domain' => (string) parse_url($url, PHP_URL_HOST),
                        'title' => isset($snippet['title']) ? (string) $snippet['title'] : null,
                    ];
                }
            }

            foreach (self::FEATURES as $block => $type) {
                if (! empty($results[$block]) && ! isset($features[$type])) {
                    $features[$type] = true;
                    $items[] = ['type' => $type];
                }
            }
        }

        return $items;
    }

    /**
     * Organic results on one response page.
     *
     * @param  array<string, mixed>  $page
     */
    public function organicCount(array $page): int
    {
        return count($this->rows($this->results($page)['organic'] ?? null));
    }

    /**
     * The block holding organic results. The live endpoint returns it under
     * "results", wrapped in another "results" when a status envelope is used.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function results(array $page): array
    {
        foreach ([$page['results']['results'] ?? null, $page['results'] ?? null, $page] as $candidate) {
            if (is_array($candidate) && array_key_exists('organic', $candidate)) {
                return $candidate;
            }
        }

        return is_array($page['results'] ?? null) ? $page['results'] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
