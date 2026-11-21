<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Services;

use App\Domains\Backlinks\Events\BacklinkAlertTriggered;
use App\Domains\Backlinks\Models\BacklinkHistorySnapshot;
use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Domains\Serp\SpendRefusedException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BacklinkTimelineService
{
    public const HISTORY_ENDPOINT = '/v3/backlinks/history/live';

    /** How long a failed history fetch is skipped on ordinary page loads. */
    private const FAILURE_TTL_MINUTES = 15;

    public function __construct(
        private readonly DataForSeoClient $dataForSeo,
        private readonly BacklinkProfileService $profileService,
    ) {}

    /**
     * Retrieve the backlink timeseries velocity for a project, caching snapshots in PostgreSQL
     * to avoid re-querying DataForSEO on subsequent page loads.
     *
     * @return array{
     *     timeline: list<array<string, mixed>>,
     *     new_domains: list<array<string, mixed>>,
     *     lost_domains: list<array<string, mixed>>,
     *     velocity: array<string, mixed>,
     *     alerts: list<array<string, mixed>>,
     * }
     */
    public function getTimelineData(Project $project, int $days = 90, bool $fresh = false): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $snapshots = $project->backlinkHistorySnapshots()
            ->where('snapshot_date', '>=', $startDate)
            ->orderBy('snapshot_date', 'asc')
            ->get();

        if ($snapshots->isEmpty() || $fresh) {
            $this->syncHistorySnapshots($project, $days, force: $fresh);

            $snapshots = $project->backlinkHistorySnapshots()
                ->where('snapshot_date', '>=', $startDate)
                ->orderBy('snapshot_date', 'asc')
                ->get();
        }

        $timeline = $snapshots->map(fn (BacklinkHistorySnapshot $s): array => [
            'date' => $s->snapshot_date->toDateString(),
            'backlinks' => $s->backlinks_count,
            'referring_domains' => $s->referring_domains_count,
            'new_backlinks' => $s->new_backlinks,
            'lost_backlinks' => $s->lost_backlinks,
            'new_referring_domains' => $s->new_referring_domains,
            'lost_referring_domains' => $s->lost_referring_domains,
            'net_domains_delta' => $s->new_referring_domains - $s->lost_referring_domains,
            'net_backlinks_delta' => $s->new_backlinks - $s->lost_backlinks,
            'rank' => $s->rank,
        ])->all();

        // New vs Lost referring domains breakdown
        $domainsBreakdown = $this->extractNewAndLostDomains($project, $days);

        // Detect sudden backlink drops or spam spikes
        $alerts = $this->detectAndTriggerAlerts($project, $timeline, $domainsBreakdown);

        return [
            'timeline' => $timeline,
            'new_domains' => $domainsBreakdown['new_domains'],
            'lost_domains' => $domainsBreakdown['lost_domains'],
            'velocity' => $domainsBreakdown['velocity'],
            'alerts' => $alerts,
        ];
    }

    /**
     * Fetch historical backlink timeseries and persist it. Unless forced, a
     * fetch that failed in the last few minutes isn't retried (and re-billed).
     */
    public function syncHistorySnapshots(Project $project, int $days = 90, bool $force = true): int
    {
        $target = $this->resolveTarget($project);
        $dateFrom = now()->subDays($days)->toDateString();
        $dateTo = now()->toDateString();
        $failureKey = 'backlinks:failed:'.md5(self::HISTORY_ENDPOINT.'|'.$project->id.'|'.$target.'|'.$days);

        if (! $force && Cache::has($failureKey)) {
            return 0;
        }

        try {
            $response = $this->dataForSeo->backlinksHistory($target, $project, $dateFrom, $dateTo);
            $items = $response['tasks'][0]['result'][0]['items'] ?? [];
            if (! is_array($items)) {
                return 0;
            }

            $count = 0;
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $rawDate = (string) ($item['date'] ?? '');
                if ($rawDate === '') {
                    continue;
                }

                $snapshotDate = Carbon::parse($rawDate)->toDateString();
                $backlinks = (int) ($item['backlinks'] ?? 0);
                $domains = (int) ($item['referring_domains'] ?? 0);
                $newBacklinks = (int) ($item['new_backlinks'] ?? 0);
                $lostBacklinks = (int) ($item['lost_backlinks'] ?? 0);
                $newDomains = (int) ($item['new_referring_domains'] ?? 0);
                $lostDomains = (int) ($item['lost_referring_domains'] ?? 0);
                $rank = (int) ($item['rank'] ?? 0);

                BacklinkHistorySnapshot::query()->updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'snapshot_date' => $snapshotDate,
                    ],
                    [
                        'backlinks_count' => $backlinks,
                        'referring_domains_count' => $domains,
                        'new_backlinks' => $newBacklinks,
                        'lost_backlinks' => $lostBacklinks,
                        'new_referring_domains' => $newDomains,
                        'lost_referring_domains' => $lostDomains,
                        'rank' => $rank,
                    ]
                );

                $count++;
            }

            return $count;
        } catch (Throwable $e) {
            // Spend refusals and missing configuration aren't provider failures.
            if (! $e instanceof SpendRefusedException && ! $e instanceof ProviderNotConfiguredException) {
                Cache::put($failureKey, true, now()->addMinutes(self::FAILURE_TTL_MINUTES));
            }

            return 0;
        }
    }

    /**
     * @return array{
     *     new_domains: list<array<string, mixed>>,
     *     lost_domains: list<array<string, mixed>>,
     *     velocity: array<string, mixed>
     * }
     */
    private function extractNewAndLostDomains(Project $project, int $days): array
    {
        $profile = $this->profileService->getBacklinkProfile($project, false);
        $allDomains = $profile['referring_domains'];

        $cutoff = now()->subDays($days);

        $new = [];
        $lost = [];

        foreach ($allDomains as $d) {
            $firstSeen = isset($d['first_seen']) ? Carbon::parse((string) $d['first_seen']) : null;
            $lastSeen = isset($d['last_seen']) ? Carbon::parse((string) $d['last_seen']) : null;

            if ($firstSeen !== null && $firstSeen->isAfter($cutoff)) {
                $new[] = array_merge($d, [
                    'event_type' => 'new',
                    'event_date' => $firstSeen->toIso8601String(),
                ]);
            }

            // A domain is considered "lost" if last seen is before the recent window or marked as inactive
            if ($lastSeen !== null && $lastSeen->isBefore($cutoff)) {
                $lost[] = array_merge($d, [
                    'event_type' => 'lost',
                    'event_date' => $lastSeen->toIso8601String(),
                ]);
            }
        }

        $totalNew = count($new);
        $totalLost = count($lost);
        $netVelocity = $totalNew - $totalLost;

        return [
            'new_domains' => $new,
            'lost_domains' => $lost,
            'velocity' => [
                'days' => $days,
                'total_new' => $totalNew,
                'total_lost' => $totalLost,
                'net_velocity' => $netVelocity,
                'trend' => $netVelocity > 0 ? 'growing' : ($netVelocity < 0 ? 'declining' : 'stable'),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $timeline
     * @param  array{new_domains: list<array<string, mixed>>, lost_domains: list<array<string, mixed>>, velocity: array<string, mixed>}  $domainsBreakdown
     * @return list<array<string, mixed>>
     */
    private function detectAndTriggerAlerts(Project $project, array $timeline, array $domainsBreakdown): array
    {
        $alerts = [];

        // 1. Check for sudden backlink drops in latest timeline snapshot
        if ($timeline !== []) {
            $latest = end($timeline);
            $lostBacklinks = (int) ($latest['lost_backlinks'] ?? 0);
            $totalBacklinks = (int) ($latest['backlinks'] ?? 0);

            if ($lostBacklinks >= 25 && $totalBacklinks > 0 && ($lostBacklinks / $totalBacklinks) >= 0.15) {
                $message = sprintf(
                    'Sudden backlink drop detected for %s: lost %d links on %s.',
                    $project->domain,
                    $lostBacklinks,
                    $latest['date'] ?? 'recently'
                );

                $alerts[] = [
                    'type' => 'backlink_drop',
                    'severity' => 'warning',
                    'message' => $message,
                    'detected_at' => now()->toIso8601String(),
                    'meta' => [
                        'lost_backlinks' => $lostBacklinks,
                        'total_backlinks' => $totalBacklinks,
                        'drop_date' => $latest['date'] ?? null,
                    ],
                ];

                // A broadcast failure mustn't break the page that found the drop.
                rescue(static fn () => BacklinkAlertTriggered::dispatch(
                    $project->id,
                    'backlink_drop',
                    'warning',
                    $message,
                    $project->canonical_url,
                    [
                        'lost_backlinks' => $lostBacklinks,
                        'total_backlinks' => $totalBacklinks,
                    ]
                ));
            }
        }

        // 2. Check for spam spikes in new referring domains
        $newDomains = $domainsBreakdown['new_domains'];
        $highSpamCount = 0;
        foreach ($newDomains as $nd) {
            if ((int) ($nd['spam_score'] ?? 0) >= 60) {
                $highSpamCount++;
            }
        }

        if ($highSpamCount >= 3) {
            $message = sprintf(
                'Toxic link spam alert for %s: %d newly acquired domain(s) have a spam score over 60%%.',
                $project->domain,
                $highSpamCount
            );

            $alerts[] = [
                'type' => 'spam_spike',
                'severity' => 'critical',
                'message' => $message,
                'detected_at' => now()->toIso8601String(),
                'meta' => [
                    'high_spam_domains_count' => $highSpamCount,
                ],
            ];

            rescue(static fn () => BacklinkAlertTriggered::dispatch(
                $project->id,
                'spam_spike',
                'critical',
                $message,
                $project->canonical_url,
                ['high_spam_domains_count' => $highSpamCount]
            ));
        }

        return $alerts;
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
