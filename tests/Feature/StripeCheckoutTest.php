<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Checkout and the portal used to return fake cs_mock_/portal_mock URLs in
 * every environment, and price IDs fell back to invented strings (#68).
 */
class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.secret', null);
        config()->set('services.stripe.base_url', 'https://api.stripe.com');
        config()->set('services.stripe.prices', ['pro' => null, 'agency' => null, 'runnr_addon' => null]);

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->workspace = Workspace::query()->create([
            'name' => 'Checkout Workspace',
            'slug' => 'checkout-workspace',
            'owner_id' => $this->owner->id,
            'plan_tier' => 'free',
            'tracked_keyword_limit' => 100,
            'cloud_crawl_url_limit' => 1000,
            'monthly_dfs_budget_usd' => 25.00,
        ]);
        $this->workspace->members()->attach($this->owner->id, ['role' => 'owner']);
    }

    private function useRealStripe(): void
    {
        config()->set('services.stripe.secret', 'sk_test_example');
        config()->set('services.stripe.prices', [
            'pro' => 'price_pro_test',
            'agency' => 'price_agency_test',
            'runnr_addon' => 'price_runnr_test',
        ]);
    }

    private function inProduction(): void
    {
        $this->app['env'] = 'production';
        // CSRF checks switch back on outside the testing env; they're not under test here.
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    private function billingUrl(): string
    {
        return route('workspaces.billing.index', $this->workspace);
    }

    public function test_checkout_without_keys_outside_local_shows_an_error_instead_of_fake_success(): void
    {
        $this->inProduction();
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'pro'])
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'not set up'));

        Http::assertNothingSent();
        $this->assertSame('free', $this->workspace->fresh()->plan_tier);
    }

    public function test_portal_without_keys_outside_local_shows_an_error(): void
    {
        $this->inProduction();
        $this->workspace->update(['stripe_id' => 'cus_123']);

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.portal', $this->workspace))
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error');
    }

    public function test_a_mock_prefixed_secret_outside_local_is_treated_as_unconfigured(): void
    {
        $this->inProduction();
        config()->set('services.stripe.secret', 'mock_secret');

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'pro'])
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error');
    }

    public function test_checkout_opens_a_real_stripe_session_when_keys_are_set(): void
    {
        $this->useRealStripe();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ]),
        ]);

        $this->actingAs($this->owner)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'agency'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_123');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer sk_test_example')
                && $request['mode'] === 'subscription'
                && $request['line_items'][0]['price'] === 'price_agency_test'
                && $request['metadata']['workspace_id'] === (string) $this->workspace->id
                && $request['metadata']['plan_tier'] === 'agency'
                && $request['customer_email'] === 'owner@example.com';
        });
    }

    public function test_checkout_reuses_the_existing_stripe_customer(): void
    {
        $this->useRealStripe();
        $this->workspace->update(['stripe_id' => 'cus_existing']);
        Http::fake([
            'api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/c/pay/cs_test_456']),
        ]);

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'runnr_addon'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_456');

        Http::assertSent(fn (Request $request): bool => $request['customer'] === 'cus_existing'
            && $request['line_items'][0]['price'] === 'price_runnr_test'
            && ! isset($request['customer_email']));
    }

    public function test_checkout_refuses_when_the_plan_has_no_price_id(): void
    {
        $this->useRealStripe();
        config()->set('services.stripe.prices.pro', null);
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'pro'])
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'not available'));

        Http::assertNothingSent();
    }

    public function test_stripe_api_errors_show_a_friendly_message_without_leaking_the_response(): void
    {
        $this->useRealStripe();
        Http::fake([
            'api.stripe.com/*' => Http::response([
                'error' => ['message' => 'No such price: price_pro_test', 'code' => 'resource_missing'],
            ], 400),
        ]);

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'pro'])
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Stripe could not start')
                && ! str_contains($message, 'price_pro_test'));
    }

    public function test_checkout_for_a_base_plan_is_refused_while_one_is_already_active(): void
    {
        $this->useRealStripe();
        WorkspaceSubscription::query()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'default',
            'stripe_id' => 'sub_existing',
            'stripe_status' => 'active',
            'stripe_price' => 'pro',
            'quantity' => 1,
        ]);
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'agency'])
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'already has a paid plan'));

        Http::assertNothingSent();
    }

    public function test_portal_opens_a_real_stripe_session_for_existing_customers(): void
    {
        $this->useRealStripe();
        $this->workspace->update(['stripe_id' => 'cus_portal']);
        Http::fake([
            'api.stripe.com/v1/billing_portal/sessions' => Http::response([
                'url' => 'https://billing.stripe.com/p/session/test_789',
            ]),
        ]);

        $this->actingAs($this->owner)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('workspaces.billing.portal', $this->workspace))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://billing.stripe.com/p/session/test_789');

        Http::assertSent(fn (Request $request): bool => $request['customer'] === 'cus_portal'
            && $request['return_url'] === $this->billingUrl());
    }

    public function test_portal_explains_when_the_workspace_has_no_stripe_customer(): void
    {
        $this->useRealStripe();
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.portal', $this->workspace))
            ->assertRedirect($this->billingUrl())
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'no Stripe billing account'));

        Http::assertNothingSent();
    }

    public function test_billing_page_reports_the_billing_mode(): void
    {
        $this->actingAs($this->owner)
            ->get($this->billingUrl())
            ->assertInertia(fn (Assert $page) => $page->where('billingMode', 'mock'));

        $this->inProduction();

        $this->actingAs($this->owner)
            ->get($this->billingUrl())
            ->assertInertia(fn (Assert $page) => $page->where('billingMode', 'unconfigured'));

        $this->useRealStripe();

        $this->actingAs($this->owner)
            ->get($this->billingUrl())
            ->assertInertia(fn (Assert $page) => $page->where('billingMode', 'stripe'));
    }
}
