<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Services;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Models\CrawlUrl;
use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use Throwable;

class BacklinkProfileService
{
    public const SUMMARY_ENDPOINT = '/v3/backlinks/summary/live';

    public const DOMAINS_ENDPOINT = '/v3/backlinks/referring_domains/live';

    public const ANCHORS_ENDPOINT = '/v3/backlinks/anchors/live';

    public const PAGES_ENDPOINT = '/v3/backlinks/pages/live';

    public function __construct(
        private readonly DataForSeoClient $dataForSeo,
        private readonly DataForSeoCacheRepository $cache,
    ) {}

    /**
     * Retrieve complete backlink intelligence for a project, joining top linked pages
     * against the project's latest CrawlRun to detect high-authority 404 targets.
     *
     * @return array{
     *     summary: array<string, mixed>,
     *     referring_domains: list<array<string, mixed>>,
     *     anchors: list<array<string, mixed>>,
     *     top_pages: list<array<string, mixed>>,
     *     broken_pages_needing_redirects: list<array<string, mixed>>,
     *     crawl_run: array<string, mixed>|null,
     * }
     */
    public function getBacklinkProfile(Project $project, bool $fresh = false): array
    {
        $target = $this->resolveTarget($project);
        $ttlHours = (int) config('coqui.dataforseo.cache_ttl_hours.backlinks_summary', 336);

        // 1. Fetch or cache summary
        $summary = $this->fetchSummary($project, $target, $ttlHours, $fresh);

        // 2. Fetch or cache referring domains
        $referringDomains = $this->fetchReferringDomains($project, $target, $ttlHours, $fresh);

        // 3. Fetch or cache top anchor texts
        $anchors = $this->fetchAnchors($project, $target, $ttlHours, $fresh);

        // 4. Fetch or cache top linked pages
        $rawPages = $this->fetchPages($project, $target, $ttlHours, $fresh);

        // 5. Cross-reference top pages against latest CrawlRun
        $crawlCrossReference = $this->crossReferencePagesWithLatestCrawl($project, $rawPages);

        return [
            'summary' => $summary,
            'referring_domains' => $referringDomains,
            'anchors' => $anchors,
            'top_pages' => $crawlCrossReference['pages'],
            'broken_pages_needing_redirects' => $crawlCrossReference['broken_pages'],
            'crawl_run' => $crawlCrossReference['crawl_run'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSummary(Project $project, string $target, int $ttlHours, bool $fresh): array
    {
        $signature = ['target' => $target];

        if (! $fresh) {
            $cached = $this->cache->get(self::SUMMARY_ENDPOINT, $signature);
            if ($cached !== null) {
                $cached['cached'] = true;

                return $cached;
            }
        }

        try {
            $response = $this->dataForSeo->backlinksSummary($target, $project);
            $parsed = $this->parseSummaryResponse($response);
            $parsed['cached'] = false;
            $parsed['fetched_at'] = now()->toIso8601String();

            $this->cache->put(self::SUMMARY_ENDPOINT, $signature, $parsed, $ttlHours);

            return $parsed;
        } catch (Throwable) {
            return [
                'rank' => 0,
                'backlinks' => 0,
                'referring_domains' => 0,
                'referring_main_domains' => 0,
                'referring_pages' => 0,
                'dofollow' => 0,
                'nofollow' => 0,
                'dofollow_ratio' => 0.0,
                'cached' => false,
                'fetched_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchReferringDomains(Project $project, string $target, int $ttlHours, bool $fresh): array
    {
        $signature = ['target' => $target, 'limit' => 100];

        if (! $fresh) {
            $cached = $this->cache->get(self::DOMAINS_ENDPOINT, $signature);
            if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
                /** @var list<array<string, mixed>> $items */
                $items = $cached['items'];

                return $items;
            }
        }

        try {
            $response = $this->dataForSeo->backlinksReferringDomains($target, $project, 100);
            $items = $this->parseReferringDomainsResponse($response);

            $this->cache->put(self::DOMAINS_ENDPOINT, $signature, ['items' => $items], $ttlHours);

            return $items;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAnchors(Project $project, string $target, int $ttlHours, bool $fresh): array
    {
        $signature = ['target' => $target, 'limit' => 100];

        if (! $fresh) {
            $cached = $this->cache->get(self::ANCHORS_ENDPOINT, $signature);
            if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
                /** @var list<array<string, mixed>> $items */
                $items = $cached['items'];

                return $items;
            }
        }

        try {
            $response = $this->dataForSeo->backlinksAnchors($target, $project, 100);
            $items = $this->parseAnchorsResponse($response);

            $this->cache->put(self::ANCHORS_ENDPOINT, $signature, ['items' => $items], $ttlHours);

            return $items;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPages(Project $project, string $target, int $ttlHours, bool $fresh): array
    {
        $signature = ['target' => $target, 'limit' => 100];

        if (! $fresh) {
            $cached = $this->cache->get(self::PAGES_ENDPOINT, $signature);
            if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
                /** @var list<array<string, mixed>> $items */
                $items = $cached['items'];

                return $items;
            }
        }

        try {
            $response = $this->dataForSeo->backlinksPages($target, $project, 100);
            $items = $this->parsePagesResponse($response);

            $this->cache->put(self::PAGES_ENDPOINT, $signature, ['items' => $items], $ttlHours);

            return $items;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function parseSummaryResponse(array $response): array
    {
        $res = $response['tasks'][0]['result'][0] ?? [];
        if (! is_array($res)) {
            $res = [];
        }

        $rank = (int) ($res['rank'] ?? 0);
        $backlinks = (int) ($res['backlinks'] ?? 0);
        $pages = (int) ($res['referring_pages'] ?? 0);
        $domains = (int) ($res['referring_domains'] ?? 0);
        $mainDomains = (int) ($res['referring_main_domains'] ?? $domains);

        $dofollow = (int) ($res['dofollow'] ?? $res['info']['dofollow'] ?? 0);
        $nofollow = (int) ($res['nofollow'] ?? $res['info']['nofollow'] ?? 0);

        if ($dofollow === 0 && $nofollow === 0 && $backlinks > 0) {
            // Estimate standard distribution if split not itemized
            $dofollow = (int) round($backlinks * 0.78);
            $nofollow = $backlinks - $dofollow;
        }

        $totalLinks = $dofollow + $nofollow;
        $dofollowRatio = $totalLinks > 0 ? round(($dofollow / $totalLinks) * 100, 1) : 0.0;

        return [
            'rank' => $rank,
            'backlinks' => $backlinks,
            'referring_domains' => $domains,
            'referring_main_domains' => $mainDomains,
            'referring_pages' => $pages,
            'dofollow' => $dofollow,
            'nofollow' => $nofollow,
            'dofollow_ratio' => $dofollowRatio,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function parseReferringDomainsResponse(array $response): array
    {
        $items = $response['tasks'][0]['result'][0]['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $domain = (string) ($item['domain'] ?? '');
            if ($domain === '') {
                continue;
            }

            $backlinks = (int) ($item['backlinks'] ?? 0);
            $dofollow = (int) ($item['dofollow'] ?? 0);
            $nofollow = (int) ($item['nofollow'] ?? max(0, $backlinks - $dofollow));
            $total = $dofollow + $nofollow;
            $ratio = $total > 0 ? round(($dofollow / $total) * 100, 1) : 0.0;

            $list[] = [
                'domain' => $domain,
                'rank' => (int) ($item['rank'] ?? 0),
                'backlinks' => $backlinks,
                'dofollow' => $dofollow,
                'nofollow' => $nofollow,
                'dofollow_ratio' => $ratio,
                'spam_score' => (int) ($item['spam_score'] ?? $item['backlinks_spam_score'] ?? 0),
                'first_seen' => isset($item['first_seen']) ? (string) $item['first_seen'] : null,
                'last_seen' => isset($item['last_seen']) ? (string) $item['last_seen'] : null,
            ];
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function parseAnchorsResponse(array $response): array
    {
        $items = $response['tasks'][0]['result'][0]['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $anchor = trim((string) ($item['anchor'] ?? ''));
            if ($anchor === '') {
                $anchor = '<empty anchor>';
            }

            $backlinks = (int) ($item['backlinks'] ?? 0);
            $domains = (int) ($item['referring_domains'] ?? 0);
            $dofollow = (int) ($item['dofollow'] ?? 0);
            $nofollow = (int) ($item['nofollow'] ?? max(0, $backlinks - $dofollow));
            $total = $dofollow + $nofollow;
            $ratio = $total > 0 ? round(($dofollow / $total) * 100, 1) : 0.0;

            $list[] = [
                'anchor' => $anchor,
                'backlinks' => $backlinks,
                'referring_domains' => $domains,
                'dofollow' => $dofollow,
                'nofollow' => $nofollow,
                'dofollow_ratio' => $ratio,
            ];
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function parsePagesResponse(array $response): array
    {
        $items = $response['tasks'][0]['result'][0]['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = (string) ($item['page'] ?? $item['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $backlinks = (int) ($item['backlinks'] ?? 0);
            $dofollow = (int) ($item['dofollow'] ?? 0);
            $nofollow = (int) ($item['nofollow'] ?? max(0, $backlinks - $dofollow));

            $list[] = [
                'url' => $url,
                'path' => (string) parse_url($url, PHP_URL_PATH) ?: '/',
                'rank' => (int) ($item['rank'] ?? 0),
                'backlinks' => $backlinks,
                'referring_domains' => (int) ($item['referring_domains'] ?? 0),
                'dofollow' => $dofollow,
                'nofollow' => $nofollow,
            ];
        }

        return $list;
    }

    /**
     * Join top linked pages with the project's latest completed CrawlRun to detect
     * high-authority 404 pages needing 301 redirects.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array{
     *     pages: list<array<string, mixed>>,
     *     broken_pages: list<array<string, mixed>>,
     *     crawl_run: array<string, mixed>|null
     * }
     */
    private function crossReferencePagesWithLatestCrawl(Project $project, array $pages): array
    {
        /** @var CrawlRun|null $latestCrawl */
        $latestCrawl = CrawlRun::query()
            ->where('project_id', $project->id)
            ->where('status', 'completed')
            ->latest('finalized_at')
            ->first();

        /** @var array<string, int> $urlStatusMap */
        $urlStatusMap = [];
        /** @var array<string, int> $pathStatusMap */
        $pathStatusMap = [];

        if ($latestCrawl !== null) {
            $crawledUrls = CrawlUrl::query()
                ->where('crawl_run_id', $latestCrawl->id)
                ->get(['url', 'status']);

            foreach ($crawledUrls as $row) {
                $rawUrl = (string) $row->url;
                $status = (int) $row->status;
                $normalized = strtolower(rtrim($rawUrl, '/'));
                $urlStatusMap[$normalized] = $status;

                $path = parse_url($rawUrl, PHP_URL_PATH);
                if (is_string($path) && $path !== '') {
                    $normPath = strtolower(rtrim($path, '/'));
                    $pathStatusMap[$normPath] = $status;
                }
            }
        }

        $enhancedPages = [];
        $brokenPages = [];

        foreach ($pages as $p) {
            $pageUrl = (string) $p['url'];
            $normalizedPageUrl = strtolower(rtrim($pageUrl, '/'));
            $pagePath = (string) parse_url($pageUrl, PHP_URL_PATH) ?: '/';
            $normPath = strtolower(rtrim($pagePath, '/'));

            $crawlStatus = $urlStatusMap[$normalizedPageUrl] ?? $pathStatusMap[$normPath] ?? null;
            $isBroken = $crawlStatus !== null && $crawlStatus >= 400;

            $rank = (int) $p['rank'];
            $backlinks = (int) $p['backlinks'];

            $priority = null;
            if ($isBroken) {
                $priority = ($backlinks >= 5 || $rank >= 20) ? 'high' : 'medium';
            }

            $enhanced = array_merge($p, [
                'path' => $pagePath,
                'crawl_status' => $crawlStatus,
                'is_broken' => $isBroken,
                'redirect_priority' => $priority,
            ]);

            $enhancedPages[] = $enhanced;

            if ($isBroken) {
                $brokenPages[] = [
                    'url' => $pageUrl,
                    'path' => $pagePath,
                    'rank' => $rank,
                    'backlinks' => $backlinks,
                    'referring_domains' => (int) $p['referring_domains'],
                    'crawl_status' => (int) $crawlStatus,
                    'redirect_priority' => (string) $priority,
                ];
            }
        }

        // Sort broken pages by backlinks desc, then rank desc
        usort($brokenPages, fn ($a, $b) => ($b['backlinks'] <=> $a['backlinks']) ?: ($b['rank'] <=> $a['rank']));

        $crawlRunSummary = $latestCrawl !== null ? [
            'id' => $latestCrawl->external_crawl_id,
            'status' => $latestCrawl->status,
            'finished_at' => $latestCrawl->finalized_at?->toIso8601String(),
            'total_urls' => $latestCrawl->total_urls,
        ] : null;

        return [
            'pages' => $enhancedPages,
            'broken_pages' => $brokenPages,
            'crawl_run' => $crawlRunSummary,
        ];
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
