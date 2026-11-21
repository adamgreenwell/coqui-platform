<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Services;

use App\Domains\Backlinks\Events\BacklinkAlertTriggered;
use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Crawls\Services\SafeHttpClient;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;

class BacklinkVerificationService
{
    public function __construct(
        private readonly CoquiWorkerClient $worker,
        private readonly DataForSeoClient $dataForSeo,
        private readonly SafeHttpClient $http = new SafeHttpClient,
    ) {}

    /**
     * Verify all monitored backlinks for a project, tracking status, rel, and noindex changes.
     */
    public function verifyProjectWatchlist(Project $project): int
    {
        $links = $project->monitoredBacklinks()->get();
        if ($links->isEmpty()) {
            return 0;
        }

        $urls = $links->pluck('source_url')->values()->all();

        /** @var array<string, array<string, mixed>> $observations */
        $observations = [];
        try {
            if (! $this->worker->isConfigured()) {
                throw new RuntimeException('Crawl worker is not configured.');
            }
            $result = $this->worker->inspectUrls($urls, $project->domain);
            foreach (($result['results'] ?? []) as $item) {
                if (is_array($item) && isset($item['url'])) {
                    $observations[(string) $item['url']] = $item;
                }
            }
        } catch (Throwable) {
            // Fall back to direct inspection per link
        }

        $updated = 0;
        foreach ($links as $link) {
            /** @var MonitoredBacklink $link */
            $obs = $observations[$link->source_url] ?? null;
            if ($obs === null) {
                $obs = $this->inspectDirectly($link->source_url, $link->target_url);
            }

            $status = isset($obs['status']) ? (int) $obs['status'] : null;
            $hasTargetLink = (bool) ($obs['hasTargetLink'] ?? false);
            $observedRel = isset($obs['observedRel']) ? (string) $obs['observedRel'] : null;
            $noindex = (bool) ($obs['noindex'] ?? false);

            $state = match (true) {
                $status === null || $status >= 400 => 'error',
                ! $hasTargetLink || $noindex => 'lost',
                $link->expected_rel !== null && $observedRel !== $link->expected_rel => 'rel_changed',
                default => 'active',
            };

            $previousState = $link->state;

            $link->update([
                'state' => $state,
                'last_http_status' => $status,
                'last_observed_rel' => $observedRel,
                'last_page_noindex' => $noindex,
                'last_verified_at' => now(),
            ]);

            if ($previousState !== $state) {
                $this->triggerAlertAndLogActivity($project, $link, $previousState, $state, $status, $observedRel);
            }

            $updated++;
        }

        return $updated;
    }

    /**
     * Verify a single monitored backlink and update its state.
     */
    public function verifySingleLink(MonitoredBacklink $link): string
    {
        $project = $link->project;

        $obs = null;
        try {
            if (! $this->worker->isConfigured()) {
                throw new RuntimeException('Crawl worker is not configured.');
            }
            $result = $this->worker->inspectUrls([$link->source_url], $project->domain);
            $items = $result['results'] ?? [];
            if (is_array($items) && isset($items[0]) && is_array($items[0])) {
                $obs = $items[0];
            }
        } catch (Throwable) {
            // Fall back to direct inspection
        }

        if ($obs === null) {
            $obs = $this->inspectDirectly($link->source_url, $link->target_url);
        }

        $status = isset($obs['status']) ? (int) $obs['status'] : null;
        $hasTargetLink = (bool) ($obs['hasTargetLink'] ?? false);
        $observedRel = isset($obs['observedRel']) ? (string) $obs['observedRel'] : null;
        $noindex = (bool) ($obs['noindex'] ?? false);

        $state = match (true) {
            $status === null || $status >= 400 => 'error',
            ! $hasTargetLink || $noindex => 'lost',
            $link->expected_rel !== null && $observedRel !== $link->expected_rel => 'rel_changed',
            default => 'active',
        };

        $previousState = $link->state;

        $link->update([
            'state' => $state,
            'last_http_status' => $status,
            'last_observed_rel' => $observedRel,
            'last_page_noindex' => $noindex,
            'last_verified_at' => now(),
        ]);

        if ($previousState !== $state) {
            $this->triggerAlertAndLogActivity($project, $link, $previousState, $state, $status, $observedRel);
        }

        return $state;
    }

