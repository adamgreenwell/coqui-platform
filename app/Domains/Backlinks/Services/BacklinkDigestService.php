<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Services;

use App\Domains\Backlinks\Models\BacklinkAlert;
use App\Domains\Backlinks\Notifications\BacklinkDigestNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The weekly backlink digest: the week's alerts, new and lost referring
 * domains and backlinks, and watchlist link changes, sent by email to
 * subscribed members and to the project's Slack incoming webhook.
 */
class BacklinkDigestService
{
    /**
     * Only Slack incoming webhooks are accepted, so the URL can't be used to
     * make the server call anywhere else.
     */
    public static function isSlackWebhook(string $url): bool
    {
        return preg_match('#^https://hooks\.slack\.com/services/[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $url) === 1;
    }

    /**
     * @return array{
     *     project: array{name: string, domain: string},
     *     period_start: string,
     *     period_end: string,
     *     alerts: list<array<string, mixed>>,
     *     new_referring_domains: int,
     *     lost_referring_domains: int,
     *     new_backlinks: int,
     *     lost_backlinks: int,
     *     link_changes: list<array{description: string, new_state: string|null, at: string|null}>,
     *     link_changes_total: int,
     * }
     */
    public function build(Project $project, CarbonInterface $from, CarbonInterface $until): array
    {
        // Half-open [from, until): adjacent digests never share a day.
        $from = $from->copy()->startOfDay();
        $until = $until->copy()->startOfDay();

        $alerts = BacklinkAlert::query()
            ->where('project_id', $project->id)
            ->where('detected_at', '>=', $from)
            ->where('detected_at', '<', $until)
            ->latest('detected_at')
            ->limit(20)
            ->get()
            ->map(fn (BacklinkAlert $a): array => $a->toPayload())
            ->values()
            ->all();

        $snapshots = $project->backlinkHistorySnapshots()
            ->where('snapshot_date', '>=', $from->toDateString())
            ->where('snapshot_date', '<', $until->toDateString())
            ->get();

        $changes = ProjectActivity::query()
            ->where('project_id', $project->id)
            ->where('event_type', 'backlink.state_changed')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until);

        $linkChanges = (clone $changes)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ProjectActivity $a): array => [
                'description' => (string) ($a->description ?? $a->title),
                'new_state' => isset($a->properties['new_state']) ? (string) $a->properties['new_state'] : null,
                'at' => $a->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'project' => ['name' => $project->name, 'domain' => $project->domain],
            'period_start' => $from->toDateString(),
            'period_end' => $until->copy()->subDay()->toDateString(),
            'alerts' => $alerts,
            'new_referring_domains' => (int) $snapshots->sum('new_referring_domains'),
            'lost_referring_domains' => (int) $snapshots->sum('lost_referring_domains'),
            'new_backlinks' => (int) $snapshots->sum('new_backlinks'),
            'lost_backlinks' => (int) $snapshots->sum('lost_backlinks'),
            'link_changes' => $linkChanges,
            'link_changes_total' => $changes->count(),
        ];
    }

    /**
     * Send the digest for the seven full days before today and record that
     * it was sent.
     *
     * @return array{emailed: int, slack: bool}
     */
    public function send(Project $project): array
    {
        $today = now()->startOfDay();
        $digest = $this->build($project, $today->copy()->subDays(7), $today);

        // Only members who can still see the project.
        $recipients = $project->backlinkDigestSubscribers()
            ->get()
            ->filter(fn (User $user): bool => $user->can('view', $project))
            ->values();

        foreach ($recipients as $user) {
            $user->notify(new BacklinkDigestNotification($project, $digest));
        }

        $slack = false;
        $webhook = $project->backlink_digest_slack_webhook;
        if (is_string($webhook) && self::isSlackWebhook($webhook)) {
            try {
                $slack = Http::timeout(5)
                    ->connectTimeout(3)
                    ->withoutRedirecting()
                    ->post($webhook, ['text' => $this->slackText($project, $digest)])
                    ->successful();
            } catch (Throwable $e) {
                report($e);
            }
        }

        $project->forceFill(['backlink_digest_sent_at' => now()])->save();

        return ['emailed' => $recipients->count(), 'slack' => $slack];
    }

    /**
     * @param  array{project: array{name: string, domain: string}, period_start: string, period_end: string, alerts: list<array<string, mixed>>, new_referring_domains: int, lost_referring_domains: int, new_backlinks: int, lost_backlinks: int, link_changes: list<array{description: string, new_state: string|null, at: string|null}>, link_changes_total: int}  $digest
     */
    public function slackText(Project $project, array $digest): string
    {
        $lines = [
            sprintf('*Weekly backlink digest for %s* (%s to %s)', $this->slackEscape($project->domain), $digest['period_start'], $digest['period_end']),
            sprintf(
                'Referring domains: +%d new, -%d lost. Backlinks: +%d new, -%d lost.',
                $digest['new_referring_domains'],
                $digest['lost_referring_domains'],
                $digest['new_backlinks'],
                $digest['lost_backlinks'],
            ),
        ];

        if ($digest['alerts'] === []) {
            $lines[] = 'No alerts this week.';
        } else {
            $lines[] = sprintf('*Alerts (%d)*', count($digest['alerts']));
            foreach ($digest['alerts'] as $alert) {
                $lines[] = '- '.$this->slackEscape((string) ($alert['message'] ?? ''));
            }
        }

        if ($digest['link_changes_total'] > 0) {
            $lines[] = sprintf('*Watchlist link changes (%d)*', $digest['link_changes_total']);
            foreach (array_slice($digest['link_changes'], 0, 10) as $change) {
                $lines[] = '- '.$this->slackEscape($change['description']);
            }
        }

        return implode("\n", $lines);
    }

    private function slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
