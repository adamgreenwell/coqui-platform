<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Crawls\CrawlSource;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\SafeHttpClient;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunnrDeploySmokeService
{
    public function __construct(
        private readonly SafeHttpClient $http = new SafeHttpClient,
        private readonly DeploySiteFilesInspector $files = new DeploySiteFilesInspector,
    ) {}

    /**
     * Headers for a callback to Runnr, signed the same way Runnr signs its
     * webhooks to us: HMAC-SHA256 of "{timestamp}.{body}".
     *
     * @param  array<string, mixed>  $payload
     * @return array{X-Runnr-Timestamp: string, X-Runnr-Signature: string}
     */
    public static function signedHeaders(array $payload): array
    {
        $timestamp = (string) now()->getTimestamp();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'X-Runnr-Timestamp' => $timestamp,
            'X-Runnr-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.runnr.webhook_secret')),
        ];
    }

    /**
     * robots.txt and sitemap results from this project's last evaluated
     * smoke run, to diff against.
     *
     * @return array<string, mixed>|null
     */
    private function previousChecks(CrawlRun $run, string $environment): ?array
    {
        // Filtered in the query, so any number of other-environment runs in
        // between can't push the baseline out of reach.
        $previous = CrawlRun::query()
            ->where('project_id', $run->project_id)
            ->where('source', CrawlSource::DeploySmoke->value)
            ->where('id', '<', $run->id)
            // A preview deploy is no baseline for production, or vice versa
            // (a run without an environment counts as production).
            ->where(fn ($query) => $query
                ->where('config->deploy->environment', $environment)
                ->when($environment === 'production', fn ($q) => $q->orWhereNull('config->deploy->environment')))
            ->whereNotNull('config->smoke_results->checks->robots')
            ->whereNotNull('config->smoke_results->checks->sitemap')
            ->orderByDesc('id')
            ->first(['id', 'config']);

        $checks = $previous?->config['smoke_results']['checks'] ?? null;

        return is_array($checks) ? $checks : null;
    }

    /**
     * The smoke crawl couldn't run (the worker was unreachable or stopped
     * reporting). Record it and tell Runnr the check failed, so the deploy
     * log doesn't wait on a result that will never come.
     */
    public function reportFailure(CrawlRun $run, string $reason): void
    {
        $config = $run->config;
        /** @var array<string, mixed> $deploy */
        $deploy = is_array($config['deploy'] ?? null) ? $config['deploy'] : [];
        $summary = "Deploy SEO Smoke Test FAILED: the smoke crawl couldn't run. {$reason}";

        $config['smoke_results'] = [
            'status' => 'failed',
            'passed' => false,
            'regressions' => [],
            'summary' => $summary,
            'total_urls_checked' => 0,
            'evaluated_at' => now()->toIso8601String(),
        ];
        $run->update(['config' => $config]);

        $project = $run->project;
        if ($project !== null) {
            ProjectActivity::log(
                project: $project,
                eventType: 'deploy.smoke_failed',
                title: '🚨 Deploy Smoke Test Failed',
                description: $summary,
                properties: [
                    'crawl_id' => $run->id,
                    'external_crawl_id' => $run->external_crawl_id,
                    'deploy_id' => $deploy['deploy_id'] ?? null,
                    'environment' => (string) ($deploy['environment'] ?? 'production'),
                    'status' => 'failed',
                    'total_urls_checked' => 0,
                ],
            );
        }

        $callbackUrl = isset($deploy['callback_url']) ? (string) $deploy['callback_url'] : '';
        if ($callbackUrl === '') {
            return;
        }

        $payload = [
            'deployId' => $deploy['deploy_id'] ?? null,
            'siteId' => $project?->runnr_site_id,
            'crawlId' => $run->external_crawl_id,
            'status' => 'failed',
            'passed' => false,
            'summary' => $summary,
            'regressions' => [],
            'totalUrlsChecked' => 0,
            'completedAt' => now()->toIso8601String(),
        ];

        try {
            $headers = self::signedHeaders($payload);
            rescue(fn () => $this->http->postJson($callbackUrl, $payload, $headers, timeoutSeconds: 10), report: true);
        } catch (Throwable) {
            // The failure is already recorded; the callback is best effort.
        }
    }

    /**
     * Evaluates a completed deploy_smoke crawl for critical SEO regressions.
     *
     * @return array{
     *     status: 'passed'|'failed'|'warning',
     *     passed: bool,
     *     regressions: list<array{rule: string, severity: string, message: string, affected_urls: list<string>}>,
     *     summary: string,
     *     total_urls_checked: int,
     * }
     */
    public function evaluate(CrawlRun $run): array
    {
        $config = $run->config;
        /** @var array<string, mixed> $deploy */
        $deploy = is_array($config['deploy'] ?? null) ? $config['deploy'] : [];

        $environment = (string) ($deploy['environment'] ?? 'production');
        $callbackUrl = isset($deploy['callback_url']) ? (string) $deploy['callback_url'] : null;
        $project = $run->project;

        /** @var list<array{rule: string, severity: string, message: string, affected_urls: list<string>}> $regressions */
        $regressions = [];

        $totalUrls = (int) DB::table('crawl_urls')->where('crawl_run_id', $run->id)->count();

        $rootUrls = array_filter([$run->seed, rtrim($run->seed, '/'), rtrim($run->seed, '/').'/']);

        // robots.txt and the sitemap are fetched directly: a crawl blocked by
        // robots.txt finds no pages, so its results can't show the block.
        $checks = $this->files->inspect($run->seed);
        $previousChecks = $this->previousChecks($run, $environment);

        // Check 0: Nothing crawled. There's nothing to judge, so it can't pass.
        if ($totalUrls === 0) {
            $regressions[] = [
                'rule' => 'no_urls_crawled',
                'severity' => 'critical',
                'message' => $checks['robots']['disallow_all']
                    ? 'The smoke crawl reached no pages: robots.txt blocks all crawling (Disallow: /).'
                    : 'The smoke crawl reached no pages; the site may be down or refusing crawlers.',
                'affected_urls' => [$run->seed],
            ];
        }

        // Check 1: Robots.txt Disallow Regressions
        if ($checks['robots']['disallow_all'] && $environment === 'production') {
            $regressions[] = [
                'rule' => 'robots_txt_disallow_all',
                'severity' => 'critical',
                'message' => 'Robots.txt contains Disallow: / blocking all search engine indexing on production.',
                'affected_urls' => [$checks['robots']['url']],
            ];
        } elseif ($previousChecks !== null
            && ($previousChecks['robots']['hash'] ?? null) !== null
            && $checks['robots']['hash'] !== ($previousChecks['robots']['hash'] ?? null)) {
            $regressions[] = [
                'rule' => 'robots_txt_changed',
                'severity' => 'low',
                'message' => 'Robots.txt changed since the last deploy check.',
                'affected_urls' => [$checks['robots']['url']],
            ];
        }

        // Check 1b: Sitemap missing or shrunk since the last deploy check. A
        // sitemap too large to fetch can't be compared, so it isn't flagged.
        if ($environment === 'production' && $previousChecks !== null && ($previousChecks['sitemap']['valid'] ?? false) === true
            && ! $checks['sitemap']['too_large']) {
            $previousCount = (int) ($previousChecks['sitemap']['url_count'] ?? 0);
            $currentCount = (int) ($checks['sitemap']['url_count'] ?? 0);

            if (! $checks['sitemap']['valid']) {
                $regressions[] = [
                    'rule' => 'sitemap_missing',
                    'severity' => 'high',
                    'message' => 'The XML sitemap is missing or no longer valid (HTTP '.($checks['sitemap']['status'] ?? 'no response').').',
                    'affected_urls' => array_filter([$checks['sitemap']['url']]),
                ];
            } elseif ($previousCount >= 10 && $currentCount < $previousCount / 2) {
                $regressions[] = [
                    'rule' => 'sitemap_shrunk',
                    'severity' => 'medium',
                    'message' => "The XML sitemap lists {$currentCount} entries, down from {$previousCount} at the last deploy check.",
                    'affected_urls' => array_filter([$checks['sitemap']['url']]),
                ];
            }
        }

        // Check 2: Accidental Global Noindex
        $noindexUrls = DB::table('crawl_urls')
            ->where('crawl_run_id', $run->id)
            ->where(function ($q): void {
                $q->where('meta_robots', 'LIKE', '%noindex%')
                    ->orWhere('error', 'LIKE', '%noindex%');
            })
            ->pluck('url')
            ->map(fn ($u) => (string) $u)
            ->all();

        $rootHasNoindex = false;
        foreach ($noindexUrls as $u) {
            if (in_array($u, $rootUrls, true)) {
                $rootHasNoindex = true;
                break;
            }
        }

        $noindexCount = count($noindexUrls);
        $noindexRatio = $totalUrls > 0 ? $noindexCount / $totalUrls : 0.0;

        if ($environment === 'production' && ($rootHasNoindex || ($totalUrls >= 2 && $noindexRatio >= 0.50) || ($totalUrls === 1 && $noindexCount === 1))) {
            $regressions[] = [
                'rule' => 'accidental_global_noindex',
                'severity' => 'critical',
                'message' => "Accidental global noindex detected on production deployment ({$noindexCount} pages affected).",
                'affected_urls' => array_slice($noindexUrls, 0, 10),
            ];
        }

        // Check 3: Server 5xx Errors
        $serverErrors = DB::table('crawl_urls')
            ->where('crawl_run_id', $run->id)
            ->whereBetween('status', [500, 599])
            ->pluck('url')
            ->map(fn ($u) => (string) $u)
            ->all();

        if ($serverErrors !== []) {
            $errorCount = count($serverErrors);
            $regressions[] = [
                'rule' => 'server_5xx_errors',
                'severity' => 'high',
                'message' => "Server 5xx error encountered on {$errorCount} URLs during post-deploy smoke check.",
                'affected_urls' => array_slice($serverErrors, 0, 10),
            ];
        }

        // Check 4: Canonical Host Flips
        $expectedHost = parse_url($project->canonical_url ?? $run->seed, PHP_URL_HOST);
        $crawledCanonicals = DB::table('crawl_urls')
            ->where('crawl_run_id', $run->id)
            ->whereNotNull('canonical')
            ->select(['url', 'canonical'])
            ->get();

        $flippedUrls = [];
        foreach ($crawledCanonicals as $item) {
            $canonicalHost = parse_url((string) $item->canonical, PHP_URL_HOST);
            if ($canonicalHost === null || $canonicalHost === '') {
                continue;
            }
            $isStagingOrLocal = in_array($canonicalHost, ['localhost', '127.0.0.1'], true)
                || str_starts_with($canonicalHost, 'staging.')
                || str_starts_with($canonicalHost, 'dev.')
                || str_starts_with($canonicalHost, 'preview.')
                || (str_ends_with($canonicalHost, '.runnr.io') && ! str_ends_with((string) $expectedHost, '.runnr.io'));

            if ($isStagingOrLocal || ($expectedHost !== null && $canonicalHost !== $expectedHost)) {
                $flippedUrls[] = (string) $item->url.' -> '.(string) $item->canonical;
            }
        }

        if ($flippedUrls !== []) {
            $flippedCount = count($flippedUrls);
            $regressions[] = [
                'rule' => 'canonical_host_flip',
                'severity' => 'high',
                'message' => "Canonical host flip detected on {$flippedCount} URLs (pointing to staging, localhost, or foreign host).",
                'affected_urls' => array_slice($flippedUrls, 0, 10),
            ];
        }

        // Status & Verdict Determination
        $hasCriticalOrHigh = false;
        foreach ($regressions as $r) {
            if (in_array($r['severity'], ['critical', 'high'], true)) {
                $hasCriticalOrHigh = true;
                break;
            }
        }

        $passed = ! $hasCriticalOrHigh;
        $status = $passed ? ($regressions === [] ? 'passed' : 'warning') : 'failed';
        $summary = $passed
            ? "Deploy SEO Smoke Test PASSED: {$totalUrls} URLs checked with zero critical SEO regressions."
            : 'Deploy SEO Smoke Test FAILED: '.implode('; ', array_column($regressions, 'message'));

        $evaluation = [
            'status' => $status,
            'passed' => $passed,
            'regressions' => $regressions,
            'summary' => $summary,
            'total_urls_checked' => $totalUrls,
            'checks' => $checks,
            'evaluated_at' => now()->toIso8601String(),
        ];

        // Persist smoke evaluation in crawl config
        $config['smoke_results'] = $evaluation;
        $run->update(['config' => $config]);

        // Broadcast Activity & Realtime Alert
        if ($project !== null) {
            if (! $passed) {
                ProjectActivity::log(
                    project: $project,
                    eventType: 'deploy.smoke_failed',
                    title: '🚨 Deploy Smoke Test Failed',
                    description: $summary,
                    properties: [
                        'crawl_id' => $run->id,
                        'external_crawl_id' => $run->external_crawl_id,
                        'deploy_id' => $deploy['deploy_id'] ?? null,
                        'environment' => $environment,
                        'status' => 'failed',
                        'regressions' => $regressions,
                        'total_urls_checked' => $totalUrls,
                    ],
                );
            } else {
                ProjectActivity::log(
                    project: $project,
                    eventType: 'deploy.smoke_passed',
                    title: '✅ Deploy Smoke Test Passed',
                    description: $summary,
                    properties: [
                        'crawl_id' => $run->id,
                        'external_crawl_id' => $run->external_crawl_id,
                        'deploy_id' => $deploy['deploy_id'] ?? null,
                        'environment' => $environment,
                        'status' => 'passed',
                        'total_urls_checked' => $totalUrls,
                    ],
                );
            }
        }

        // Post signed callback to Runnr deployment log if callback_url is set
        if ($callbackUrl !== null && $callbackUrl !== '') {
            $callbackPayload = [
                'deployId' => $deploy['deploy_id'] ?? null,
                'siteId' => $project?->runnr_site_id,
                'crawlId' => $run->external_crawl_id,
                'status' => $status,
                'passed' => $passed,
                'summary' => $summary,
                'regressions' => $regressions,
                'totalUrlsChecked' => $totalUrls,
                'completedAt' => now()->toIso8601String(),
            ];

            try {
                $headers = self::signedHeaders($callbackPayload);

                // The callback URL comes from the webhook payload, so it goes
                // through the SSRF-safe client (no redirects, pinned DNS).
                rescue(fn () => $this->http->postJson($callbackUrl, $callbackPayload, $headers, timeoutSeconds: 10), report: true);
            } catch (Throwable) {
                // Non-blocking callback logging
            }
        }

        return [
            'status' => $status,
            'passed' => $passed,
            'regressions' => $regressions,
            'summary' => $summary,
            'total_urls_checked' => $totalUrls,
        ];
    }
}
