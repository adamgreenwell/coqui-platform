<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Backlinks\Services\BacklinkVerificationService;
use App\Domains\Billing\PlanFeature;
use App\Domains\Projects\Models\Project;
use Illuminate\Console\Command;

class VerifyBacklinksCommand extends Command
{
    protected $signature = 'coqui:verify-backlinks {--project= : Optional project ID}';

    protected $description = 'Verify starred customer backlinks using coqui-worker.';

    public function handle(BacklinkVerificationService $verifier): int
    {
        $query = Project::query()->with('workspace')->whereHas('monitoredBacklinks');

        if ($this->option('project') !== null) {
            $query->whereKey($this->option('project'));
        }

        $total = 0;
        foreach ($query->cursor() as $project) {
            // Link-rot monitoring is part of the backlink intelligence plan feature.
            if ($project->workspace?->hasFeature(PlanFeature::BacklinkIntelligence) !== true) {
                continue;
            }

            $total += $verifier->verifyProjectWatchlist($project);
        }

        $this->info("Verified {$total} monitored backlinks.");

        return self::SUCCESS;
    }
}
