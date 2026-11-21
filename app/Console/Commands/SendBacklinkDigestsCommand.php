<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Backlinks\Services\BacklinkDigestService;
use App\Domains\Backlinks\Services\BacklinkTimelineService;
use App\Domains\Billing\PlanFeature;
use App\Domains\Projects\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendBacklinkDigestsCommand extends Command
{
    protected $signature = 'backlinks:send-digests {--project= : Optional project ID} {--force : Send even if one went out in the last 6 days}';

    protected $description = 'Send the weekly backlink digest by email and Slack.';

    public function handle(BacklinkDigestService $digests, BacklinkTimelineService $timeline): int
    {
        $scheduledSpend = (bool) config('coqui.dataforseo.scheduled_spend');

        $query = Project::query()
            ->with('workspace')
            ->where(fn (Builder $q) => $q->whereHas('backlinkDigestSubscribers')->orWhereNotNull('backlink_digest_slack_webhook'));

        if ($this->option('project') !== null) {
            $query->whereKey($this->option('project'));
        }

        $sent = 0;
        foreach ($query->cursor() as $project) {
            if ($project->workspace?->hasFeature(PlanFeature::BacklinkIntelligence) !== true) {
                continue;
            }

            // A missed or repeated run never sends twice in one week.
            if (! $this->option('force')
                && $project->backlink_digest_sent_at !== null
                && $project->backlink_digest_sent_at->isAfter(now()->subDays(6))) {
                continue;
            }

            // Bring the history up to date first, so the week's totals are
            // complete and no day falls between two digests.
            if ($scheduledSpend && ! $this->checkedToday($project)) {
                $timeline->syncHistorySnapshots($project, 90, isScheduled: true);
                $timeline->evaluateAlerts($project);
            }

            $digests->send($project);
            $sent++;
        }

        $this->info("Sent {$sent} backlink digests.");

        return self::SUCCESS;
    }

    private function checkedToday(Project $project): bool
    {
        return $project->backlink_history_checked_at !== null
            && $project->backlink_history_checked_at->isAfter(now()->startOfDay());
    }
}
