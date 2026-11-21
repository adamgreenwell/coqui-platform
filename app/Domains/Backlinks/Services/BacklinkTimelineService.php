<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Services;

use App\Domains\Backlinks\Events\BacklinkAlertTriggered;
use App\Domains\Backlinks\Models\BacklinkAlert;
use App\Domains\Backlinks\Models\BacklinkHistorySnapshot;
use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use Carbon\Carbon;
use Throwable;

class BacklinkTimelineService
{
    public function __construct(
        private readonly DataForSeoClient $dataForSeo,
        private readonly BacklinkProfileService $profileService,
    ) {}

    /**
     * The backlink timeline for a project from stored daily snapshots, with
     * the stored alerts. Fetches history only when none has been checked
     * recently, so repeat views make no paid calls and send no alerts.
     *
     * @return array{
     *     timeline: list<array<string, mixed>>,
     *     new_domains: list<array<string, mixed>>,
     *     lost_domains: list<array<string, mixed>>,
     *     velocity: array<string, mixed>,
     *     alerts: list<array<string, mixed>>,
     * }
     */
    public function getTimelineData(Project $project, int $days = 90, bool $fresh = false, bool $allowFetch = true): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $snapshots = $project->backlinkHistorySnapshots()
            ->where('snapshot_date', '>=', $startDate)
            ->orderBy('snapshot_date', 'asc')
            ->get();

        // Fetch only on the first view (or an explicit refresh). An empty
        // result is remembered too, so a project with no history isn't
        // fetched again on every page load.
        if ($allowFetch && ($fresh || ($snapshots->isEmpty() && ! $this->historyCheckedRecently($project)))) {
            $this->syncHistorySnapshots($project, $days);

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

        // Example new and lost domains, from the cached referring domains.
        $domainsBreakdown = $this->extractNewAndLostDomains($project, $days);

        // Totals come from the full daily new/lost series, not the sample.
        $totalNew = (int) $snapshots->sum('new_referring_domains');
        $totalLost = (int) $snapshots->sum('lost_referring_domains');
        $net = $totalNew - $totalLost;

        return [
            'timeline' => $timeline,
            'new_domains' => $domainsBreakdown['new_domains'],
            'lost_domains' => $domainsBreakdown['lost_domains'],
            'velocity' => [
                'days' => $days,
                'total_new' => $totalNew,
                'total_lost' => $totalLost,
                'net_velocity' => $net,
                'trend' => $net > 0 ? 'growing' : ($net < 0 ? 'declining' : 'stable'),
            ],
            // Alerts are evaluated on a schedule; viewing only reads them.
            'alerts' => BacklinkAlert::query()
                ->where('project_id', $project->id)
                ->where('detected_at', '>=', now()->subDays($days))
                ->latest('detected_at')
                ->limit(20)
                ->get()
                ->map(fn (BacklinkAlert $a): array => $a->toPayload())
                ->values()
                ->all(),
        ];
    }

    public function historyCheckedRecently(Project $project): bool
    {
        $days = max(1, (int) config('coqui.backlinks.history_recheck_days', 7));

        return $project->backlink_history_checked_at !== null
            && $project->backlink_history_checked_at->isAfter(now()->subDays($days));
    }

