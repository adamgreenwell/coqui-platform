<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Notifications;

use App\Domains\Projects\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BacklinkDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{project: array{name: string, domain: string}, period_start: string, period_end: string, alerts: list<array<string, mixed>>, new_referring_domains: int, lost_referring_domains: int, new_backlinks: int, lost_backlinks: int, link_changes: list<array{description: string, new_state: string|null, at: string|null}>, link_changes_total: int}  $digest
     */
    public function __construct(
        public Project $project,
        public array $digest,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->digest;
        $mail = (new MailMessage)
            ->subject(sprintf('Weekly backlink digest for %s', $d['project']['domain']))
            ->greeting(sprintf('Backlinks for %s', $d['project']['domain']))
            ->line(sprintf('%s to %s', $d['period_start'], $d['period_end']))
            ->line(sprintf(
                'Referring domains: %d new, %d lost. Backlinks: %d new, %d lost.',
                $d['new_referring_domains'],
                $d['lost_referring_domains'],
                $d['new_backlinks'],
                $d['lost_backlinks'],
            ));

        if ($d['alerts'] === []) {
            $mail->line('No alerts this week.');
        } else {
            $mail->line(sprintf('Alerts (%d):', count($d['alerts'])));
            foreach ($d['alerts'] as $alert) {
                $mail->line('- '.(string) ($alert['message'] ?? ''));
            }
        }

        if ($d['link_changes_total'] > 0) {
            $mail->line(sprintf('Watchlist link changes (%d):', $d['link_changes_total']));
            foreach (array_slice($d['link_changes'], 0, 10) as $change) {
                $mail->line('- '.$change['description']);
            }
        }

        $workspace = $this->project->workspace;
        if ($workspace !== null) {
            $mail->action('Open backlinks', route('workspaces.projects.backlinks.index', [$workspace, $this->project]));
        }

        return $mail->line('You get this because you subscribed to the weekly digest on the Backlinks page.');
    }
}
