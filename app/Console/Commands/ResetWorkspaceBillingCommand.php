<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\Models\BillingInvoice;
use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Projects\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator-only: forget a workspace's Stripe billing so it starts over on Free.
 *
 * Use it when the Stripe account behind the app changes, for example after
 * testing with sandbox keys and then switching to live keys. The stored
 * customer, subscriptions and invoices belong to the old account, so the app
 * would keep the paid plan and the portal would fail. Nothing is changed in
 * Stripe itself.
 */
class ResetWorkspaceBillingCommand extends Command
{
    protected $signature = 'billing:reset
        {workspace : Workspace ID, UUID or slug}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Forget a workspace\'s Stripe customer, subscriptions and invoices and drop it to Free (operator use only; Stripe is not changed).';

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('workspace'));
        $workspace = Workspace::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('slug', $identifier)->orWhere('uuid', $identifier);
                if (ctype_digit($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->first();

        if (! $workspace instanceof Workspace) {
            $this->error("Workspace [{$identifier}] not found.");

            return self::FAILURE;
        }

        $subscriptions = WorkspaceSubscription::query()->where('workspace_id', $workspace->id);
        $invoices = BillingInvoice::query()->where('workspace_id', $workspace->id);

        $this->table(['Field', 'Current value'], [
            ['Workspace', "{$workspace->name} (id {$workspace->id})"],
            ['Plan', (string) $workspace->plan_tier],
            ['Stripe customer', $workspace->stripe_id ?? '-'],
            ['Base subscription', $workspace->stripe_subscription_id ?? '-'],
            ['Runnr add-on', $workspace->has_runnr_addon ? 'yes' : 'no'],
            ['Subscription rows', (string) $subscriptions->count()],
            ['Invoice rows', (string) $invoices->count()],
        ]);

        if (! $this->option('force')
            && ! $this->confirm('Delete these subscription and invoice rows and drop the workspace to Free? Stripe is not changed.')) {
            $this->warn('Nothing changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($workspace, $subscriptions, $invoices): void {
            $subscriptions->delete();
            $invoices->delete();

            $workspace->update([
                'stripe_id' => null,
                'stripe_subscription_id' => null,
                'subscription_status' => 'active',
                'has_runnr_addon' => false,
            ]);
            $workspace->applyPlanTier('free');
        });

        $this->info("Workspace [{$workspace->name}] is on Free with no Stripe billing. Stripe was not updated.");

        return self::SUCCESS;
    }
}
