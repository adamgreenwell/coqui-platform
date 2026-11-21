<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Backlinks\Services\BacklinkTimelineService;
use App\Domains\Billing\PlanFeature;
use App\Domains\Projects\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class EvaluateBacklinkAlertsCommand extends Command
{
    protected $signature = 'backlinks:evaluate-alerts {--project= : Optional project ID}';

    protected $description = 'Check stored backlink history for drops and spam spikes, recording each alert once.';

    public function handle(BacklinkTimelineService $timeline): int
    {
        $query = Project::query()
            ->with('workspace')
            // Projects whose backlinks someone has opened.
            ->where(fn (Builder $q) => $q->whereNotNull('backlink_history_checked_at')->orWhereHas('backlinkHistorySnapshots'));

        if ($this->option('project') !== null) {
            $query->whereKey($this->option('project'));
        }

        $scheduledSpend = (bool) config('coqui.dataforseo.scheduled_spend');
        $refreshed = 0;
        $created = 0;

        foreach ($query->cursor() as $project) {
            if ($project->workspace?->hasFeature(PlanFeature::BacklinkIntelligence) !== true) {
                continue;
            }

            if ($scheduledSpend && ! $timeline->historyCheckedRecently($project)) {
                $timeline->syncHistorySnapshots($project, 90, isScheduled: true);
                $refreshed++;
            }

            $created += count($timeline->evaluateAlerts($project));
        }

        $this->info("Refreshed {$refreshed} backlink histories; recorded {$created} new backlink alerts.");

        return self::SUCCESS;
    }
}