    /**
     * Inspect a source URL directly using HTTP client and HTML parsing.
     *
     * @return array{status: int|null, hasTargetLink: bool, observedRel: string|null, noindex: bool}
     */
    public function inspectDirectly(string $sourceUrl, string $targetUrl): array
    {
        try {
            // Validates every redirect hop and pins DNS (see SafeHttpClient).
            $response = $this->http->get($sourceUrl, timeoutSeconds: 6);

            $status = $response->status();
            if ($status >= 400) {
                return [
                    'status' => $status,
                    'hasTargetLink' => false,
                    'observedRel' => null,
                    'noindex' => false,
                ];
            }

            // Check X-Robots-Tag header
            $xRobots = strtolower((string) $response->header('X-Robots-Tag'));
            $noindex = str_contains($xRobots, 'noindex') || str_contains($xRobots, 'none');

            $html = $response->body();
            if ($html === '') {
                return [
                    'status' => $status,
                    'hasTargetLink' => false,
                    'observedRel' => null,
                    'noindex' => $noindex,
                ];
            }

            $doc = new DOMDocument;
            @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($doc);

            if (! $noindex) {
                $metaRobots = $xpath->query("//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='robots']/@content");
                if ($metaRobots && $metaRobots->length > 0) {
                    $content = strtolower((string) $metaRobots->item(0)?->nodeValue);
                    if (str_contains($content, 'noindex') || str_contains($content, 'none')) {
                        $noindex = true;
                    }
                }
            }

            $targetHost = parse_url($targetUrl, PHP_URL_HOST);
            $targetPath = parse_url($targetUrl, PHP_URL_PATH) ?: '/';

            $anchorNodes = $xpath->query('//a[@href]');
            $hasTargetLink = false;
            $observedRel = null;

            if ($anchorNodes) {
                foreach ($anchorNodes as $anchor) {
                    if (! ($anchor instanceof DOMElement)) {
                        continue;
                    }

                    $href = trim($anchor->getAttribute('href'));
                    if ($href === '') {
                        continue;
                    }

                    $match = false;
                    if ($href === $targetUrl) {
                        $match = true;
                    } else {
                        $hrefHost = parse_url($href, PHP_URL_HOST);
                        $hrefPath = parse_url($href, PHP_URL_PATH) ?: '/';
                        if ($hrefHost && $targetHost && strcasecmp((string) $hrefHost, (string) $targetHost) === 0 && rtrim((string) $hrefPath, '/') === rtrim((string) $targetPath, '/')) {
                            $match = true;
                        }
                    }

                    if ($match) {
                        $hasTargetLink = true;
                        $rel = strtolower(trim($anchor->getAttribute('rel')));
                        if (str_contains($rel, 'nofollow')) {
                            $observedRel = 'nofollow';
                        } elseif (str_contains($rel, 'sponsored')) {
                            $observedRel = 'sponsored';
                        } elseif (str_contains($rel, 'ugc')) {
                            $observedRel = 'ugc';
                        } else {
                            $observedRel = 'dofollow';
                        }
                        break;
                    }
                }
            }

            return [
                'status' => $status,
                'hasTargetLink' => $hasTargetLink,
                'observedRel' => $observedRel,
                'noindex' => $noindex,
            ];
        } catch (Throwable) {
            return [
                'status' => null,
                'hasTargetLink' => false,
                'observedRel' => null,
                'noindex' => false,
            ];
        }
    }

    /**
     * Import backlinks from DataForSEO into the project's monitored watchlist.
     */
    public function importFromDataForSeo(Project $project, int $limit = 100): int
    {
        $target = $this->resolveTarget($project);

        try {
            $response = $this->dataForSeo->backlinksBacklinks($target, $project, $limit);
            $items = $response['tasks'][0]['result'][0]['items'] ?? [];
            if (! is_array($items) || $items === []) {
                return 0;
            }

            $imported = 0;
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $sourceUrl = (string) ($item['url_from'] ?? '');
                $targetUrl = (string) ($item['url_to'] ?? $project->canonical_url);
                $anchor = isset($item['anchor']) ? (string) $item['anchor'] : null;
                $dofollow = (bool) ($item['dofollow'] ?? true);
                $expectedRel = $dofollow ? 'dofollow' : 'nofollow';

                if ($sourceUrl === '' || ! filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $hash = hash('sha256', $sourceUrl);

                MonitoredBacklink::query()->updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'source_url_hash' => $hash,
                    ],
                    [
                        'source_url' => $sourceUrl,
                        'target_url' => $targetUrl,
                        'expected_anchor' => $anchor,
                        'expected_rel' => $expectedRel,
                        'state' => 'active',
                    ]
                );

                $imported++;
            }

