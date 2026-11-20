<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Jobs\SyncTrafficAnalyticsJob;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\TrafficAttributionService;
use App\Domains\Integrations\TrafficProvider;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class TrafficAnalyticsSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = $this->makeWorkspace('pro', 'paid-analytics');
        $this->project = $this->makeProject($this->workspace, 'paid.example');
    }

    public function test_traffic_sync_runs_nightly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'traffic:sync'));

        $this->assertCount(1, $events);
        $this->assertSame('30 4 * * *', $events->first()->expression);
    }

    public function test_command_queues_connected_analytics_integrations_on_plans_with_attribution(): void
    {
        Queue::fake();

        $ga4 = $this->integration($this->project, 'ga4');
        $plausible = $this->integration($this->project, 'plausible');
        $this->integration($this->project, 'gsc');
        $this->integration($this->makeProject($this->workspace, 'other.example'), 'plausible', 'needs_reconnect');

        $free = $this->makeWorkspace('free', 'free-analytics');
        $this->integration($this->makeProject($free, 'free.example'), 'plausible');

        $this->artisan('traffic:sync')->assertSuccessful();

        Queue::assertPushed(SyncTrafficAnalyticsJob::class, 2);
        foreach ([$ga4, $plausible] as $integration) {
            Queue::assertPushed(SyncTrafficAnalyticsJob::class, fn (SyncTrafficAnalyticsJob $job): bool => $job->integration->is($integration));
        }
    }

    public function test_command_can_filter_by_provider_and_rejects_unknown_ones(): void
    {
        Queue::fake();

        $this->integration($this->project, 'ga4');
        $plausible = $this->integration($this->project, 'plausible');

        $this->artisan('traffic:sync', ['--provider' => 'plausible'])->assertSuccessful();
        Queue::assertPushed(SyncTrafficAnalyticsJob::class, 1);
        Queue::assertPushed(SyncTrafficAnalyticsJob::class, fn (SyncTrafficAnalyticsJob $job): bool => $job->integration->is($plausible));

        $this->artisan('traffic:sync', ['--provider' => 'gsc'])->assertExitCode(2);
    }

    public function test_job_skips_a_workspace_that_lost_traffic_attribution(): void
    {
        Http::fake();
        $integration = $this->integration($this->project, 'plausible');
        $this->workspace->applyPlanTier('free');

        (new SyncTrafficAnalyticsJob($integration->fresh() ?? $integration))->handle(app(TrafficAttributionService::class));

        Http::assertNothingSent();
    }

    public function test_job_stops_retrying_when_the_provider_rejects_the_key(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid API key'], 401)]);
        $integration = $this->integration($this->project, 'plausible');

        (new SyncTrafficAnalyticsJob($integration))->handle(app(TrafficAttributionService::class));

        $this->assertSame('needs_reconnect', $integration->fresh()?->status);
    }

    public function test_job_rethrows_other_failures_so_the_queue_retries(): void
    {
        Http::fake(['*' => Http::response('upstream down', 500)]);
        $integration = $this->integration($this->project, 'plausible');

        $this->expectException(RuntimeException::class);

        (new SyncTrafficAnalyticsJob($integration))->handle(app(TrafficAttributionService::class));
    }

    public function test_sync_integration_refuses_providers_that_are_not_analytics(): void
    {
        $this->expectException(RuntimeException::class);

        app(TrafficAttributionService::class)->syncIntegration($this->integration($this->project, 'gsc'));
    }

    public function test_a_failed_manual_sync_shows_an_error_instead_of_crashing(): void
    {
        Http::fake(['*' => Http::response('upstream down', 500)]);
        $integration = $this->integration($this->project, 'plausible');

        $this->actingAs($this->owner)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/{$integration->uuid}/sync")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Plausible Analytics sync failed'));
    }

    public function test_performance_page_lists_analytics_integrations_in_priority_order(): void
    {
        $this->integration($this->project, 'plausible');
        $this->integration($this->project, 'ga4');
        $this->integration($this->project, 'gsc');

        $this->actingAs($this->owner)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/analytics/performance")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analytics/Performance')
                ->has('integrations', 2)
                ->where('integrations.0.provider', 'ga4')
                ->where('integrations.0.label', 'GA4')
                ->where('integrations.1.provider', 'plausible')
                ->where('integrations.1.externalId', 'paid.example')
                ->where('trafficProviderLabels', ['ga4' => 'GA4', 'plausible' => 'Plausible'])
            );
    }

    public function test_provider_values_follow_report_priority(): void
    {
        $this->assertSame(['ga4', 'plausible'], TrafficProvider::values());
        $this->assertSame('Plausible Analytics', TrafficProvider::Plausible->label());
    }

    private function makeWorkspace(string $tier, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'plan_tier' => $tier,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'owner_id' => $this->owner->id,
        ]);
        $workspace->members()->attach($this->owner->id, ['role' => 'owner']);

        return $workspace;
    }

    private function makeProject(Workspace $workspace, string $domain): Project
    {
        return Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $domain,
            'domain' => $domain,
            'canonical_url' => "https://{$domain}/",
        ]);
    }

    private function integration(Project $project, string $provider, string $status = 'connected'): ProjectIntegration
    {
        $site = $project->domain;

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $project->id,
            'provider' => $provider,
            'site_url' => $site,
            'external_identifier' => $site,
            'status' => $status,
            'credentials_encrypted' => ['api_key' => 'test-key'],
        ]);

        return $integration;
    }
}
