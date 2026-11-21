<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\SafeHttpClient;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunnrDeploySmokeService
{
    public function __construct(
        private readonly SafeHttpClient $http = new SafeHttpClient,
    ) {}

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
            $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('services.runnr.webhook_secret'));
            rescue(fn () => $this->http->postJson($callbackUrl, $payload, [
                'X-Runnr-Signature' => $signature,
            ], timeoutSeconds: 10), report: true);
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

        // Check 1: Robots.txt Disallow Regressions
        $robotsIssues = DB::table('crawl_issues')
            ->where('crawl_run_id', $run->id)
            ->whereIn('rule', ['robots_disallowed', 'robots_txt_disallow_all'])
            ->pluck('url')
            ->all();

        $rootUrls = array_filter([$run->seed, rtrim($run->seed, '/'), rtrim($run->seed, '/').'/']);

        $disallowedRoot = false;
        foreach ($robotsIssues as $issueUrl) {
            if (in_array($issueUrl, $rootUrls, true) || $issueUrl === '/' || $issueUrl === '') {
                $disallowedRoot = true;
                break;
            }
        }

        if ($disallowedRoot && $environment === 'production') {
            $regressions[] = [
                'rule' => 'robots_txt_disallow_all',
                'severity' => 'critical',
                'message' => 'Robots.txt contains Disallow: / blocking all search engine indexing on production.',
                'affected_urls' => array_values(array_unique(array_merge([$run->seed], $robotsIssues))),
            ];
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
            $secret = (string) config('services.runnr.webhook_secret');
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
                $payloadJson = json_encode($callbackPayload, JSON_THROW_ON_ERROR);
                $signature = hash_hmac('sha256', $payloadJson, $secret);

                // The callback URL comes from the webhook payload, so it goes
                // through the SSRF-safe client (no redirects, pinned DNS).
                rescue(fn () => $this->http->postJson($callbackUrl, $callbackPayload, [
                    'X-Runnr-Signature' => $signature,
                ], timeoutSeconds: 10), report: true);
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
