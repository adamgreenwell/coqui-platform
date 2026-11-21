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

        // The client sends competitors in this canonical (sorted) order, so
        // the intersection map's "1", "2", ... keys index this list.
        $targetIndexToDomain = DataForSeoClient::canonicalIntersectionTargets(
            array_map(static fn (mixed $d): string => (string) $d, $analyzedCompetitors),
        );
        $targets = $targetIndexToDomain;

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

                    // Which competitors this domain links to, from the
                    // provider's per-target map. Without a map the overlap is
                    // unknown: it is never assumed to be every competitor.
                    $rawIntersection = $item['intersection'] ?? $item['domain_intersection'] ?? null;
                    $linkedCompetitors = [];
                    $intersectionCount = null;

                    if (is_array($rawIntersection) && $rawIntersection !== []) {
                        foreach ($rawIntersection as $k => $val) {
                            if (! empty($val) && isset($targetIndexToDomain[(string) $k])) {
                                $linkedCompetitors[] = $targetIndexToDomain[(string) $k];
                            }
                        }
                        $intersectionCount = count($linkedCompetitors);
                    }

                    if ($rank < $minRank || $spamScore > $maxSpam) {
                        continue;
                    }
                    // An unknown overlap can't be shown to meet a "links to N
                    // competitors" filter.
                    if ($minIntersections > 1 && ($intersectionCount ?? 0) < $minIntersections) {
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
                    limit: 500,
                    forceFresh: $fresh,
                );
            } catch (Throwable) {
                continue;
            }

            $items = $response['tasks'][0]['result'][0]['items'] ?? [];
            if (! is_array($items)) {
                continue;
            }

            // Each item is one backlink to a broken page; group them by page.
            /** @var array<string, array{status: int|null, backlinks: int, dofollow: int, domains: array<string, true>}> $pages */
            $pages = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $pageUrl = (string) ($item['url_to'] ?? '');
                if ($pageUrl === '') {
                    continue;
                }

                $status = isset($item['url_to_status_code']) ? (int) $item['url_to_status_code'] : null;
                $pages[$pageUrl] ??= ['status' => $status, 'backlinks' => 0, 'dofollow' => 0, 'domains' => []];
                $pages[$pageUrl]['backlinks']++;
                if (($item['dofollow'] ?? false) === true) {
                    $pages[$pageUrl]['dofollow']++;
                }
                $fromDomain = strtolower((string) ($item['domain_from'] ?? ''));
                if ($fromDomain !== '') {
                    $pages[$pageUrl]['domains'][$fromDomain] = true;
                }
                if ($pages[$pageUrl]['status'] === null && $status !== null) {
                    $pages[$pageUrl]['status'] = $status;
                }
            }

            foreach ($pages as $pageUrl => $page) {
                $backlinks = $page['backlinks'];
                $statusCode = $page['status'] !== null && $page['status'] > 0 ? $page['status'] : null;

                $reclaimList[] = [
                    'competitor_domain' => $cleanDomain,
                    'page_url' => $pageUrl,
                    'path' => parse_url($pageUrl, PHP_URL_PATH) ?: '/',
                    'status_code' => $statusCode,
                    'backlinks' => $backlinks,
                    'referring_domains' => count($page['domains']),
                    'dofollow' => $page['dofollow'],
                    'dofollow_ratio' => $backlinks > 0 ? (int) round(($page['dofollow'] / $backlinks) * 100) : null,
                    'pitch_idea' => sprintf(
                        'Outreach hook: %s has a dead page (%s) with %d broken backlinks. Pitch a relevant %s page as the replacement.',
                        $cleanDomain,
                        $statusCode !== null ? 'HTTP '.$statusCode : 'broken',
                        $backlinks,
                        $project->domain
                    ),
                ];
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
