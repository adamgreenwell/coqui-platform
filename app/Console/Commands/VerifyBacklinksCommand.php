<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Backlinks\Jobs\VerifyMonitoredBacklinksJob;
use App\Domains\Backlinks\Services\BacklinkVerificationService;
use App\Domains\Billing\PlanFeature;
use App\Domains\Projects\Models\Project;
use Illuminate\Console\Command;

class VerifyBacklinksCommand extends Command
{
    protected $signature = 'coqui:verify-backlinks
        {--project= : Optional project ID}
        {--sync : Verify in this process instead of queueing batched jobs}';

    protected $description = 'Verify starred customer backlinks using coqui-worker.';

    public function handle(BacklinkVerificationService $verifier): int
    {
        $query = Project::query()->with('workspace')->whereHas('monitoredBacklinks');

        if ($this->option('project') !== null) {
            $query->whereKey($this->option('project'));
        }

        $sync = (bool) $this->option('sync');
        $total = 0;
        $projects = 0;
        foreach ($query->cursor() as $project) {
            // Link-rot monitoring is part of the backlink intelligence plan feature.
            if ($project->workspace?->hasFeature(PlanFeature::BacklinkIntelligence) !== true) {
                continue;
            }

            // Queued by default: each project's links are checked in bounded,
            // host-paced batches on the backlinks queue.
            $total += $sync
                ? $verifier->verifyProjectWatchlist($project)
                : VerifyMonitoredBacklinksJob::dispatchForProject($project);
            $projects++;
        }

        $this->info($sync
            ? "Verified {$total} monitored backlinks."
            : "Queued verification of {$total} monitored backlinks across {$projects} projects.");

        return self::SUCCESS;
    }
}
