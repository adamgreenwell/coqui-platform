<?php

declare(strict_types=1);

namespace App\Domains\Serp\Services;

class HtmlPageOutlineExtractor
{
    /**
     * Extract structured outline, headings, word count, and schema types from raw HTML.
     *
     * @return array{
     *     title: string|null,
     *     description: string|null,
     *     word_count: int,
     *     headings: array{
     *         h1: list<string>,
     *         h2: list<string>,
     *         h3: list<string>
     *     },
     *     schema_types: list<string>,
     *     internal_links_count: int,
     *     external_links_count: int
     * }
     */
    public function extract(string $html, string $pageUrl): array
    {
        $pageHost = parse_url($pageUrl, PHP_URL_HOST);

        // 1. Meta Title
        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = html_entity_decode(trim(strip_tags($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = preg_replace('/\s+/', ' ', $title);
        }

        // 2. Meta Description
        $description = null;
        if (
            preg_match('/<meta\s+[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $matches)
            || preg_match('/<meta\s+[^>]*content=["\'](.*?)["\'][^>]*name=["\']description["\']/is', $html, $matches)
        ) {
            $description = html_entity_decode(trim(strip_tags($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $description = preg_replace('/\s+/', ' ', $description);
        }

        // 3. Headings H1, H2, H3
        $h1s = $this->extractTags($html, 'h1');
        $h2s = $this->extractTags($html, 'h2');
        $h3s = $this->extractTags($html, 'h3');

        // 4. Schema Types from JSON-LD
        $schemaTypes = $this->extractSchemaTypes($html);

        // 5. Internal & External Link Counts
        $internalCount = 0;
        $externalCount = 0;
        if (preg_match_all('/<a\s+[^>]*href=["\'](.*?)["\']/is', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $trimmed = trim((string) $href);
                if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, 'javascript:') || str_starts_with($trimmed, 'mailto:') || str_starts_with($trimmed, 'tel:')) {
                    continue;
                }

                $hrefHost = parse_url($trimmed, PHP_URL_HOST);
                if ($hrefHost === null || ($pageHost !== null && strcasecmp($hrefHost, (string) $pageHost) === 0)) {
                    $internalCount++;
                } else {
                    $externalCount++;
                }
            }
        }

        // 6. Word Count of main content
        $cleanedHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $cleanedHtml = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', (string) $cleanedHtml);
        $cleanedHtml = preg_replace('/<svg\b[^>]*>(.*?)<\/svg>/is', '', (string) $cleanedHtml);
        $cleanedHtml = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', (string) $cleanedHtml);
        $plainText = html_entity_decode(strip_tags((string) $cleanedHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $plainText);

        return [
            'title' => $title !== '' ? $title : null,
            'description' => $description !== '' ? $description : null,
            'word_count' => is_int($wordCount) ? $wordCount : 0,
            'headings' => [
                'h1' => $h1s,
                'h2' => $h2s,
                'h3' => $h3s,
            ],
            'schema_types' => $schemaTypes,
            'internal_links_count' => $internalCount,
            'external_links_count' => $externalCount,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractTags(string $html, string $tag): array
    {
        $results = [];
        if (preg_match_all('/<'.$tag.'[^>]*>(.*?)<\/'.$tag.'>/is', $html, $matches)) {
            foreach ($matches[1] as $content) {
                $cleaned = html_entity_decode(trim(strip_tags((string) $content)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $cleaned = (string) preg_replace('/\s+/', ' ', $cleaned);
                if ($cleaned !== '') {
                    $results[] = $cleaned;
                }
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function extractSchemaTypes(string $html): array
    {
        $types = [];
        if (preg_match_all('/<script\s+[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonBlock) {
                $json = @json_decode(trim((string) $jsonBlock), true);
                if (! is_array($json)) {
                    continue;
                }

                $this->collectSchemaTypes($json, $types);
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  list<string>  $types
     */
    private function collectSchemaTypes(array $data, array &$types): void
    {
        if (isset($data['@type'])) {
            if (is_string($data['@type']) && $data['@type'] !== '') {
                $types[] = $data['@type'];
            } elseif (is_array($data['@type'])) {
                foreach ($data['@type'] as $t) {
                    if (is_string($t) && $t !== '') {
                        $types[] = $t;
                    }
                }
            }
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $this->collectSchemaTypes($node, $types);
                }
            }
        }

        foreach ($data as $key => $val) {
            if ($key !== '@graph' && is_array($val)) {
                $this->collectSchemaTypes($val, $types);
            }
        }
    }
}
