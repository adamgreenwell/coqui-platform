<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Services;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use Throwable;

class BacklinkIntersectService
{
    public function __construct(
        private readonly DataForSeoClient $dataForSeo,
    ) {}

    /**
     * Discover referring domains linking to competitor targets but not your project domain.
     *
     * @param  list<string>  $competitors
     * @return array{
     *     has_competitors: bool,
     *     competitors: list<string>,
     *     opportunities: list<array<string, mixed>>,
     *     metrics: array{
     *         total_opportunities: int,
     *         high_authority: int,
     *         low_spam: int,
     *         competitor_count: int
     *     }
     * }
     */
    public function getIntersectData(
        Project $project,
        array $competitors = [],
        int $minRank = 0,
        int $maxSpam = 100,
        int $minIntersections = 2,
        bool $fresh = false,
    ): array {
        $analyzedCompetitors = $competitors !== []
            ? array_slice($competitors, 0, 3)
            : $project->competitors()->take(3)->pluck('domain')->all();

        if ($analyzedCompetitors === []) {
            return [
                'has_competitors' => false,
                'competitors' => [],
                'opportunities' => [],
                'metrics' => [
                    'total_opportunities' => 0,
                    'high_authority' => 0,
                    'low_spam' => 0,
                    'competitor_count' => 0,
                ],
            ];
        }

        $targets = [];
        $targetIndexToDomain = [];
        foreach ($analyzedCompetitors as $idx => $domain) {
            $key = (string) ($idx + 1);
            $cleanDomain = strtolower(trim((string) $domain));
            $targets[$key] = $cleanDomain;
            $targetIndexToDomain[$key] = $cleanDomain;
        }

        $excludeTargets = [$this->resolveTarget($project)];

        $opportunities = [];
        try {
            $response = $this->dataForSeo->backlinksDomainIntersection(
                targets: $targets,
                excludeTargets: $excludeTargets,
                project: $project,
                limit: 100,
                forceFresh: $fresh,
            );

            $items = $response['tasks'][0]['result'][0]['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $domain = (string) ($item['domain'] ?? '');
                    if ($domain === '') {
                        continue;
                    }

                    $rank = (int) ($item['rank'] ?? 0);
                    $backlinks = (int) ($item['backlinks'] ?? 0);
                    $spamScore = (int) ($item['spam_score'] ?? 0);
                    $firstSeen = isset($item['first_seen']) ? (string) $item['first_seen'] : null;

                    // Intersection map from DataForSEO
                    $rawIntersection = $item['intersection'] ?? [];
                    $linkedCompetitors = [];

                    if (is_array($rawIntersection)) {
                        foreach ($rawIntersection as $k => $val) {
                            if (! empty($val) && isset($targetIndexToDomain[(string) $k])) {
                                $linkedCompetitors[] = $targetIndexToDomain[(string) $k];
                            }
                        }
                    }

                    $intersectionCount = count($linkedCompetitors);

                    // If DataForSEO didn't supply intersection map or single item, fallback to all targets
                    if ($intersectionCount === 0) {
                        $intersectionCount = count($analyzedCompetitors);
                        $linkedCompetitors = $analyzedCompetitors;
                    }

                    if ($rank < $minRank || $spamScore > $maxSpam || $intersectionCount < $minIntersections) {
                        continue;
                    }

                    $opportunities[] = [
                        'domain' => $domain,
                        'rank' => $rank,
                        'backlinks' => $backlinks,
                        'spam_score' => $spamScore,
                        'first_seen' => $firstSeen,
                        'intersections_count' => $intersectionCount,
                        'linked_competitors' => $linkedCompetitors,
                    ];
                }
            }
        } catch (Throwable) {
            // Keep empty opportunities
        }

        $total = count($opportunities);
        $highAuthority = count(array_filter($opportunities, static fn (array $o): bool => (int) $o['rank'] >= 500));
        $lowSpam = count(array_filter($opportunities, static fn (array $o): bool => (int) $o['spam_score'] <= 20));

        return [
            'has_competitors' => true,
            'competitors' => $analyzedCompetitors,
            'opportunities' => $opportunities,
            'metrics' => [
                'total_opportunities' => $total,
                'high_authority' => $highAuthority,
                'low_spam' => $lowSpam,
                'competitor_count' => count($analyzedCompetitors),
            ],
        ];
    }

    /**
     * Discover competitor 404 pages receiving backlinks for reclaim outreach opportunities.
     *
     * @param  list<string>  $competitors
     * @return list<array<string, mixed>>
     */
    public function getBrokenReclaimOpportunities(
        Project $project,
        array $competitors = [],
        bool $fresh = false,
    ): array {
        $analyzedCompetitors = $competitors !== []
            ? array_slice($competitors, 0, 3)
            : $project->competitors()->take(3)->pluck('domain')->all();

        if ($analyzedCompetitors === []) {
            return [];
        }

        $reclaimList = [];

        foreach ($analyzedCompetitors as $competitorDomain) {
            $cleanDomain = strtolower(trim((string) $competitorDomain));
            if ($cleanDomain === '') {
                continue;
            }

            try {
                $response = $this->dataForSeo->backlinksBrokenPages(
                    target: $cleanDomain,
                    project: $project,
                    limit: 50,
                    forceFresh: $fresh,
                );

                $items = $response['tasks'][0]['result'][0]['items'] ?? [];
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $pageUrl = (string) ($item['page'] ?? $item['url'] ?? '');
                    if ($pageUrl === '') {
                        continue;
                    }

                    $statusCode = (int) ($item['status_code'] ?? 0);
                    $isBroken = (bool) ($item['is_broken'] ?? false);
                    $backlinks = (int) ($item['backlinks'] ?? 0);
                    $referringDomains = (int) ($item['referring_domains'] ?? 0);
                    $dofollow = (int) ($item['dofollow'] ?? 0);

                    // Consider broken if status is 404/410/5xx or explicitly flagged as broken
                    if ($statusCode === 404 || $statusCode === 410 || ($statusCode >= 400 && $backlinks > 0) || $isBroken) {
                        $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';

                        $reclaimList[] = [
                            'competitor_domain' => $cleanDomain,
                            'page_url' => $pageUrl,
                            'path' => $path,
                            'status_code' => $statusCode > 0 ? $statusCode : 404,
                            'backlinks' => $backlinks,
                            'referring_domains' => $referringDomains,
                            'dofollow' => $dofollow,
                            'dofollow_ratio' => $backlinks > 0 ? (int) round(($dofollow / $backlinks) * 100) : 0,
                            'pitch_idea' => sprintf(
                                'Outreach hook: %s 404 dead resource with %d backlinks. Pitch relevant %s page as replacement.',
                                $cleanDomain,
                                $backlinks,
                                $project->domain
                            ),
                        ];
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        usort($reclaimList, static fn (array $a, array $b): int => (int) $b['backlinks'] <=> (int) $a['backlinks']);

        return $reclaimList;
    }

    private function resolveTarget(Project $project): string
    {
        $domain = trim($project->domain);
        if ($domain !== '') {
            return strtolower($domain);
        }

        $host = parse_url((string) $project->canonical_url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        return 'example.com';
    }
}
