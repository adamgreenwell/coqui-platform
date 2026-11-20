<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The runnr-integration flag hides every user-facing part of the Runnr
 * integration until the Runnr platform is ready.
 */
class RunnrIntegrationFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.secret', null);
        config()->set('services.runnr.webhook_secret', null);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Runnr Flag Workspace',
            'slug' => 'runnr-flag-workspace',
            'owner_id' => $this->owner->id,
            'plan_tier' => 'agency',
            'has_runnr_addon' => true,
            'tracked_keyword_limit' => 10000,
            'cloud_crawl_url_limit' => 250000,
            'monthly_dfs_budget_usd' => 750.00,
        ]);
        $this->workspace->members()->attach($this->owner->id, ['role' => 'owner']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Runnr Site',
            'domain' => 'runnr-site.test',
            'canonical_url' => 'https://runnr-site.test/',
            'runnr_site_id' => 'site_flag_123',
        ]);

        ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'runnr',
            'external_identifier' => 'site_flag_123',
            'status' => 'connected',
        ]);
    }

    public function test_the_flag_is_off_by_default(): void
    {
        $this->assertFalse(RunnrIntegration::enabled());

        $this->actingAs($this->owner)
            ->get(route('workspaces.billing.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('features.runnrIntegration', false));
    }

    public function test_flag_off_hides_runnr_from_the_billing_page(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspaces.billing.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workspaces/Billing')
                ->where('features.runnrIntegration', false)
                ->missing('workspace.has_runnr_addon')
                ->missing('usage.runnr_entitled')
                ->missing('plans.runnr_addon')
                ->has('plans.agency')
                ->where('plans.agency.features', fn ($features): bool => collect($features)
                    ->every(fn (string $feature): bool => stripos($feature, 'runnr') === false))
            );
    }

    public function test_flag_off_refuses_add_on_checkout_without_calling_stripe(): void
    {
        config()->set('services.stripe.secret', 'sk_test_example');
        config()->set('services.stripe.prices.runnr_addon', 'price_runnr_test');
        $this->workspace->update(['has_runnr_addon' => false]);
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'runnr_addon'])
            ->assertRedirect(route('workspaces.billing.index', $this->workspace))
            ->assertSessionHas('error', 'The Runnr hosting add-on is not available yet.');

        Http::assertNothingSent();
    }

    public function test_flag_off_still_allows_base_plan_checkout(): void
    {
        $this->workspace->update(['plan_tier' => 'free']);

        $response = $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'pro']);

        $response->assertRedirect();
        $this->assertStringContainsString('cs_mock_', (string) $response->headers->get('Location'));
    }

    public function test_flag_off_hides_runnr_from_project_pages(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspaces.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Show')
                ->where('features.runnrIntegration', false)
                ->missing('project.runnr_site_id')
                ->missing('deploySmoke')
                ->missing('runnrWebhookUrl')
                ->missing('runnrBadgeUrl')
                ->where('integrations', fn ($integrations): bool => collect($integrations)
                    ->doesntContain('provider', 'runnr'))
            );

        $this->actingAs($this->owner)
            ->get(route('workspaces.projects.bot-logs.index', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('project.runnr_site_id'));

        $this->actingAs($this->owner)
            ->get(route('workspaces.projects.analytics.performance', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('project.runnr_site_id'));

        $this->actingAs($this->owner)
            ->getJson(route('workspaces.projects.integrations.index', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertJsonCount(0, 'integrations');

        // Hiding the badge must not mint a badge token as a side effect.
        $this->assertNull($this->project->refresh()->deploy_badge_token);
    }

    public function test_flag_off_returns_404_for_runnr_routes(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspaces.projects.integrations.runnr.connect', [$this->workspace, $this->project]), [
                'runnr_site_id' => 'site_new',
            ])
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->delete(route('workspaces.projects.integrations.runnr.disconnect', [$this->workspace, $this->project]))
            ->assertNotFound();

        $this->assertSame('site_flag_123', $this->project->refresh()->runnr_site_id);

        $this->postJson('/api/webhooks/runnr/deploy', ['site_id' => 'site_flag_123'])->assertNotFound();
        $this->postJson('/api/v1/telemetry/bot-logs', ['site_id' => 'site_flag_123', 'entries' => []])->assertNotFound();
        $this->get('/api/v1/deploy-smoke/badge/'.$this->project->deployBadgeToken())->assertNotFound();

        Sanctum::actingAs($this->owner, ['projects:read']);
        $this->getJson("/api/v1/projects/{$this->project->id}/deploy-smoke/latest")->assertNotFound();
    }

    public function test_flag_on_shows_runnr_on_the_billing_page(): void
    {
        RunnrIntegration::activate();

        $this->actingAs($this->owner)
            ->get(route('workspaces.billing.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('features.runnrIntegration', true)
                ->where('workspace.has_runnr_addon', true)
                ->where('usage.runnr_entitled', true)
                ->has('plans.runnr_addon')
                ->where('plans.agency.features', fn ($features): bool => collect($features)
                    ->contains(fn (string $feature): bool => stripos($feature, 'runnr') !== false))
            );
    }

    public function test_flag_on_opens_add_on_checkout(): void
    {
        RunnrIntegration::activate();
        $this->workspace->update(['has_runnr_addon' => false]);

        $response = $this->actingAs($this->owner)
            ->post(route('workspaces.billing.checkout', $this->workspace), ['plan_tier' => 'runnr_addon']);

        $response->assertRedirect();
        $this->assertStringContainsString('plan=runnr_addon', (string) $response->headers->get('Location'));
    }

    public function test_flag_on_shows_runnr_on_project_pages(): void
    {
        RunnrIntegration::activate();

        $this->actingAs($this->owner)
            ->get(route('workspaces.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('features.runnrIntegration', true)
                ->where('project.runnr_site_id', 'site_flag_123')
                ->has('runnrWebhookUrl')
                ->has('runnrBadgeUrl')
                ->where('integrations', fn ($integrations): bool => collect($integrations)
                    ->contains('provider', 'runnr'))
            );

        $this->actingAs($this->owner)
            ->get(route('workspaces.projects.bot-logs.index', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page->where('project.runnr_site_id', 'site_flag_123'));
    }

    public function test_flag_on_serves_runnr_routes(): void
    {
        RunnrIntegration::activate();

        $this->actingAs($this->owner)
            ->post(route('workspaces.projects.integrations.runnr.connect', [$this->workspace, $this->project]), [
                'runnr_site_id' => 'site_new',
            ])
            ->assertRedirect();
        $this->assertSame('site_new', $this->project->refresh()->runnr_site_id);

        // No webhook secret configured: the controller answers, not the flag.
        $this->postJson('/api/webhooks/runnr/deploy', ['site_id' => 'site_new'])->assertStatus(503);
        $this->get('/api/v1/deploy-smoke/badge/'.$this->project->deployBadgeToken())->assertOk();
    }

    public function test_the_flag_can_be_turned_back_off(): void
    {
        RunnrIntegration::activate();
        $this->assertTrue(RunnrIntegration::enabled());

        RunnrIntegration::deactivate();
        $this->assertFalse(RunnrIntegration::enabled());
    }
}
