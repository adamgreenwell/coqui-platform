<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Billing\Services\StripeWebhookSignature;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Subscription webhooks must follow portal plan switches and keep the base
 * plan and the Runnr add-on apart. They run with the runnr-integration flag
 * off (its default), because existing add-on subscriptions must keep syncing
 * while the Runnr UI is hidden.
 */
class StripeSubscriptionSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_subscription_sync';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'array');
        config()->set('services.stripe.webhook_secret', self::SECRET);
        config()->set('services.stripe.prices', [
            'personal' => 'price_personal_test',
            'pro' => 'price_pro_test',
            'agency' => 'price_agency_test',
            'runnr_addon' => 'price_runnr_test',
        ]);

        $owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Sync Workspace',
            'slug' => 'sync-workspace',
            'owner_id' => $owner->id,
            'plan_tier' => 'free',
        ]);
    }

    private function subscribeToPro(): void
    {
        $this->workspace->applyPlanTier('pro');
        $this->workspace->update([
            'stripe_id' => 'cus_sync',
            'stripe_subscription_id' => 'sub_base',
            'subscription_status' => 'active',
        ]);

        WorkspaceSubscription::query()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'default',
            'stripe_id' => 'sub_base',
            'stripe_status' => 'active',
            'stripe_price' => 'pro',
            'quantity' => 1,
        ]);
    }

    private function addRunnrAddon(): void
    {
        $this->workspace->update(['has_runnr_addon' => true]);

        WorkspaceSubscription::query()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'runnr_addon',
            'stripe_id' => 'sub_addon',
            'stripe_status' => 'active',
            'stripe_price' => 'runnr_addon',
            'quantity' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function subscription(string $id, string $priceId, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'object' => 'subscription',
            'customer' => 'cus_sync',
            'status' => 'active',
            'cancel_at' => null,
            'metadata' => [],
            'items' => [
                'data' => [
                    [
                        'id' => 'si_'.$id,
                        'price' => ['id' => $priceId],
                        'current_period_end' => 1798761600,
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_a_portal_switch_from_pro_to_agency_upgrades_the_workspace(): void
    {
        $this->subscribeToPro();

        $this->postEvent('customer.subscription.updated', $this->subscription('sub_base', 'price_agency_test', [
            // Metadata still names the plan bought at checkout.
            'metadata' => ['workspace_id' => (string) $this->workspace->id, 'plan_tier' => 'pro'],
        ]))->assertOk();

        $this->workspace->refresh();
        $this->assertSame('agency', $this->workspace->plan_tier);
        $this->assertSame(10000, $this->workspace->tracked_keyword_limit);
        $this->assertSame(1798761600, $this->workspace->current_period_end?->getTimestamp());
        $this->assertDatabaseHas('workspace_subscriptions', [
            'stripe_id' => 'sub_base',
            'type' => 'default',
            'stripe_price' => 'agency',
        ]);
    }

    public function test_a_portal_switch_from_personal_to_pro_upgrades_the_workspace(): void
    {
        $this->subscribeToPro();
        $this->workspace->applyPlanTier('personal');

        $this->postEvent('customer.subscription.updated', $this->subscription('sub_base', 'price_pro_test'))
            ->assertOk();

        $this->workspace->refresh();
        $this->assertSame('pro', $this->workspace->plan_tier);
        $this->assertSame(1000, $this->workspace->tracked_keyword_limit);
    }

    public function test_a_portal_switch_from_pro_to_personal_downgrades_the_workspace(): void
    {
        $this->subscribeToPro();

        $this->postEvent('customer.subscription.updated', $this->subscription('sub_base', 'price_personal_test'))
            ->assertOk();

        $this->workspace->refresh();
        $this->assertSame('personal', $this->workspace->plan_tier);
        $this->assertSame(300, $this->workspace->tracked_keyword_limit);
        $this->assertDatabaseHas('workspace_subscriptions', ['stripe_id' => 'sub_base', 'stripe_price' => 'personal']);
    }

    public function test_a_portal_switch_from_agency_to_pro_downgrades_the_workspace(): void
    {
        $this->subscribeToPro();
        $this->workspace->applyPlanTier('agency');

        $this->postEvent('customer.subscription.updated', $this->subscription('sub_base', 'price_pro_test'))
            ->assertOk();

        $this->assertSame('pro', $this->workspace->refresh()->plan_tier);
    }

    public function test_a_scheduled_cancellation_keeps_the_plan_and_records_the_end_date(): void
    {
        $this->subscribeToPro();

        $this->postEvent('customer.subscription.updated', $this->subscription('sub_base', 'price_pro_test', [
            'cancel_at' => 1798761600,
        ]))->assertOk();

        $this->assertSame('pro', $this->workspace->refresh()->plan_tier);
        $subscription = WorkspaceSubscription::query()->where('stripe_id', 'sub_base')->firstOrFail();
        $this->assertSame(1798761600, $subscription->ends_at?->getTimestamp());
    }

    public function test_an_unpaid_subscription_update_does_not_change_the_plan(): void
    {
        $this->subscribeToPro();

        $this->postEvent('customer.subscription.updated', $this->subscription('sub_base', 'price_agency_test', [
            'status' => 'unpaid',
        ]))->assertOk();

        $this->workspace->refresh();
        $this->assertSame('pro', $this->workspace->plan_tier);
        $this->assertSame('unpaid', $this->workspace->subscription_status);
    }

    public function test_an_add_on_update_leaves_the_base_subscription_alone(): void
    {
        $this->subscribeToPro();

        $this->postEvent('customer.subscription.updated', $this->subscription('sub_addon', 'price_runnr_test', [
            'status' => 'past_due',
        ]))->assertOk();

        $this->workspace->refresh();
        $this->assertSame('pro', $this->workspace->plan_tier);
        $this->assertSame('sub_base', $this->workspace->stripe_subscription_id);
        $this->assertSame('active', $this->workspace->subscription_status);
        $this->assertTrue($this->workspace->has_runnr_addon);
        $this->assertDatabaseHas('workspace_subscriptions', [
            'stripe_id' => 'sub_addon',
            'type' => 'runnr_addon',
            'stripe_status' => 'past_due',
        ]);
    }

    public function test_buying_the_add_on_does_not_replace_the_base_subscription(): void
    {
        $this->assertFalse(RunnrIntegration::enabled(), 'Add-on webhooks must sync while the Runnr UI is hidden.');
        $this->subscribeToPro();

        $this->postEvent('checkout.session.completed', [
            'id' => 'cs_test_addon',
            'customer' => 'cus_sync',
            'subscription' => 'sub_addon',
            'metadata' => ['workspace_id' => (string) $this->workspace->id, 'plan_tier' => 'runnr_addon'],
        ])->assertOk();

        $this->workspace->refresh();
        $this->assertSame('pro', $this->workspace->plan_tier);
        $this->assertSame('sub_base', $this->workspace->stripe_subscription_id);
        $this->assertTrue($this->workspace->has_runnr_addon);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutSession(string $subscriptionId, string $planTier): array
    {
        return [
            'id' => 'cs_test_'.$subscriptionId,
            'customer' => 'cus_sync',
            'subscription' => $subscriptionId,
            'metadata' => ['workspace_id' => (string) $this->workspace->id, 'plan_tier' => $planTier],
        ];
    }

    public function test_a_first_checkout_applies_the_purchased_plan(): void
    {
        $this->postEvent('checkout.session.completed', $this->checkoutSession('sub_base', 'pro'))->assertOk();

        $this->workspace->refresh();
        $this->assertSame('pro', $this->workspace->plan_tier);
        $this->assertSame('cus_sync', $this->workspace->stripe_id);
        $this->assertSame('sub_base', $this->workspace->stripe_subscription_id);
        $this->assertDatabaseHas('workspace_subscriptions', ['stripe_id' => 'sub_base', 'stripe_price' => 'pro']);
    }

    public function test_a_replayed_checkout_does_not_undo_a_portal_switch(): void
    {
        $this->subscribeToPro();
        $this->postEvent('customer.subscription.updated', $this->subscription('sub_base', 'price_agency_test'))
            ->assertOk();

        $this->postEvent('checkout.session.completed', $this->checkoutSession('sub_base', 'pro'))->assertOk();

        $this->assertSame('agency', $this->workspace->refresh()->plan_tier);
        $this->assertDatabaseHas('workspace_subscriptions', ['stripe_id' => 'sub_base', 'stripe_price' => 'agency']);
    }

    public function test_a_replayed_checkout_does_not_revive_a_cancelled_plan(): void
    {
        $this->subscribeToPro();
        $this->postEvent('customer.subscription.deleted', $this->subscription('sub_base', 'price_pro_test', [
            'status' => 'canceled',
        ]))->assertOk();

        $this->postEvent('checkout.session.completed', $this->checkoutSession('sub_base', 'pro'))->assertOk();

        $this->workspace->refresh();
        $this->assertSame('free', $this->workspace->plan_tier);
        $this->assertSame('canceled', $this->workspace->subscription_status);
        $this->assertDatabaseHas('workspace_subscriptions', ['stripe_id' => 'sub_base', 'stripe_status' => 'canceled']);
    }

    public function test_a_replayed_add_on_checkout_does_not_revive_a_cancelled_add_on(): void
    {
        $this->subscribeToPro();
        $this->addRunnrAddon();
        $this->postEvent('customer.subscription.deleted', $this->subscription('sub_addon', 'price_runnr_test', [
            'status' => 'canceled',
        ]))->assertOk();

        $this->postEvent('checkout.session.completed', $this->checkoutSession('sub_addon', 'runnr_addon'))->assertOk();

        $this->assertFalse($this->workspace->refresh()->has_runnr_addon);
    }

    public function test_a_replayed_checkout_still_links_a_missing_customer(): void
    {
        $this->subscribeToPro();
        $this->workspace->update(['stripe_id' => null]);

        $this->postEvent('checkout.session.completed', $this->checkoutSession('sub_base', 'pro'))->assertOk();

        $this->workspace->refresh();
        $this->assertSame('cus_sync', $this->workspace->stripe_id);
        $this->assertSame('pro', $this->workspace->plan_tier);
    }

    public function test_cancelling_the_add_on_keeps_the_base_plan(): void
    {
        $this->subscribeToPro();
        $this->addRunnrAddon();

        $this->postEvent('customer.subscription.deleted', $this->subscription('sub_addon', 'price_runnr_test', [
            'status' => 'canceled',
        ]))->assertOk();

        $this->workspace->refresh();
        $this->assertSame('pro', $this->workspace->plan_tier);
        $this->assertSame(1000, $this->workspace->tracked_keyword_limit);
        $this->assertSame('active', $this->workspace->subscription_status);
        $this->assertFalse($this->workspace->has_runnr_addon);
        $this->assertDatabaseHas('workspace_subscriptions', ['stripe_id' => 'sub_addon', 'stripe_status' => 'canceled']);
        $this->assertDatabaseHas('workspace_subscriptions', ['stripe_id' => 'sub_base', 'stripe_status' => 'active']);
    }

    public function test_cancelling_the_base_plan_keeps_an_active_add_on(): void
    {
        $this->subscribeToPro();
        $this->addRunnrAddon();

        $this->postEvent('customer.subscription.deleted', $this->subscription('sub_base', 'price_pro_test', [
            'status' => 'canceled',
        ]))->assertOk();

        $this->workspace->refresh();
        $this->assertSame('free', $this->workspace->plan_tier);
        $this->assertSame('canceled', $this->workspace->subscription_status);
        $this->assertTrue($this->workspace->has_runnr_addon);
    }

    public function test_subscription_events_for_unknown_customers_change_nothing(): void
    {
        $this->subscribeToPro();

        $this->postEvent('customer.subscription.deleted', $this->subscription('sub_other', 'price_pro_test', [
            'customer' => 'cus_someone_else',
        ]))->assertOk();

        $this->assertSame('pro', $this->workspace->refresh()->plan_tier);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function postEvent(string $type, array $object): TestResponse
    {
        $body = (string) json_encode(['type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => StripeWebhookSignature::sign($body, self::SECRET),
        ], $body);
    }
}
