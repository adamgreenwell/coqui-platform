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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BacklinkVerificationService
{
    /** Most URLs per worker request (the worker's own limit). */
    public const WORKER_BATCH = 200;

    public function __construct(
        private readonly CoquiWorkerClient $worker,
        private readonly DataForSeoClient $dataForSeo,
        private readonly SafeHttpClient $http = new SafeHttpClient,
    ) {}

    /**
     * Verify all monitored backlinks for a project, tracking status, rel,
     * anchor and noindex changes. Runs to completion in this process; the
     * scheduled run and "Verify all" use VerifyMonitoredBacklinksJob instead.
     */
    public function verifyProjectWatchlist(Project $project): int
    {
        $verified = 0;
        $project->monitoredBacklinks()->chunkById(self::WORKER_BATCH, function (Collection $links) use ($project, &$verified): void {
            $this->verifyLinks($project, $links);
            $verified += $links->count();
        });

        return $verified;
    }

    /**
     * Verify a single monitored backlink and update its state.
     */
    public function verifySingleLink(MonitoredBacklink $link): string
    {
        $this->verifyLinks($link->project, new Collection([$link]));

        return $link->state;
    }

    /**
     * Verify a batch of links: the worker first, then a direct check, paced
     * per host, of any link the worker couldn't settle. With a deadline (unix
     * time) direct checks stop there, after at least one, and the links not
     * reached are returned for a later run.
     *
     * @param  Collection<int, MonitoredBacklink>  $links
     * @return Collection<int, MonitoredBacklink> links not verified before the deadline
     */
    public function verifyLinks(Project $project, Collection $links, ?float $deadline = null): Collection
    {
        $observations = $this->observeWithWorker($project, $links);

        $pending = [];
        foreach ($links as $link) {
            $obs = $observations[$link->source_url] ?? null;
            if ($obs === null) {
                $pending[] = $link;

                continue;
            }

            $this->applyObservation($project, $link, $obs);
        }

        /** @var Collection<int, MonitoredBacklink> $unverified */
        $unverified = new Collection;
        $checked = 0;
        foreach (self::interleaveByHost($pending) as $link) {
            if ($deadline !== null && $checked > 0 && microtime(true) >= $deadline) {
                $unverified->push($link);

                continue;
            }

            $this->paceHost($link->source_url);
            $this->applyObservation($project, $link, $this->inspectDirectly($link->source_url, $link->target_url));
            $checked++;
        }

        return $unverified;
    }

    /**
     * Whether an observed anchor differs from the expected one, ignoring case
     * and spacing. Unknown on either side isn't a change.
     */
    public static function anchorChanged(?string $expected, ?string $observed): bool
    {
        if ($expected === null || $observed === null) {
            return false;
        }

        $normalize = static fn (string $text): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        $want = $normalize($expected);

        return $want !== '' && $want !== $normalize($observed);
    }

    public static function maxMonitoredLinks(): int
    {
        return max(1, (int) config('coqui.backlinks.max_monitored_links', 500));
    }

    /**
     * Whether adding this source URL would take the project past its link
     * cap. A URL that's already monitored never does.
     */
    public function wouldExceedCap(Project $project, string $sourceUrl): bool
    {
        if ($project->monitoredBacklinks()->where('source_url_hash', hash('sha256', $sourceUrl))->exists()) {
            return false;
        }

        return $project->monitoredBacklinks()->count() >= self::maxMonitoredLinks();
    }

    /**
     * Ask the worker about each link, in batches it accepts. A failed batch
     * leaves its links to the direct check; a URL the SSRF guard refuses is
     * recorded as unreachable, since a direct fetch would be refused too.
     *
     * @param  Collection<int, MonitoredBacklink>  $links
     * @return array<string, array<string, mixed>>
     */
    private function observeWithWorker(Project $project, Collection $links): array
    {
        if (! $this->worker->isConfigured()) {
            return [];
        }

        $observations = [];
        foreach ($links->chunk(self::WORKER_BATCH) as $chunk) {
            try {
                $result = $this->worker->inspectUrls(
                    $chunk->pluck('source_url')->values()->all(),
                    $project->domain,
                    $chunk->mapWithKeys(fn (MonitoredBacklink $link): array => [$link->source_url => $link->target_url])->all(),
                );
            } catch (Throwable) {
                continue;
            }

            foreach (($result['results'] ?? []) as $item) {
                // An observation with an error (unreachable, or a page too
                // large to check in full) isn't conclusive: inspect directly.
                if (is_array($item) && isset($item['url']) && ! isset($item['error'])) {
                    $observations[(string) $item['url']] = $item;
                }
            }

            foreach (($result['blocked'] ?? []) as $url) {
                $observations[(string) $url] = [
                    'status' => null,
                    'hasTargetLink' => false,
                    'observedRel' => null,
                    'observedAnchor' => null,
                    'noindex' => false,
                ];
            }
        }

        return $observations;
    }

    /**
     * @param  array<string, mixed>  $obs
     */
    private function applyObservation(Project $project, MonitoredBacklink $link, array $obs): void
    {
        $status = isset($obs['status']) ? (int) $obs['status'] : null;
        $hasTargetLink = (bool) ($obs['hasTargetLink'] ?? false);
        $observedRel = isset($obs['observedRel']) ? (string) $obs['observedRel'] : null;
        $observedAnchor = $hasTargetLink && isset($obs['observedAnchor']) ? (string) $obs['observedAnchor'] : null;
        $noindex = (bool) ($obs['noindex'] ?? false);

        $state = match (true) {
            $status === null || $status >= 400 => 'error',
            ! $hasTargetLink || $noindex => 'lost',
            $link->expected_rel !== null && $observedRel !== $link->expected_rel => 'rel_changed',
            self::anchorChanged($link->expected_anchor, $observedAnchor) => 'anchor_changed',
            default => 'active',
        };

        $previousState = $link->state;

        $link->update([
            'state' => $state,
            'last_http_status' => $status,
            'last_observed_rel' => $observedRel,
            'last_observed_anchor' => $observedAnchor,
            'last_page_noindex' => $noindex,
            'last_verified_at' => now(),
        ]);

        if ($previousState !== $state) {
            $this->triggerAlertAndLogActivity($project, $link, $previousState, $state, $status, $observedRel, $observedAnchor);
        }
    }

    /**
     * Order links so pages on the same host are spread out (a, b, c, a, b, ...).
     *
     * @param  list<MonitoredBacklink>  $links
     * @return list<MonitoredBacklink>
     */
    private static function interleaveByHost(array $links): array
    {
        $byHost = [];
        foreach ($links as $link) {
            $byHost[strtolower((string) parse_url($link->source_url, PHP_URL_HOST))][] = $link;
        }

        $ordered = [];
        $queues = array_values($byHost);
        for ($round = 0; $queues !== []; $round++) {
            foreach ($queues as $i => $queue) {
                if (isset($queue[$round])) {
                    $ordered[] = $queue[$round];
                } else {
                    unset($queues[$i]);
                }
            }
        }

        return $ordered;
    }

    /**
     * Wait until the configured gap since the last direct check of this host
     * has passed. Shared through the cache, so it holds across runs.
     */
    private function paceHost(string $url): void
    {
        $delayMs = (int) config('coqui.backlinks.verify_host_delay_ms', 1000);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($delayMs <= 0 || $host === '') {
            return;
        }

        $key = 'backlink-verify:host:'.md5($host);
        $gap = $delayMs / 1000;

        // Reserve this request's slot under a lock so parallel runs hitting
        // the same host queue up behind each other instead of reading the
        // same timestamp. The wait happens after the lock is released.
        $slot = Cache::lock($key.':lock', 10)->block(10, function () use ($key, $gap): float {
            $now = microtime(true);
            $last = Cache::get($key);
            $slot = is_numeric($last) ? max($now, (float) $last + $gap) : $now;
            Cache::put($key, $slot, now()->addMinutes(5));

            return $slot;
        });

        $wait = $slot - microtime(true);
        if ($wait > 0) {
            usleep((int) ceil($wait * 1_000_000));
        }
    }

    /**
     * Inspect a source URL directly using HTTP client and HTML parsing.
     *
     * @return array{status: int|null, hasTargetLink: bool, observedRel: string|null, observedAnchor: string|null, noindex: bool}
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
                    'observedAnchor' => null,
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
                    'observedAnchor' => null,
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
            $observedAnchor = null;

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
                        $observedAnchor = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent)), 0, 255);
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
                'observedAnchor' => $observedAnchor,
                'noindex' => $noindex,
            ];
        } catch (Throwable) {
            return [
                'status' => null,
                'hasTargetLink' => false,
                'observedRel' => null,
                'observedAnchor' => null,
                'noindex' => false,
            ];
        }
    }

    /**
     * Import backlinks from DataForSEO into the project's monitored watchlist.
     * New links past the per-project cap are skipped.
     *
     * @return array{imported: int, skipped: int}
     */
    public function importFromDataForSeo(Project $project, int $limit = 100): array
    {
        $target = $this->resolveTarget($project);
        $count = $project->monitoredBacklinks()->count();
        $cap = self::maxMonitoredLinks();
        $skipped = 0;

        try {
            $response = $this->dataForSeo->backlinksBacklinks($target, $project, $limit);
            $items = $response['tasks'][0]['result'][0]['items'] ?? [];
            if (! is_array($items) || $items === []) {
                return ['imported' => 0, 'skipped' => 0];
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
                $isNew = ! $project->monitoredBacklinks()->where('source_url_hash', $hash)->exists();
                if ($isNew && $count >= $cap) {
                    $skipped++;

                    continue;
                }

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

                $count += $isNew ? 1 : 0;
                $imported++;
            }

            return ['imported' => $imported, 'skipped' => $skipped];
        } catch (Throwable) {
            return ['imported' => 0, 'skipped' => $skipped];
        }
    }

    /**
     * Import monitored backlinks from CSV string. New links past the
     * per-project cap are skipped.
     *
     * @return array{imported: int, skipped: int}
     */
    public function importFromCsv(Project $project, string $csvContent): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($csvContent));
        if (! is_array($lines) || count($lines) <= 1) {
            return ['imported' => 0, 'skipped' => 0];
        }

        $count = $project->monitoredBacklinks()->count();
        $cap = self::maxMonitoredLinks();
        $skipped = 0;

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
            $isNew = ! $project->monitoredBacklinks()->where('source_url_hash', $hash)->exists();
            if ($isNew && $count >= $cap) {
                $skipped++;

                continue;
            }

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

            $count += $isNew ? 1 : 0;
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
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
        ?string $observedAnchor = null,
    ): void {
        $severity = $newState === 'lost' ? 'critical' : 'warning';
        $message = sprintf(
            'Monitored backlink on %s changed state from %s to %s (target: %s).',
            $link->source_url,
            $previousState,
            $newState,
            $link->target_url
        );

        // Broadcast immediately, but a broadcast failure (Reverb down) is
        // reported and skipped rather than stopping the verification run.
        rescue(static fn () => BacklinkAlertTriggered::dispatch(
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
                'expected_anchor' => $link->expected_anchor,
                'observed_anchor' => $observedAnchor,
            ]
        ));

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
