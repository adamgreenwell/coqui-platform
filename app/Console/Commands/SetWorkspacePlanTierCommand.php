<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Projects\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Operator-only plan override (comps, migrations, support fixes).
 *
 * Customers change plans through Stripe checkout and the customer portal;
 * there is deliberately no web route that changes a plan without payment.
 */
class SetWorkspacePlanTierCommand extends Command
{
    protected $signature = 'billing:set-tier
        {workspace : Workspace ID or slug}
        {tier : free, personal, pro or agency}
        {--runnr : Grant the Runnr hosting add-on}';

    protected $description = 'Manually override a workspace plan tier (operator use only; bypasses Stripe).';

    public function handle(): int
    {
        $tier = strtolower(trim((string) $this->argument('tier')));
        if (! in_array($tier, ['free', ...Workspace::PAID_PLAN_TIERS], true)) {
            $this->error("Unknown tier [{$tier}]. Use free, personal, pro or agency.");

            return self::FAILURE;
        }

        $identifier = (string) $this->argument('workspace');
        $workspace = Workspace::query()
            ->when(
                ctype_digit($identifier),
                fn ($query) => $query->whereKey((int) $identifier),
                fn ($query) => $query->where('slug', $identifier),
            )
            ->first();

        if (! $workspace instanceof Workspace) {
            $this->error("Workspace [{$identifier}] not found.");

            return self::FAILURE;
        }

        $previous = (string) $workspace->plan_tier;
        $workspace->applyPlanTier($tier, (bool) $this->option('runnr'));

        $this->info("Workspace [{$workspace->name}] changed from {$previous} to {$tier}. Stripe was not updated.");

        return self::SUCCESS;
    }
}