    /**
     * Fetch the daily backlink timeseries (totals plus new and lost) and
     * store one snapshot per day. Records the attempt on the project, so an
     * empty or failed result isn't fetched again on the next page view.
     */
    public function syncHistorySnapshots(Project $project, int $days = 90, bool $isScheduled = false): int
    {
        $target = $this->resolveTarget($project);
        $dateFrom = now()->subDays($days)->toDateString();
        $dateTo = now()->toDateString();

        try {
            $totals = $this->itemsByDate($this->dataForSeo->backlinksTimeseriesSummary($target, $project, $dateFrom, $dateTo, $isScheduled));
            $changes = $this->itemsByDate($this->dataForSeo->backlinksTimeseriesNewLost($target, $project, $dateFrom, $dateTo, $isScheduled));
        } catch (Throwable $e) {
            report($e);

            return 0;
        } finally {
            $project->forceFill(['backlink_history_checked_at' => now()])->save();
        }

        $count = 0;
        foreach (array_unique(array_merge(array_keys($totals), array_keys($changes))) as $date) {
            $total = $totals[$date] ?? [];
            $change = $changes[$date] ?? [];

            BacklinkHistorySnapshot::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'snapshot_date' => $date,
                ],
                [
                    'backlinks_count' => (int) ($total['backlinks'] ?? 0),
                    'referring_domains_count' => (int) ($total['referring_domains'] ?? 0),
                    'new_backlinks' => (int) ($change['new_backlinks'] ?? 0),
                    'lost_backlinks' => (int) ($change['lost_backlinks'] ?? 0),
                    'new_referring_domains' => (int) ($change['new_referring_domains'] ?? 0),
                    'lost_referring_domains' => (int) ($change['lost_referring_domains'] ?? 0),
                    'rank' => (int) ($total['rank'] ?? 0),
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array<string, mixed>> items keyed by Y-m-d
     */
    private function itemsByDate(array $response): array
    {
        $items = $response['tasks'][0]['result'][0]['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $byDate = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            // e.g. "2021-12-31 00:00:00 +00:00": the day is the leading date.
            $raw = trim((string) ($item['date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m) !== 1) {
                continue;
            }
            $byDate[$m[0]] = $item;
        }

        return $byDate;
    }

    /**
     * Example new and lost referring domains, from the cached referring
     * domains only (never a paid call).
     *
     * @return array{
     *     new_domains: list<array<string, mixed>>,
     *     lost_domains: list<array<string, mixed>>,
     * }
     */
    private function extractNewAndLostDomains(Project $project, int $days): array
    {
        $cutoff = now()->subDays($days);

        $new = [];
        $lost = [];

        foreach ($this->profileService->cachedReferringDomains($project) as $d) {
            $firstSeen = $this->seenAt($d['first_seen'] ?? null);
            $lastSeen = $this->seenAt($d['last_seen'] ?? null);

            if ($firstSeen !== null && $firstSeen->isAfter($cutoff)) {
                $new[] = array_merge($d, [
                    'event_type' => 'new',
                    'event_date' => $firstSeen->toIso8601String(),
                ]);
            }

            // Lost when last seen before the window.
            if ($lastSeen !== null && $lastSeen->isBefore($cutoff)) {
                $lost[] = array_merge($d, [
                    'event_type' => 'lost',
                    'event_date' => $lastSeen->toIso8601String(),
                ]);
            }
        }

        return [
            'new_domains' => $new,
            'lost_domains' => $lost,
        ];
    }

    /**
     * Check the stored history and the cached referring domains for a
     * sudden drop or a spam spike. Each event is stored and broadcast once;
     * seeing it again changes nothing. Makes no paid calls.
     *
     * @return list<BacklinkAlert> alerts created by this run
     */
    public function evaluateAlerts(Project $project): array
    {
        $created = [];

        // Every day a refresh may have brought in since the last one, not
        // just the newest; the date in the key keeps each drop to one alert.
        $recheckDays = max(1, (int) config('coqui.backlinks.history_recheck_days', 7));
        $recent = $project->backlinkHistorySnapshots()
            ->where('snapshot_date', '>=', now()->subDays($recheckDays + 1)->toDateString())
            ->orderBy('snapshot_date')
            ->get();
        foreach ($recent as $snapshot) {
            $lost = $snapshot->lost_backlinks;
            $total = $snapshot->backlinks_count;
            if ($lost >= 25 && $total > 0 && ($lost / $total) >= 0.15) {
                $date = $snapshot->snapshot_date->toDateString();
                $created[] = $this->recordAlert(
                    $project,
                    'backlink_drop',
                    'warning',
                    'drop:'.$date,
                    sprintf('Sudden backlink drop detected for %s: lost %d links on %s.', $project->domain, $lost, $date),
                    ['lost_backlinks' => $lost, 'total_backlinks' => $total, 'drop_date' => $date],
                );
            }
        }

        $cutoff = now()->subDays(max(1, (int) config('coqui.backlinks.spam_window_days', 30)));
        $spammy = [];
        foreach ($this->profileService->cachedReferringDomains($project) as $d) {
            $firstSeen = $this->seenAt($d['first_seen'] ?? null);
            $domain = strtolower(trim((string) ($d['domain'] ?? '')));
            if ($domain !== '' && $firstSeen !== null && $firstSeen->isAfter($cutoff) && (int) ($d['spam_score'] ?? 0) >= 60) {
                $spammy[$domain] = true;
            }
        }
        $spammy = array_keys($spammy);
        sort($spammy);

        if (count($spammy) >= 3) {
            $created[] = $this->recordAlert(
                $project,
                'spam_spike',
                'critical',
                // A new alert only when the set of spammy domains changes.
                'spam:'.sha1(implode(',', $spammy)),
                sprintf('Toxic link spam alert for %s: %d newly acquired domain(s) have a spam score over 60%%.', $project->domain, count($spammy)),
                ['high_spam_domains_count' => count($spammy), 'domains' => array_slice($spammy, 0, 20)],
            );
        }

        return array_values(array_filter($created));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function recordAlert(Project $project, string $type, string $severity, string $dedupeKey, string $message, array $meta): ?BacklinkAlert
    {
        $inserted = BacklinkAlert::query()->insertOrIgnore([
            'project_id' => $project->id,
            'type' => $type,
            'severity' => $severity,
            'dedupe_key' => $dedupeKey,
            'message' => $message,
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        rescue(static fn () => BacklinkAlertTriggered::dispatch(
            $project->id,
            $type,
            $severity,
            $message,
            $project->canonical_url,
            $meta,
        ));

        return BacklinkAlert::query()
            ->where('project_id', $project->id)
            ->where('type', $type)
            ->where('dedupe_key', $dedupeKey)
            ->first();
    }

    private function seenAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
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
