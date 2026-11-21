<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

/**
 * Turns a DataForSEO Labs `keyword_overview` response into one metrics row
 * per keyword. Shared by Keyword Explorer and the provider router.
 */
final class DataForSeoKeywordParser
{
    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array<string, mixed>> Map of normalized_keyword => metrics
     */
    public static function overviewItems(array $response, int $locationCode, string $languageCode): array
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
}