            return $imported;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Import monitored backlinks from CSV string.
     */
    public function importFromCsv(Project $project, string $csvContent): int
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($csvContent));
        if (! is_array($lines) || count($lines) <= 1) {
            return 0;
        }

        $header = str_getcsv(array_shift($lines) ?: '');
        $headerMap = [];
        foreach ($header as $idx => $col) {
            $headerMap[strtolower(trim((string) $col))] = $idx;
        }

        $sourceIdx = $headerMap['source_url'] ?? $headerMap['source'] ?? $headerMap['url'] ?? 0;
        $targetIdx = $headerMap['target_url'] ?? $headerMap['target'] ?? 1;
        $anchorIdx = $headerMap['expected_anchor'] ?? $headerMap['anchor'] ?? 2;
        $relIdx = $headerMap['expected_rel'] ?? $headerMap['rel'] ?? 3;

        $imported = 0;
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            $sourceUrl = trim((string) ($row[$sourceIdx] ?? ''));
            if ($sourceUrl === '' || ! filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
                continue;
            }

            $targetUrl = trim((string) ($row[$targetIdx] ?? $project->canonical_url));
            if ($targetUrl === '' || ! filter_var($targetUrl, FILTER_VALIDATE_URL)) {
                $targetUrl = (string) $project->canonical_url;
            }

            $anchor = isset($row[$anchorIdx]) && trim((string) $row[$anchorIdx]) !== '' ? trim((string) $row[$anchorIdx]) : null;
            $rel = isset($row[$relIdx]) && trim((string) $row[$relIdx]) !== '' ? strtolower(trim((string) $row[$relIdx])) : 'dofollow';

            $hash = hash('sha256', $sourceUrl);

            MonitoredBacklink::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'source_url_hash' => $hash,
                ],
                [
                    'source_url' => $sourceUrl,
                    'target_url' => $targetUrl,
                    'expected_anchor' => $anchor,
                    'expected_rel' => $rel,
                    'state' => 'active',
                ]
            );

            $imported++;
        }

        return $imported;
    }

    /**
     * Add a single monitored link to the project watchlist.
     */
    public function addSingleLink(
        Project $project,
        string $sourceUrl,
        string $targetUrl,
        ?string $expectedAnchor = null,
        ?string $expectedRel = 'dofollow',
    ): MonitoredBacklink {
        $hash = hash('sha256', $sourceUrl);

        /** @var MonitoredBacklink $link */
        $link = MonitoredBacklink::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'source_url_hash' => $hash,
            ],
            [
                'source_url' => $sourceUrl,
                'target_url' => $targetUrl,
                'expected_anchor' => $expectedAnchor,
                'expected_rel' => $expectedRel ?: 'dofollow',
                'state' => 'active',
            ]
        );

        return $link;
    }

    private function triggerAlertAndLogActivity(
        Project $project,
        MonitoredBacklink $link,
        string $previousState,
        string $newState,
        ?int $status,
        ?string $observedRel,
    ): void {
        $severity = $newState === 'lost' ? 'critical' : 'warning';
        $message = sprintf(
            'Monitored backlink on %s changed state from %s to %s (target: %s).',
            $link->source_url,
            $previousState,
            $newState,
            $link->target_url
        );

        BacklinkAlertTriggered::dispatch(
            $project->id,
            'link_rot_'.$newState,
            $severity,
            $message,
            $link->source_url,
            [
                'source_url' => $link->source_url,
                'target_url' => $link->target_url,
                'previous_state' => $previousState,
                'new_state' => $newState,
                'http_status' => $status,
                'observed_rel' => $observedRel,
            ]
        );

        ProjectActivity::log(
            project: $project,
            eventType: 'backlink.state_changed',
            title: 'Backlink state changed',
            description: $message,
            properties: [
                'source_url' => $link->source_url,
                'target_url' => $link->target_url,
                'previous_state' => $previousState,
                'new_state' => $newState,
                'http_status' => $status,
            ]
        );
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
