<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\BillingInvoice;
use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * billing:reset clears a workspace's Stripe data from an old Stripe account
 * (for example the sandbox) before the app switches to live keys.
 */
class ResetWorkspaceBillingCommandTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'array');

        $owner = User::factory()->create();
        $this->workspace = $this->paidWorkspace($owner, 'sandbox-workspace', 'cus_sandbox', 'sub_sandbox', 'in_sandbox');
        $this->other = $this->paidWorkspace($owner, 'other-workspace', 'cus_other', 'sub_other', 'in_other');
    }

    private function paidWorkspace(User $owner, string $slug, string $customer, string $subscription, string $invoice): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'owner_id' => $owner->id,
            'plan_tier' => 'free',
        ]);
        $workspace->applyPlanTier('agency', true);
        $workspace->update([
            'stripe_id' => $customer,
            'stripe_subscription_id' => $subscription,
            'subscription_status' => 'past_due',
        ]);

        WorkspaceSubscription::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'default',
            'stripe_id' => $subscription,
            'stripe_status' => 'active',
            'stripe_price' => 'agency',
            'quantity' => 1,
        ]);
        WorkspaceSubscription::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'runnr_addon',
            'stripe_id' => $subscription.'_addon',
            'stripe_status' => 'active',
            'stripe_price' => 'runnr_addon',
            'quantity' => 1,
        ]);
        BillingInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'stripe_invoice_id' => $invoice,
            'amount_paid_usd' => 52.43,
            'currency' => 'usd',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $workspace->refresh();
    }

    public function test_it_drops_the_workspace_to_free_and_forgets_its_stripe_data(): void
    {
        $this->artisan('billing:reset', ['workspace' => $this->workspace->slug, '--force' => true])
            ->assertSuccessful();

        $this->workspace->refresh();
        $this->assertSame('free', $this->workspace->plan_tier);
        $this->assertSame(20, $this->workspace->tracked_keyword_limit);
        $this->assertFalse($this->workspace->has_runnr_addon);
        $this->assertNull($this->workspace->stripe_id);
        $this->assertNull($this->workspace->stripe_subscription_id);
        $this->assertSame('active', $this->workspace->subscription_status);
        $this->assertSame(0, WorkspaceSubscription::query()->where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(0, BillingInvoice::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_other_workspaces_are_left_alone(): void
    {
        $this->artisan('billing:reset', ['workspace' => (string) $this->workspace->id, '--force' => true])
            ->assertSuccessful();

        $this->other->refresh();
        $this->assertSame('agency', $this->other->plan_tier);
        $this->assertSame('cus_other', $this->other->stripe_id);
        $this->assertSame(2, WorkspaceSubscription::query()->where('workspace_id', $this->other->id)->count());
        $this->assertSame(1, BillingInvoice::query()->where('workspace_id', $this->other->id)->count());
    }

    public function test_it_finds_the_workspace_by_uuid(): void
    {
        $this->artisan('billing:reset', ['workspace' => $this->workspace->uuid, '--force' => true])
            ->assertSuccessful();

        $this->assertSame('free', $this->workspace->refresh()->plan_tier);
    }

    public function test_declining_the_prompt_changes_nothing(): void
    {
        $this->artisan('billing:reset', ['workspace' => $this->workspace->slug])
            ->expectsConfirmation('Delete these subscription and invoice rows and drop the workspace to Free? Stripe is not changed.', 'no')
            ->assertFailed();

        $this->workspace->refresh();
        $this->assertSame('agency', $this->workspace->plan_tier);
        $this->assertSame('cus_sandbox', $this->workspace->stripe_id);
        $this->assertSame(2, WorkspaceSubscription::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_an_id_that_is_also_another_workspaces_slug_is_refused(): void
    {
        $this->other->update(['slug' => (string) $this->workspace->id]);

        $this->artisan('billing:reset', ['workspace' => (string) $this->workspace->id, '--force' => true])
            ->expectsOutputToContain('more than one workspace')
            ->assertFailed();

        $this->assertSame('agency', $this->workspace->refresh()->plan_tier);
        $this->assertSame('agency', $this->other->refresh()->plan_tier);
        $this->assertSame(1, BillingInvoice::query()->where('workspace_id', $this->other->id)->count());
    }

    public function test_an_unknown_workspace_fails(): void
    {
        $this->artisan('billing:reset', ['workspace' => 'no-such-workspace', '--force' => true])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }
}
