<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Jobs\SyncTrafficAnalyticsJob;
use App\Domains\Integrations\Models\PageTrafficSnapshot;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GA4 connects through Google OAuth on the shared callback, the property is
 * matched to the project or chosen in a picker, and the Data API client keeps
 * its token fresh.
 */
class Ga4ConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REPORT_URL = 'https://analyticsdata.googleapis.com/v1beta/properties/987654321:runReport';

    private const ADMIN_URL = 'https://analyticsadmin.googleapis.com';

    /**
     * id => [name, web stream URL]. Only "Traffic Site" is trafficsite.com.
     */
    private const THREE_PROPERTIES = [
        'properties/111' => ['Blog', 'https://blog.example.org'],
        'properties/222' => ['Traffic Site', 'https://www.trafficsite.com/'],
        'properties/333' => ['Shop', null],
    ];

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
            ->post($this->connectUrl());

        $response->assertStatus(409);
        $location = (string) $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(GoogleSearchConsoleClient::ANALYTICS_SCOPE, $query['scope'] ?? null);
        $this->assertSame('offline', $query['access_type'] ?? null);
        $this->assertSame(route('integrations.google.callback'), $query['redirect_uri'] ?? null);

        $pending = session('google_oauth.'.$query['state']);
        $this->assertSame('ga4', $pending['provider'] ?? null);
        $this->assertSame($this->project->id, $pending['project_id'] ?? null);

        // Nothing is stored until Google sends the user back.
        $this->assertDatabaseMissing('project_integrations', ['provider' => 'ga4']);
    }

    public function test_the_callback_picks_the_property_whose_website_matches_the_project(): void
    {
        Queue::fake();
        $this->fakeGoogle(self::THREE_PROPERTIES);

        $this->signInCallback()
            ->assertRedirect(route('workspaces.projects.show', [$this->workspace, $this->project]))
            ->assertSessionHas('success');

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->where('provider', 'ga4')->firstOrFail();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('properties/222', $integration->external_identifier);
        $this->assertSame('Traffic Site', $integration->site_url);
        $this->assertSame('ga4-access-token', $integration->getAccessToken());
        $this->assertSame('ga4-refresh-token', $integration->getRefreshToken());

        // Search Console is a separate integration and isn't touched.
        $this->assertDatabaseMissing('project_integrations', ['provider' => 'gsc']);
        Queue::assertPushed(SyncTrafficAnalyticsJob::class, fn (SyncTrafficAnalyticsJob $job): bool => $job->integration->is($integration));
    }

    public function test_the_callback_asks_the_user_to_choose_when_no_property_matches(): void
    {
        Queue::fake();
        $this->fakeGoogle([
            'properties/111' => ['Blog', 'https://blog.example.org'],
            'properties/333' => ['Shop', null],
        ]);

        $this->signInCallback()
            ->assertRedirect(route('workspaces.projects.show', [$this->workspace, $this->project]).'?ga4_picker=1');

        $integration = ProjectIntegration::query()->where('provider', 'ga4')->sole();
        $this->assertSame('needs_property', $integration->status);
        $this->assertNull($integration->external_identifier);
        Queue::assertNothingPushed();
    }

    public function test_a_later_web_stream_counts_as_a_match(): void
    {
        Queue::fake();
        // Both properties have a trafficsite.com stream, one of them second.
        $this->fakeGoogle([
            'properties/111' => ['Network', ['https://blog.example.org', 'https://trafficsite.com']],
            'properties/222' => ['Traffic Site', 'https://www.trafficsite.com/'],
        ]);

        // Two matches: the user chooses instead of one being picked for them.
        $this->signInCallback()
            ->assertRedirect(route('workspaces.projects.show', [$this->workspace, $this->project]).'?ga4_picker=1');
        Queue::assertNothingPushed();

        $this->actingAs($this->user)
            ->getJson($this->ga4Url('properties'))
            ->assertJsonPath('properties.0.id', 'properties/111')
            ->assertJsonPath('properties.0.matches', true)
            ->assertJsonPath('properties.0.website', 'https://trafficsite.com')
            ->assertJsonPath('properties.1.matches', true);
    }

    public function test_the_callback_uses_the_only_property_on_the_account(): void
    {
        Queue::fake();
        $this->fakeGoogle(['properties/444' => ['Only One', null]]);

        $this->signInCallback()->assertSessionHas('success');

        $integration = ProjectIntegration::query()->where('provider', 'ga4')->sole();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('properties/444', $integration->external_identifier);
        Queue::assertPushed(SyncTrafficAnalyticsJob::class);
    }

    public function test_the_callback_reports_googles_error_when_properties_cant_be_listed(): void
    {
        Queue::fake();
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenResponse(), 200),
            self::ADMIN_URL.'/v1beta/accountSummaries*' => Http::response([
                'error' => ['code' => 403, 'message' => 'Google Analytics Admin API has not been used in project 123 or it is disabled.'],
            ], 403),
        ]);

        $this->signInCallback()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Admin API has not been used'));

        $this->assertSame('needs_property', ProjectIntegration::query()->where('provider', 'ga4')->sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_reconnecting_keeps_the_property_and_refresh_token(): void
    {
        Queue::fake();
        $this->ga4Integration(expiresAt: now()->subHour()->toIso8601String())->update(['status' => 'needs_reconnect']);

        // Google sends no refresh token on a repeat consent. Registered first,
        // so it wins over fakeGoogle()'s token response.
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'new-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer'], 200)]);
        $this->fakeGoogle(['properties/987654321' => ['Traffic Site', null], 'properties/111' => ['Blog', null]]);

        $this->signInCallback()
            ->assertRedirect(route('workspaces.projects.show', [$this->workspace, $this->project]))
            ->assertSessionHas('success');

        $integration = ProjectIntegration::query()->where('provider', 'ga4')->sole();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('properties/987654321', $integration->external_identifier);
        $this->assertSame('new-access-token', $integration->getAccessToken());
        $this->assertSame('ga4-refresh-token', $integration->getRefreshToken());
        Queue::assertPushed(SyncTrafficAnalyticsJob::class);
    }

    public function test_reconnecting_with_an_account_that_cant_read_the_property_asks_for_another(): void
    {
        Queue::fake();
        $this->ga4Integration(expiresAt: now()->subHour()->toIso8601String())->update(['status' => 'needs_reconnect']);
        $this->fakeGoogle([
            'properties/111' => ['Blog', 'https://blog.example.org'],
            'properties/333' => ['Shop', null],
        ]);

        $this->signInCallback()
            ->assertRedirect(route('workspaces.projects.show', [$this->workspace, $this->project]).'?ga4_picker=1');

        $this->assertSame('needs_property', ProjectIntegration::query()->where('provider', 'ga4')->sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_reconnecting_keeps_the_property_when_properties_cant_be_listed(): void
    {
        Queue::fake();
        $this->ga4Integration(expiresAt: now()->subHour()->toIso8601String())->update(['status' => 'needs_reconnect']);
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenResponse(), 200),
            self::ADMIN_URL.'/*' => Http::response(['error' => ['code' => 403, 'message' => 'Admin API disabled']], 403),
        ]);

        $this->signInCallback()->assertSessionHas('success');

        $integration = ProjectIntegration::query()->where('provider', 'ga4')->sole();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('properties/987654321', $integration->external_identifier);
        Queue::assertPushed(SyncTrafficAnalyticsJob::class);
    }

    public function test_the_picker_asks_an_api_key_integration_to_reconnect(): void
    {
        ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'site_url' => 'properties/987654321',
            'external_identifier' => 'properties/987654321',
            'status' => 'needs_reconnect',
            'credentials_encrypted' => ['api_key' => 'legacy-key'],
        ]);
        Http::fake();

        $this->actingAs($this->user)
            ->getJson($this->ga4Url('properties'))
            ->assertStatus(409)
            ->assertJsonPath('reconnect', true);

        Http::assertNothingSent();
    }

    public function test_the_callback_refuses_a_grant_without_analytics_access(): void
    {
        Queue::fake();
        Http::fake([self::TOKEN_URL => Http::response(['scope' => 'openid'] + $this->tokenResponse(), 200)]);

        $this->signInCallback()->assertSessionHas('error');

        $this->assertDatabaseMissing('project_integrations', ['provider' => 'ga4']);
        Queue::assertNothingPushed();
    }

    public function test_the_picker_lists_matching_properties_first(): void
    {
        $this->pendingIntegration();
        $this->fakeGoogle(self::THREE_PROPERTIES);

        $this->actingAs($this->user)
            ->getJson($this->ga4Url('properties'))
            ->assertOk()
            ->assertJsonPath('properties.0.id', 'properties/222')
            ->assertJsonPath('properties.0.matches', true)
            ->assertJsonPath('properties.0.website', 'https://www.trafficsite.com/')
            ->assertJsonPath('properties.1.name', 'Blog')
            ->assertJsonPath('properties.1.matches', false)
            ->assertJsonCount(3, 'properties');
    }

    public function test_the_picker_asks_for_a_reconnect_when_google_rejects_the_token(): void
    {
        $this->pendingIntegration();
        Http::fake([
            self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400),
            self::ADMIN_URL.'/*' => Http::response(['error' => ['code' => 401]], 401),
        ]);

        $this->actingAs($this->user)
            ->getJson($this->ga4Url('properties'))
            ->assertStatus(409)
            ->assertJsonPath('reconnect', true);
    }

    public function test_choosing_a_property_connects_it_and_queues_a_sync(): void
    {
        Queue::fake();
        $integration = $this->pendingIntegration();
        $this->fakeGoogle(self::THREE_PROPERTIES);

        $this->actingAs($this->user)
            ->post($this->ga4Url('property'), ['property_id' => 'properties/333'])
            ->assertSessionHas('success');

        $integration->refresh();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('properties/333', $integration->external_identifier);
        $this->assertSame('Shop', $integration->site_url);
        Queue::assertPushed(SyncTrafficAnalyticsJob::class);
    }

    public function test_switching_property_removes_the_old_propertys_traffic(): void
    {
        Queue::fake();
        $this->ga4Integration(expiresAt: now()->addHour()->toIso8601String());
        PageTrafficSnapshot::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'path' => '/',
            'path_hash' => hash('sha256', '/'),
            'date' => now()->toDateString(),
            'organic_sessions' => 10,
            'ai_referral_sessions' => 0,
            'direct_sessions' => 0,
            'total_sessions' => 10,
        ]);
        $this->fakeGoogle(self::THREE_PROPERTIES);

        $this->actingAs($this->user)
            ->post($this->ga4Url('property'), ['property_id' => 'properties/222'])
            ->assertSessionHas('success');

        $this->assertSame(0, PageTrafficSnapshot::query()->where('provider', 'ga4')->count());
    }

    public function test_refreshing_the_token_while_choosing_keeps_the_property_choice_pending(): void
    {
        $integration = $this->pendingIntegration();
        $integration->update(['credentials_encrypted' => ['refresh_token' => 'ga4-refresh-token', 'expires_at' => now()->subMinute()->toIso8601String()]]);
        $this->fakeGoogle(self::THREE_PROPERTIES);

        $this->actingAs($this->user)->getJson($this->ga4Url('properties'))->assertOk();

        $integration->refresh();
        $this->assertSame('ga4-access-token', $integration->getAccessToken());
        $this->assertSame('needs_property', $integration->status);
    }

    public function test_a_report_for_a_property_switched_away_from_mid_sync_is_discarded(): void
    {
        $integration = $this->ga4Integration(expiresAt: now()->addHour()->toIso8601String());

        Http::fake([
            self::REPORT_URL => function () use ($integration) {
                // The user picks another property while this report is in flight.
                ProjectIntegration::query()->whereKey($integration->id)->update(['external_identifier' => 'properties/222']);

                return Http::response(['rows' => [[
                    'dimensionValues' => [['value' => '/'], ['value' => 'Organic Search'], ['value' => 'google'], ['value' => now()->format('Ymd')]],
                    'metricValues' => [['value' => '50'], ['value' => '0.4'], ['value' => '60']],
                ]]], 200);
            },
        ]);

        $this->assertSame(0, app(TrafficAttributionService::class)->syncGa4($integration));
        $this->assertSame(0, PageTrafficSnapshot::query()->where('provider', 'ga4')->count());
    }

    public function test_a_property_the_account_cant_see_is_refused(): void
    {
        Queue::fake();
        $integration = $this->pendingIntegration();
        $this->fakeGoogle(self::THREE_PROPERTIES);

        $this->actingAs($this->user)
            ->post($this->ga4Url('property'), ['property_id' => 'properties/999'])
            ->assertSessionHasErrors('property_id');

        $this->assertSame('needs_property', $integration->fresh()?->status);
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
        return $this->ga4Url('connect');
    }

    private function ga4Url(string $action): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/ga4/{$action}";
    }

    private function signInCallback(): TestResponse
    {
        $state = str_repeat('g', 40);
        session(['google_oauth.'.$state => [
            'provider' => 'ga4',
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'issued_at' => now()->getTimestamp(),
        ]]);

        return $this->actingAs($this->user)
            ->get(route('integrations.google.callback', ['state' => $state, 'code' => 'auth-code']));
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenResponse(): array
    {
        return [
            'access_token' => 'ga4-access-token',
            'refresh_token' => 'ga4-refresh-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => GoogleSearchConsoleClient::ANALYTICS_SCOPE,
        ];
    }

    /**
     * Fake Google's token endpoint and the Admin API for these properties.
     *
     * @param  array<string, array{string, string|list<string>|null}>  $properties  id => [name, web stream URL(s)]
     */
    private function fakeGoogle(array $properties): void
    {
        $fakes = [
            self::TOKEN_URL => Http::response($this->tokenResponse(), 200),
            self::ADMIN_URL.'/v1beta/accountSummaries*' => Http::response([
                'accountSummaries' => [[
                    'account' => 'accounts/1',
                    'displayName' => 'Example Account',
                    'propertySummaries' => array_map(
                        fn (string $id, array $property): array => ['property' => $id, 'displayName' => $property[0], 'propertyType' => 'PROPERTY_TYPE_ORDINARY'],
                        array_keys($properties),
                        $properties,
                    ),
                ]],
            ], 200),
        ];

        foreach ($properties as $id => [, $website]) {
            $fakes[self::ADMIN_URL.'/v1beta/'.$id.'/dataStreams*'] = Http::response([
                'dataStreams' => array_map(
                    fn (string $uri): array => ['type' => 'WEB_DATA_STREAM', 'webStreamData' => ['defaultUri' => $uri]],
                    $website === null ? [] : (array) $website,
                ),
            ], 200);
        }

        Http::fake($fakes);
    }

    /**
     * Signed in to Google, property not chosen yet.
     */
    private function pendingIntegration(): ProjectIntegration
    {
        /** @var ProjectIntegration */
        return ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'status' => 'needs_property',
            'credentials_encrypted' => [
                'access_token' => 'ga4-access-token',
                'refresh_token' => 'ga4-refresh-token',
                'token_type' => 'Bearer',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
        ]);
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
