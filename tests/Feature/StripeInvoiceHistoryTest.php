<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\BillingInvoice;
use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Billing\Services\StripeWebhookSignature;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stripe sends invoice.payment_succeeded for a new subscription before
 * checkout.session.completed, so the workspace has no Stripe customer yet
 * and the first invoice was dropped from billing history.
 */
class StripeInvoiceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_invoice_history';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'array');
        config()->set('services.stripe.webhook_secret', self::SECRET);

        $owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Invoice Workspace',
            'slug' => 'invoice-workspace',
            'owner_id' => $owner->id,
            'plan_tier' => 'free',
        ]);
    }

    /**
     * Shaped like the 2026-10-28 API's invoice payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'id' => 'in_first',
            'object' => 'invoice',
            'customer' => 'cus_new',
            'amount_paid' => 5243,
            'currency' => 'usd',
            'invoice_pdf' => 'https://pay.stripe.com/invoice/in_first/pdf',
            'status' => 'paid',
            'status_transitions' => ['paid_at' => 1795186726],
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => [
                    'subscription' => 'sub_new',
                    'metadata' => ['workspace_id' => (string) $this->workspace->id, 'plan_tier' => 'pro'],
                ],
            ],
        ], $overrides);
    }

    public function test_the_first_invoice_is_recorded_before_checkout_links_the_customer(): void
    {
        $this->postEvent('invoice.payment_succeeded', $this->invoice())->assertOk();

        $invoice = BillingInvoice::query()->where('stripe_invoice_id', 'in_first')->firstOrFail();
        $this->assertSame($this->workspace->id, $invoice->workspace_id);
        $this->assertSame(52.43, $invoice->amount_paid_usd);
        $this->assertSame(1795186726, $invoice->paid_at?->getTimestamp());
    }

    public function test_a_resent_invoice_event_does_not_duplicate_the_row(): void
    {
        $this->postEvent('invoice.payment_succeeded', $this->invoice())->assertOk();
        $this->postEvent('invoice.payment_succeeded', $this->invoice())->assertOk();

        $this->assertSame(1, BillingInvoice::query()->count());
    }

    public function test_invoices_still_match_by_customer_without_metadata(): void
    {
        $this->workspace->update(['stripe_id' => 'cus_existing']);

        $this->postEvent('invoice.payment_succeeded', $this->invoice([
            'id' => 'in_renewal',
            'customer' => 'cus_existing',
            'parent' => null,
        ]))->assertOk();

        $this->assertDatabaseHas('billing_invoices', [
            'stripe_invoice_id' => 'in_renewal',
            'workspace_id' => $this->workspace->id,
        ]);
    }

    public function test_metadata_cannot_attach_an_invoice_to_a_workspace_of_another_customer(): void
    {
        $this->workspace->update(['stripe_id' => 'cus_owner']);

        $this->postEvent('invoice.payment_succeeded', $this->invoice(['customer' => 'cus_someone_else']))
            ->assertOk();

        $this->assertSame(0, BillingInvoice::query()->count());
    }

    public function test_a_failed_first_payment_marks_the_workspace_past_due(): void
    {
        WorkspaceSubscription::query()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'default',
            'stripe_id' => 'sub_new',
            'stripe_status' => 'incomplete',
            'stripe_price' => 'pro',
            'quantity' => 1,
        ]);

        $this->postEvent('invoice.payment_failed', $this->invoice(['status' => 'open', 'amount_paid' => 0]))
            ->assertOk();

        $this->assertSame('past_due', $this->workspace->refresh()->subscription_status);
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
