<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Billing\Services\StripeWebhookSignature;
use App\Domains\Crawls\Jobs\DispatchCloudCrawlJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoBudgetException;
use App\Domains\Serp\DataForSeo\DataForSeoSpendGuard;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class WorkspaceBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private const STRIPE_WEBHOOK_SECRET = 'whsec_test_billing';

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'array');
        config()->set('services.stripe.webhook_secret', self::STRIPE_WEBHOOK_SECRET);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Acme Billing Workspace',
            'slug' => 'acme-billing-workspace',
            'owner_id' => $this->owner->id,
            'plan_tier' => 'free',
            'tracked_keyword_limit' => 100,
            'cloud_crawl_url_limit' => 1000,
            'monthly_dfs_budget_usd' => 25.00,
        ]);
        $this->workspace->members()->attach($this->owner->id, ['role' => 'owner']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Acme App',
            'domain' => 'acme.app',
            'canonical_url' => 'https://acme.app/',
        ]);
    }

    public function test_workspace_billing_index_renders_inertia_with_plans_and_usage(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('workspaces.billing.index', $this->workspace));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Workspaces/Billing')
            ->has('workspace')
            ->where('workspace.plan_tier', 'free')
            ->has('usage')
            ->where('usage.keywords_limit', 100)
            ->where('usage.crawl_urls_limit', 1000)
            ->where('usage.dfs_budget_usd', fn ($val): bool => (float) $val === 25.0)
            ->has('plans')
            ->has('invoices')
            ->where('canEdit', true)
        );
    }

    public function test_plan_limit_enforcement_on_keywords(): void
    {
        // Populate 100 keywords to reach free plan limit
        $keywords = [];
        for ($i = 1; $i <= 100; $i++) {
            $keywords[] = [
                'uuid' => (string) Str::uuid7(),
                'project_id' => $this->project->id,
                'keyword' => "keyword-{$i}",
                'device' => 'desktop',
                'location_code' => 2840,
                'language_code' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        TrackedKeyword::query()->insert($keywords);

        $this->assertSame(100, $this->workspace->trackedKeywordsCount());

        // Attempting to add 1 more keyword should trigger validation error
        $response = $this->actingAs($this->owner)
            ->post(route('workspaces.projects.keywords.store', [$this->workspace, $this->project]), [
                'keywords' => 'new-overflow-keyword',
            ]);

        $response->assertSessionHasErrors('keywords');

        // Upgrade to Pro tier allowing 1,000 keywords
        $this->workspace->applyPlanTier('pro');
        $this->assertSame(1000, $this->workspace->fresh()->tracked_keyword_limit);

        $successResponse = $this->actingAs($this->owner)
            ->post(route('workspaces.projects.keywords.store', [$this->workspace, $this->project]), [
                'keywords' => 'new-allowed-keyword',
            ]);

        $successResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tracked_keywords', [
            'project_id' => $this->project->id,
            'keyword' => 'new-allowed-keyword',
        ]);
    }

    public function test_plan_limit_enforcement_on_cloud_crawl_urls(): void
    {
        // Free tier has 1,000 URL limit - test model assertion directly
        $this->expectException(ValidationException::class);
        $this->workspace->assertCanCrawlUrls(5000);
    }

    public function test_cloud_crawl_controller_enforces_url_limit(): void
    {
        Queue::fake();
        config()->set('coqui.worker.token', 'worker-token');

        // Free tier has 1,000 URL limit - test controller endpoint
        $response = $this->actingAs($this->owner)
            ->post(route('workspaces.projects.crawls.dispatch', [$this->workspace, $this->project]), [
                'max_urls' => 5000,
            ]);

        $response->assertSessionHasErrors('max_urls');

        // Upgrade workspace to pro tier (25,000 URL limit)
        $this->workspace->applyPlanTier('pro');

        $allowedResponse = $this->actingAs($this->owner)
            ->post(route('workspaces.projects.crawls.dispatch', [$this->workspace, $this->project]), [
                'max_urls' => 5000,
            ]);

        $allowedResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('crawl_runs', [
            'project_id' => $this->project->id,
            'source' => 'worker',
            'status' => 'pending',
        ]);
        Queue::assertPushed(DispatchCloudCrawlJob::class);
    }

    public function test_runnr_entitlement_unlocking(): void
    {
        // Base free workspace without add-on or Runnr site ID
        $this->assertFalse($this->workspace->hasRunnrEntitlement());
        $this->assertFalse($this->project->hasRunnrEntitlement());

        // Enabled via workspace add-on
        $this->workspace->update(['has_runnr_addon' => true]);
        $this->assertTrue($this->workspace->hasRunnrEntitlement());
        $this->assertTrue($this->project->refresh()->hasRunnrEntitlement());

        // Reset add-on and test agency plan tier
        $this->workspace->update(['has_runnr_addon' => false, 'plan_tier' => 'agency']);
        $this->assertTrue($this->workspace->hasRunnrEntitlement());
        $this->assertTrue($this->project->refresh()->hasRunnrEntitlement());

        // Reset workspace to free: a linked Runnr site ID is unverified, so it
        // must not unlock anything on its own.
        $this->workspace->update(['plan_tier' => 'free']);
        $this->assertFalse($this->workspace->hasRunnrEntitlement());

        $this->project->update(['runnr_site_id' => 'runnr_site_xyz789']);
        $this->assertFalse($this->project->refresh()->hasRunnrEntitlement());
        $this->assertFalse($this->workspace->refresh()->hasRunnrEntitlement());
    }

    public function test_dataforseo_spend_allowance_limit_enforcement(): void
    {
        config()->set('coqui.dataforseo.base_url', 'https://api.dataforseo.com');
        config()->set('coqui.dataforseo.daily_cap_usd', 100.00);
        config()->set('coqui.dataforseo.monthly_cap_usd', 1000.00);

        $guard = new DataForSeoSpendGuard;

        // Spend within $25 monthly budget passes
        $guard->assertCanSpend(10.00, $this->workspace);

        // Record $20 of spend for workspace
        $guard->recordSpend(
            endpoint: '/v3/serp/google/organic/task_post',
            mode: 'standard',
            estimatedCostUsd: 20.00,
            actualCostUsd: 20.00,
            workspaceId: $this->workspace->id,
            projectId: $this->project->id,
        );

        // Now an additional $10.00 spend would exceed $25 budget (20 + 10 = 30 > 25)
        $this->expectException(DataForSeoBudgetException::class);
        $guard->assertCanSpend(10.00, $this->workspace);
    }

    public function test_pennant_feature_flags_by_plan_tier(): void
    {
        // Free tier checks
        Feature::flushCache();
        $this->workspace->applyPlanTier('free');
        $this->assertFalse(Feature::for($this->workspace)->active('content-briefs'));
        $this->assertFalse(Feature::for($this->workspace)->active('backlink-intelligence'));
        $this->assertTrue(Feature::for($this->workspace)->active('traffic-attribution'));
        $this->assertFalse(Feature::for($this->workspace)->active('white-label-reports'));
        $this->assertFalse(Feature::for($this->workspace)->active('runnr-smoke-tests'));

        // Personal tier: the Pro feature set, without the Agency extras
        Feature::flushCache();
        $this->workspace->applyPlanTier('solo');
        $this->assertTrue(Feature::for($this->workspace)->active('content-briefs'));
        $this->assertTrue(Feature::for($this->workspace)->active('backlink-intelligence'));
        $this->assertTrue(Feature::for($this->workspace)->active('traffic-attribution'));
        $this->assertTrue(Feature::for($this->workspace)->active('bot-telemetry'));
        $this->assertFalse(Feature::for($this->workspace)->active('white-label-reports'));
        $this->assertFalse(Feature::for($this->workspace)->active('priority-queue'));
        $this->assertFalse(Feature::for($this->workspace)->active('runnr-smoke-tests'));

        // Pro tier checks
        Feature::flushCache();
        $this->workspace->applyPlanTier('pro');
        $this->assertTrue(Feature::for($this->workspace)->active('content-briefs'));
        $this->assertTrue(Feature::for($this->workspace)->active('backlink-intelligence'));
        $this->assertTrue(Feature::for($this->workspace)->active('traffic-attribution'));
        $this->assertFalse(Feature::for($this->workspace)->active('white-label-reports'));
        $this->assertFalse(Feature::for($this->workspace)->active('priority-queue'));
        $this->assertFalse(Feature::for($this->workspace)->active('runnr-smoke-tests'));

        // Agency tier checks
        Feature::flushCache();
        $this->workspace->applyPlanTier('agency');
        $this->assertTrue(Feature::for($this->workspace)->active('content-briefs'));
        $this->assertTrue(Feature::for($this->workspace)->active('backlink-intelligence'));
        $this->assertTrue(Feature::for($this->workspace)->active('traffic-attribution'));
        $this->assertTrue(Feature::for($this->workspace)->active('white-label-reports'));
        $this->assertTrue(Feature::for($this->workspace)->active('priority-queue'));
        $this->assertTrue(Feature::for($this->workspace)->active('runnr-smoke-tests'));
        $this->assertTrue(Feature::for($this->workspace)->active('bot-telemetry'));
    }

    public function test_checkout_session_redirect(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), [
                'plan_tier' => 'pro',
            ]);

        $response->assertRedirect();
        $target = (string) $response->headers->get('Location');
        $this->assertStringContainsString('session_id=cs_mock_', $target);
        $this->assertStringContainsString('plan=pro', $target);
    }

    public function test_the_solo_plan_has_its_own_limits_and_checkout(): void
    {
        $this->workspace->applyPlanTier('solo');
        $this->workspace->refresh();
        $this->assertSame('solo', $this->workspace->plan_tier);
        $this->assertSame(300, $this->workspace->tracked_keyword_limit);
        $this->assertSame(5000, $this->workspace->cloud_crawl_url_limit);
        $this->assertSame(8.0, (float) $this->workspace->monthly_dfs_budget_usd);

        $this->workspace->applyPlanTier('free');
        $response = $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), [
                'plan_tier' => 'solo',
            ]);

        $response->assertRedirect();
        $this->assertStringContainsString('plan=solo', (string) $response->headers->get('Location'));
    }

    public function test_customer_portal_redirect(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('workspaces.billing.portal', $this->workspace));

        $response->assertRedirect();
        $target = (string) $response->headers->get('Location');
        $this->assertStringContainsString('portal_mock=1', $target);
    }

    public function test_workspace_admin_cannot_change_plan_without_payment(): void
    {
        $response = $this->actingAs($this->owner)
            ->post('/workspaces/'.$this->workspace->uuid.'/billing/change-tier', [
                'plan_tier' => 'agency',
                'has_runnr_addon' => true,
            ]);

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->workspace->refresh();

        $this->assertSame('free', $this->workspace->plan_tier);
        $this->assertFalse((bool) $this->workspace->has_runnr_addon);
    }

    public function test_operator_command_overrides_plan_tier(): void
    {
        $this->artisan('billing:set-tier', ['workspace' => $this->workspace->slug, 'tier' => 'agency'])
            ->assertSuccessful();

        $this->workspace->refresh();

        $this->assertSame('agency', $this->workspace->plan_tier);
        $this->assertSame(5000, $this->workspace->tracked_keyword_limit);
        $this->assertSame(250000, $this->workspace->cloud_crawl_url_limit);
        $this->assertEquals(58.00, $this->workspace->monthly_dfs_budget_usd);
    }

    public function test_operator_command_rejects_unknown_tier(): void
    {
        $this->artisan('billing:set-tier', ['workspace' => (string) $this->workspace->id, 'tier' => 'platinum'])
            ->assertFailed();

        $this->assertSame('free', $this->workspace->refresh()->plan_tier);
    }

    public function test_stripe_webhook_checkout_session_completed(): void
    {
        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_sample_123',
                    'customer' => 'cus_stripe_sample_456',
                    'subscription' => 'sub_stripe_sample_789',
                    'metadata' => [
                        'workspace_id' => (string) $this->workspace->id,
                        'plan_tier' => 'pro',
                    ],
                ],
            ],
        ];

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'event' => 'checkout.session.completed',
        ]);

        $this->workspace->refresh();
        $this->assertSame('pro', $this->workspace->plan_tier);
        $this->assertSame('cus_stripe_sample_456', $this->workspace->stripe_id);
        $this->assertSame('sub_stripe_sample_789', $this->workspace->stripe_subscription_id);
        $this->assertSame('active', $this->workspace->subscription_status);
        $this->assertSame(1000, $this->workspace->tracked_keyword_limit);
        $this->assertSame(25000, $this->workspace->cloud_crawl_url_limit);
        $this->assertEquals(22.00, $this->workspace->monthly_dfs_budget_usd);

        $this->assertDatabaseHas('workspace_subscriptions', [
            'workspace_id' => $this->workspace->id,
            'stripe_id' => 'sub_stripe_sample_789',
            'stripe_status' => 'active',
            'stripe_price' => 'pro',
            'type' => 'default',
        ]);
    }

    public function test_stripe_webhook_customer_subscription_deleted(): void
    {
        // First set workspace as pro with subscription
        $this->workspace->update([
            'plan_tier' => 'pro',
            'stripe_id' => 'cus_123',
            'stripe_subscription_id' => 'sub_stripe_to_delete',
            'subscription_status' => 'active',
        ]);

        WorkspaceSubscription::query()->create([
            'workspace_id' => $this->workspace->id,
            'stripe_id' => 'sub_stripe_to_delete',
            'stripe_status' => 'active',
            'stripe_price' => 'pro',
            'type' => 'default',
        ]);

        $payload = [
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_stripe_to_delete',
                    'customer' => 'cus_123',
                    'metadata' => [
                        'workspace_id' => (string) $this->workspace->id,
                    ],
                ],
            ],
        ];

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'event' => 'customer.subscription.deleted',
        ]);

        $this->workspace->refresh();
        $this->assertSame('free', $this->workspace->plan_tier);
        $this->assertSame('canceled', $this->workspace->subscription_status);
        $this->assertSame(20, $this->workspace->tracked_keyword_limit);
        $this->assertSame(1000, $this->workspace->cloud_crawl_url_limit);

        $this->assertDatabaseHas('workspace_subscriptions', [
            'workspace_id' => $this->workspace->id,
            'stripe_id' => 'sub_stripe_to_delete',
            'stripe_status' => 'canceled',
        ]);
    }

    public function test_stripe_webhook_invoice_payment_succeeded(): void
    {
        $this->workspace->update(['stripe_id' => 'cus_stripe_sample_456']);

        $payload = [
            'type' => 'invoice.payment_succeeded',
            'data' => [
                'object' => [
                    'id' => 'in_stripe_sample_123',
                    'customer' => 'cus_stripe_sample_456',
                    'subscription' => 'sub_stripe_sample_789',
                    'amount_paid' => 4900,
                    'currency' => 'usd',
                    'status' => 'paid',
                    'invoice_pdf' => 'https://pay.stripe.com/invoice/sample.pdf',
                    'subscription_details' => [
                        'metadata' => [
                            'workspace_id' => (string) $this->workspace->id,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'event' => 'invoice.payment_succeeded',
        ]);

        $this->assertDatabaseHas('billing_invoices', [
            'workspace_id' => $this->workspace->id,
            'stripe_invoice_id' => 'in_stripe_sample_123',
            'amount_paid_usd' => 49.00,
            'status' => 'paid',
            'invoice_pdf_url' => 'https://pay.stripe.com/invoice/sample.pdf',
        ]);
    }

    public function test_stripe_webhook_invoice_payment_failed(): void
    {
        $this->workspace->update(['stripe_id' => 'cus_stripe_sample_456']);

        $payload = [
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'in_stripe_failed_123',
                    'customer' => 'cus_stripe_sample_456',
                    'subscription' => 'sub_stripe_sample_789',
                    'amount_due' => 4900,
                    'status' => 'open',
                    'subscription_details' => [
                        'metadata' => [
                            'workspace_id' => (string) $this->workspace->id,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $this->workspace->refresh();
        $this->assertSame('past_due', $this->workspace->subscription_status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postStripeWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => StripeWebhookSignature::sign($body, self::STRIPE_WEBHOOK_SECRET),
        ], $body);
    }
}
