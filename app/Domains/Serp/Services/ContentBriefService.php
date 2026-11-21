<?php

declare(strict_types=1);

namespace App\Domains\Serp\Services;

use App\Domains\Crawls\Services\SafeHttpClient;
use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\Models\ContentBrief;
use App\Domains\Serp\ProviderNotConfiguredException;
use Illuminate\Support\Str;
use Throwable;

class ContentBriefService
{
    public function __construct(
        private readonly DataForSeoClient $dataForSeo,
        private readonly DataForSeoCacheRepository $cache,
        private readonly HtmlPageOutlineExtractor $extractor = new HtmlPageOutlineExtractor,
        private readonly SafeHttpClient $http = new SafeHttpClient,
    ) {}

    /**
     * Build or refresh a comprehensive SERP content brief for a target keyword.
     *
     * @param  array<string, mixed>|null  $serpOverride
     */
    public function buildBrief(
        Project $project,
        string $keyword,
        ?int $locationCode = null,
        ?string $languageCode = null,
        ?array $serpOverride = null,
    ): ContentBrief {
        $normalizedKeyword = strtolower(trim($keyword));
        $location = $locationCode ?? (int) ($project->location_code ?? 2840);
        $language = strtolower(trim($languageCode ?? (string) ($project->language_code ?? 'en')));

        // 1. Fetch keyword volume & CPC
        $metrics = $this->cache->getKeywordMetrics($normalizedKeyword, $location, $language);
        $searchVolume = isset($metrics['search_volume']) ? (int) $metrics['search_volume'] : 0;
        $cpc = isset($metrics['cpc']) ? (float) $metrics['cpc'] : 0.00;

        // 2. Fetch SERP organic items and People Also Ask questions
        $serpData = $serpOverride ?? $this->fetchSerpData($project, $normalizedKeyword, $location, $language);
        $extractedSerp = $this->extractSerpOrganicAndQuestions($serpData);

        $organicItems = $extractedSerp['organic'];
        $paaQuestions = $extractedSerp['questions'];

        // 3. Inspect top 10 competitor URLs (with SSRF protection & graceful failure degradation)
        $competitorMatrix = [];
        $successfulWordCounts = [];
        $allSchemaTypes = [];
        $allH2s = [];

        foreach ($organicItems as $item) {
            $url = (string) $item['url'];
            $rank = (int) $item['rank'];
            $title = (string) ($item['title'] ?? '');
            $domain = (string) ($item['domain'] ?? parse_url($url, PHP_URL_HOST) ?? '');

            try {
                // Validates every redirect hop and pins DNS (see SafeHttpClient).
                $response = $this->http->get($url, timeoutSeconds: 6);

                $statusCode = $response->status();

                if ($response->successful()) {
                    $outline = $this->extractor->extract($response->body(), $url);

                    $wordCount = $outline['word_count'];
                    $successfulWordCounts[] = $wordCount;

                    foreach ($outline['schema_types'] as $st) {
                        $allSchemaTypes[] = $st;
                    }

                    foreach ($outline['headings']['h2'] as $h2) {
                        $allH2s[] = $h2;
                    }

                    $competitorMatrix[] = [
                        'rank' => $rank,
                        'url' => $url,
                        'domain' => $domain,
                        'title' => $outline['title'] ?? $title,
                        'description' => $outline['description'],
                        'wordCount' => $wordCount,
                        'headings' => $outline['headings'],
                        'schemaTypes' => $outline['schema_types'],
                        'internalLinks' => $outline['internal_links_count'],
                        'externalLinks' => $outline['external_links_count'],
                        'status' => $statusCode,
                        'error' => null,
                    ];
                } else {
                    // Graceful degradation for 403, 404, 500
                    $competitorMatrix[] = [
                        'rank' => $rank,
                        'url' => $url,
                        'domain' => $domain,
                        'title' => $title,
                        'description' => null,
                        'wordCount' => 0,
                        'headings' => ['h1' => [], 'h2' => [], 'h3' => []],
                        'schemaTypes' => [],
                        'internalLinks' => 0,
                        'externalLinks' => 0,
                        'status' => $statusCode,
                        'error' => "HTTP {$statusCode}",
                    ];
                }
            } catch (Throwable $e) {
                // Graceful degradation for network timeout, connection refused, or SSRF block
                $competitorMatrix[] = [
                    'rank' => $rank,
                    'url' => $url,
                    'domain' => $domain,
                    'title' => $title,
                    'description' => null,
                    'wordCount' => 0,
                    'headings' => ['h1' => [], 'h2' => [], 'h3' => []],
                    'schemaTypes' => [],
                    'internalLinks' => 0,
                    'externalLinks' => 0,
                    'status' => null,
                    'error' => Str::limit($e->getMessage(), 120, '...'),
                ];
            }
        }

        // 4. Compute Word Count Targets & Recommendations
        $successCount = count($successfulWordCounts);
        $avgWordCount = $successCount > 0 ? (int) round(array_sum($successfulWordCounts) / $successCount) : 1850;

        // Top 3 average
        $top3Counts = array_slice($successfulWordCounts, 0, 3);
        $avgTop3 = count($top3Counts) > 0 ? (int) round(array_sum($top3Counts) / count($top3Counts)) : $avgWordCount;

        $minWords = max((int) round($avgWordCount * 0.9), 1200);
        $maxWords = (int) round($avgWordCount * 1.25);

        // Schema frequency
        $schemaFrequency = array_count_values($allSchemaTypes);
        arsort($schemaFrequency);
        $popularSchemas = array_keys(array_slice($schemaFrequency, 0, 5));

        // Deduplicate common H2 themes
        $uniqueH2s = array_values(array_unique($allH2s));

        $data = [
            'keyword' => $normalizedKeyword,
            'searchVolume' => $searchVolume,
            'cpc' => $cpc,
            'metrics' => [
                'avgWordCountTop10' => $avgWordCount,
                'avgWordCountTop3' => $avgTop3,
                'minWords' => $minWords,
                'maxWords' => $maxWords,
                'successfulCompetitorsScraped' => $successCount,
                'totalCompetitorsTargeted' => count($competitorMatrix),
            ],
            'recommendations' => [
                'suggestedWordCount' => "{$minWords} – {$maxWords} words",
                'recommendedSchemas' => $popularSchemas !== [] ? $popularSchemas : ['Article', 'FAQPage'],
                'keyH2Themes' => array_slice($uniqueH2s, 0, 12),
                'questionsToAnswer' => array_slice($paaQuestions, 0, 8),
            ],
            'competitors' => $competitorMatrix,
        ];

        // 5. Generate Markdown Content Brief
        $markdown = $this->generateMarkdown($project, $normalizedKeyword, $data);

        /** @var ContentBrief $brief */
        $brief = ContentBrief::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'keyword' => $normalizedKeyword,
            ],
            [
                'location_code' => $location,
                'language_code' => $language,
                'search_volume' => $searchVolume,
                'cpc' => $cpc,
                'recommended_words_min' => $minWords,
                'recommended_words_max' => $maxWords,
                'data' => $data,
                'markdown' => $markdown,
            ]
        );

        return $brief;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSerpData(Project $project, string $keyword, int $locationCode, string $languageCode): array
    {
        try {
            return $this->dataForSeo->serpLive($keyword, $project, $locationCode, $languageCode);
        } catch (ProviderNotConfiguredException $e) {
            // Not a lookup failure: a brief without SERP data would be empty.
            throw $e;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $serpData
     * @return array{
     *     organic: list<array<string, mixed>>,
     *     questions: list<string>
     * }
     */
    private function extractSerpOrganicAndQuestions(array $serpData): array
    {
        $items = [];
        if (isset($serpData['tasks'][0]['result'][0]['items']) && is_array($serpData['tasks'][0]['result'][0]['items'])) {
            $items = $serpData['tasks'][0]['result'][0]['items'];
        } elseif (isset($serpData['items']) && is_array($serpData['items'])) {
            $items = $serpData['items'];
        }

        $organic = [];
        $questions = [];

        $rankCounter = 1;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? '');

            if ($type === 'organic' && isset($item['url']) && is_string($item['url'])) {
                if ($rankCounter <= 10) {
                    $organic[] = [
                        'rank' => (int) ($item['rank_absolute'] ?? $item['rank_group'] ?? $rankCounter),
                        'url' => $item['url'],
                        'title' => (string) ($item['title'] ?? ''),
                        'domain' => (string) ($item['domain'] ?? parse_url($item['url'], PHP_URL_HOST) ?? ''),
                        'description' => (string) ($item['description'] ?? ''),
                    ];
                    $rankCounter++;
                }
            } elseif ($type === 'people_also_ask') {
                $qItems = $item['items'] ?? [];
                if (is_array($qItems)) {
                    foreach ($qItems as $q) {
                        if (is_array($q) && isset($q['title']) && is_string($q['title'])) {
                            $questions[] = trim($q['title']);
                        }
                    }
                }
            }
        }

        return [
            'organic' => $organic,
            'questions' => array_values(array_unique($questions)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function generateMarkdown(Project $project, string $keyword, array $data): string
    {
        $metrics = $data['metrics'];
        $recs = $data['recommendations'];
        $competitors = $data['competitors'];
        $schemas = implode(', ', $recs['recommendedSchemas']);

        $md = [];
        $md[] = "# SEO Content Brief: {$keyword}";
        $md[] = '';
        $md[] = "> Prepared for **{$project->name}** (`{$project->domain}`) on ".now()->format('F j, Y');
        $md[] = '';
        $md[] = '## 1. Target Keyword & Core Metrics';
        $md[] = "- **Target Keyword:** `{$keyword}`";
        $md[] = '- **Monthly Search Volume:** '.number_format((int) $data['searchVolume']).' searches/mo';
        $md[] = '- **Target CPC:** $'.number_format((float) $data['cpc'], 2);
        $md[] = "- **Recommended Word Count:** {$recs['suggestedWordCount']} (Top-3 Competitor Avg: {$metrics['avgWordCountTop3']} words)";
        $md[] = "- **Recommended Schema Markup:** `{$schemas}`";
        $md[] = '';
        $md[] = '---';
        $md[] = '';
        $md[] = '## 2. Key Headings & Subtopic Coverage';
        $md[] = 'Incorporate the following core themes and subtopics identified across top ranking competitor articles:';
        $md[] = '';
        foreach ($recs['keyH2Themes'] as $idx => $theme) {
            $num = $idx + 1;
            $md[] = "{$num}. **{$theme}**";
        }
        $md[] = '';
        $md[] = '---';
        $md[] = '';
        $md[] = '## 3. People Also Ask & FAQ Opportunities';
        $md[] = 'Directly answer these frequently searched user questions in a dedicated FAQ section or subsections:';
        $md[] = '';
        if ($recs['questionsToAnswer'] !== []) {
            foreach ($recs['questionsToAnswer'] as $q) {
                $md[] = "- [ ] **{$q}**";
            }
        } else {
            $md[] = "- [ ] What is {$keyword} and why does it matter?";
            $md[] = "- [ ] How do you implement {$keyword} step-by-step?";
            $md[] = "- [ ] What are the best practices and common pitfalls for {$keyword}?";
        }
        $md[] = '';
        $md[] = '---';
        $md[] = '';
        $md[] = '## 4. Top-10 SERP Competitor Structure Matrix';
        $md[] = '| Rank | Domain | Title | Word Count | Status | Schema |';
        $md[] = '| :--- | :--- | :--- | :--- | :--- | :--- |';
        foreach ($competitors as $c) {
            $schemaList = ! empty($c['schemaTypes']) ? implode(', ', array_slice($c['schemaTypes'], 0, 2)) : '-';
            $words = $c['wordCount'] > 0 ? number_format((int) $c['wordCount']) : '-';
            $status = $c['error'] !== null ? "⚠️ {$c['error']}" : '200 OK';
            $titleSafe = str_replace('|', '-', (string) $c['title']);
            $md[] = "| #{$c['rank']} | `{$c['domain']}` | [{$titleSafe}]({$c['url']}) | {$words} | {$status} | `{$schemaList}` |";
        }
        $md[] = '';
        $md[] = '---';
        $md[] = '';
        $md[] = '## 5. Competitor Heading Outlines';
        foreach ($competitors as $c) {
            if ($c['error'] !== null) {
                continue;
            }

            $md[] = "### Rank #{$c['rank']}: {$c['domain']} ({$c['wordCount']} words)";
            $h1List = $c['headings']['h1'] ?? [];
            if ($h1List !== []) {
                $md[] = '- **H1:** '.implode(' / ', $h1List);
            }
            $h2List = $c['headings']['h2'] ?? [];
            if ($h2List !== []) {
                $md[] = '- **H2 Sections:**';
                foreach ($h2List as $h2) {
                    $md[] = "  - {$h2}";
                }
            }
            $md[] = '';
        }

        return implode("\n", $md);
    }
}
