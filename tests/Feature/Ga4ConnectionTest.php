<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Jobs\SyncTrafficAnalyticsJob;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\GoogleSearchConsoleClient;
use App\Domains\Integrations\Services\SearchConsoleAuthException;
use App\Domains\Integrations\Services\TrafficAttributionService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GA4 connects through Google OAuth on the shared callback, and the Data API
 * client keeps its token fresh.
 */
class Ga4ConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REPORT_URL = 'https://analyticsdata.googleapis.com/v1beta/properties/987654321:runReport';

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'mock-google-client-id');
        config()->set('services.google.client_secret', 'mock-google-client-secret');

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => 'Analytics Workspace',
            'slug' => 'analytics-workspace',
            'owner_id' => $this->user->id,
        ]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Traffic Site',
            'domain' => 'trafficsite.com',
            'canonical_url' => 'https://trafficsite.com/',
        ]);
    }

    public function test_connecting_sends_the_user_to_google_for_analytics_access(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeader('X-Inertia', 'true')
            ->post($this->connectUrl(), ['property_id' => ' properties/987654321 ']);

        $response->assertStatus(409);
        $location = (string) $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(GoogleSearchConsoleClient::ANALYTICS_SCOPE, $query['scope'] ?? null);
        $this->assertSame('offline', $query['access_type'] ?? null);
        $this->assertSame(route('integrations.google.callback'), $query['redirect_uri'] ?? null);

        $pending = session('google_oauth.'.$query['state']);
        $this->assertSame('ga4', $pending['provider'] ?? null);
        $this->assertSame('properties/987654321', $pending['property_id'] ?? null);
        $this->assertSame($this->project->id, $pending['project_id'] ?? null);

        // Nothing is stored until Google sends the user back.
        $this->assertDatabaseMissing('project_integrations', ['provider' => 'ga4']);
    }

    public function test_a_property_id_that_isnt_numeric_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post($this->connectUrl(), ['property_id' => 'G-ABC123XYZ'])
            ->assertSessionHasErrors('property_id');
    }

    public function test_the_callback_stores_tokens_with_the_property_and_queues_a_sync(): void
    {
        Queue::fake();
        $state = $this->pendingGa4State();

        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'ga4-access-token',
                'refresh_token' => 'ga4-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => GoogleSearchConsoleClient::ANALYTICS_SCOPE,
            ], 200),
        ]);

        $this->actingAs($this->user)
            ->get(route('integrations.google.callback', ['state' => $state, 'code' => 'auth-code']))
            ->assertRedirect(route('workspaces.projects.show', [$this->workspace, $this->project]))
            ->assertSessionHas('success');

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->where('provider', 'ga4')->firstOrFail();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('properties/987654321', $integration->external_identifier);
        $this->assertSame('ga4-access-token', $integration->getAccessToken());
        $this->assertSame('ga4-refresh-token', $integration->getRefreshToken());

        // Search Console is a separate integration and isn't touched.
        $this->assertDatabaseMissing('project_integrations', ['provider' => 'gsc']);
        Queue::assertPushed(SyncTrafficAnalyticsJob::class, fn (SyncTrafficAnalyticsJob $job): bool => $job->integration->is($integration));
    }

    public function test_reconnecting_keeps_the_refresh_token_when_google_sends_none(): void
    {
        Queue::fake();
        ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'site_url' => 'properties/987654321',
            'external_identifier' => 'properties/987654321',
            'status' => 'needs_reconnect',
            'credentials_encrypted' => ['access_token' => 'old', 'refresh_token' => 'kept-refresh-token', 'expires_at' => now()->subHour()->toIso8601String()],
        ]);
        $state = $this->pendingGa4State();

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'new-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer'], 200),
        ]);

        $this->actingAs($this->user)
            ->get(route('integrations.google.callback', ['state' => $state, 'code' => 'auth-code']))
            ->assertSessionHas('success');

        $integration = ProjectIntegration::query()->where('provider', 'ga4')->sole();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('new-access-token', $integration->getAccessToken());
        $this->assertSame('kept-refresh-token', $integration->getRefreshToken());
    }

    public function test_the_callback_refuses_a_grant_without_analytics_access(): void
    {
        Queue::fake();
        $state = $this->pendingGa4State();

        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'ga4-access-token',
                'refresh_token' => 'ga4-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'openid',
            ], 200),
        ]);

        $this->actingAs($this->user)
            ->get(route('integrations.google.callback', ['state' => $state, 'code' => 'auth-code']))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('project_integrations', ['provider' => 'ga4']);
        Queue::assertNothingPushed();
    }

    public function test_an_expired_token_is_refreshed_before_the_report(): void
    {
        $integration = $this->ga4Integration(expiresAt: now()->subMinute()->toIso8601String());

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600], 200),
            self::REPORT_URL => Http::response(['rows' => []], 200),
        ]);

        app(TrafficAttributionService::class)->syncGa4($integration);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::REPORT_URL
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
        $this->assertSame('fresh-token', $integration->fresh()?->getAccessToken());
        $this->assertSame('connected', $integration->fresh()?->status);
    }

    public function test_a_rejected_token_is_refreshed_once_and_the_report_retried(): void
    {
        $integration = $this->ga4Integration(expiresAt: now()->addHour()->toIso8601String());

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600], 200),
            self::REPORT_URL => Http::sequence()
                ->push(['error' => ['code' => 401]], 401)
                ->push(['rows' => []], 200),
        ]);

        app(TrafficAttributionService::class)->syncGa4($integration);

        $reports = Http::recorded(fn (HttpRequest $request): bool => $request->url() === self::REPORT_URL)->values();
        $this->assertCount(2, $reports);
        $this->assertTrue($reports[1][0]->hasHeader('Authorization', 'Bearer fresh-token'));
        $this->assertSame('connected', $integration->fresh()?->status);
    }

    public function test_no_access_to_the_property_asks_for_a_reconnect(): void
    {
        $integration = $this->ga4Integration(expiresAt: now()->addHour()->toIso8601String());

        Http::fake([self::REPORT_URL => Http::response(['error' => ['code' => 403]], 403)]);

        try {
            app(TrafficAttributionService::class)->syncGa4($integration);
            $this->fail('Expected an auth exception.');
        } catch (SearchConsoleAuthException) {
        }

        $this->assertSame('needs_reconnect', $integration->fresh()?->status);
    }

    public function test_an_integration_saved_with_only_an_api_key_asks_for_a_reconnect(): void
    {
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'site_url' => 'properties/987654321',
            'external_identifier' => 'properties/987654321',
            'status' => 'connected',
            'credentials_encrypted' => ['api_key' => 'legacy-key'],
        ]);
        Http::fake();

        try {
            app(TrafficAttributionService::class)->syncGa4($integration);
            $this->fail('Expected an auth exception.');
        } catch (SearchConsoleAuthException) {
        }

        $this->assertSame('needs_reconnect', $integration->fresh()?->status);
        Http::assertNothingSent();
    }

    private function connectUrl(): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/ga4/connect";
    }

    private function pendingGa4State(): string
    {
        $state = str_repeat('g', 40);
        session(['google_oauth.'.$state => [
            'provider' => 'ga4',
            'property_id' => 'properties/987654321',
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'issued_at' => now()->getTimestamp(),
        ]]);

        return $state;
    }

    private function ga4Integration(string $expiresAt): ProjectIntegration
    {
        /** @var ProjectIntegration */
        return ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'site_url' => 'properties/987654321',
            'external_identifier' => 'properties/987654321',
            'status' => 'connected',
            'credentials_encrypted' => [
                'access_token' => 'stale-token',
                'refresh_token' => 'ga4-refresh-token',
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
            ],
        ]);
    }
}
