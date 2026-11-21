<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Jobs\SyncBingWebmasterJob;
use App\Domains\Integrations\Jobs\SyncGoogleSearchConsoleJob;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Models\SearchPerformanceQuery;
use App\Domains\Integrations\Services\SearchPerformanceSyncService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\User;
use App\Support\PageUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchConsoleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'mock-google-client-id');
        config()->set('services.google.client_secret', 'mock-google-client-secret');
        config()->set('services.google.search_console_base_url', 'https://searchconsole.googleapis.com');
        config()->set('services.bing.base_url', 'https://ssl.bing.com/webmaster/api.svc/json');

        $this->user = User::factory()->create();

        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => 'SEO Agency',
            'slug' => 'seo-agency',
            'owner_id' => $this->user->id,
        ]);

        $this->workspace->members()->attach($this->user, ['role' => 'owner']);

        $this->project = $this->workspace->projects()->create([
            'name' => 'Acme SaaS',
            'domain' => 'acmesaas.com',
            'canonical_url' => 'https://acmesaas.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'crawl_cadence' => 'weekly',
        ]);
    }

    public function test_guest_cannot_access_integration_routes(): void
    {
        $this->get(route('workspaces.projects.integrations.google.connect', [$this->workspace, $this->project]))
            ->assertRedirect(route('login'));

        $this->post(route('workspaces.projects.integrations.bing.connect', [$this->workspace, $this->project]), [
            'api_key' => 'valid-key',
        ])->assertRedirect(route('login'));
    }

    public function test_google_oauth_redirect_generates_valid_url_and_sets_session_state(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('workspaces.projects.integrations.google.connect', [$this->workspace, $this->project]));

        $response->assertRedirect();
        $targetUrl = (string) $response->headers->get('Location');

        $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $targetUrl);
        $this->assertStringContainsString('client_id=mock-google-client-id', $targetUrl);
        $this->assertStringContainsString('access_type=offline', $targetUrl);
        $this->assertStringContainsString('prompt=consent', $targetUrl);

        parse_str((string) parse_url($targetUrl, PHP_URL_QUERY), $query);
        $state = (string) ($query['state'] ?? '');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $state);
        $this->assertSame($this->project->id, session('google_oauth.'.$state)['project_id'] ?? null);
        $this->assertSame(route('integrations.google.callback'), $query['redirect_uri'] ?? null);
    }

    public function test_google_oauth_callback_exchanges_tokens_and_dispatches_sync(): void
    {
        Queue::fake();

        $sessionState = str_repeat('a', 40);
        session(['google_oauth.'.$sessionState => [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'issued_at' => now()->getTimestamp(),
        ]]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-google-access-token',
                'refresh_token' => 'mock-google-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://acmesaas.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('integrations.google.callback', [
                'state' => $sessionState,
                'code' => 'mock-auth-code-123',
            ]));

        $response->assertRedirect(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]));
        $response->assertSessionHas('success');

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->where('project_id', $this->project->id)->where('provider', 'gsc')->firstOrFail();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('https://acmesaas.com/', $integration->site_url);
        $this->assertSame('mock-google-access-token', $integration->getAccessToken());
        $this->assertSame('mock-google-refresh-token', $integration->getRefreshToken());

        Queue::assertPushed(SyncGoogleSearchConsoleJob::class, function (SyncGoogleSearchConsoleJob $job) use ($integration): bool {
            return $job->integration->id === $integration->id;
        });

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'integration.connected',
        ]);
    }

    public function test_google_oauth_callback_fails_on_state_mismatch(): void
    {
        Queue::fake();

        session(['google_oauth.'.str_repeat('a', 40) => [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'issued_at' => now()->getTimestamp(),
        ]]);

        $response = $this->actingAs($this->user)
            ->get(route('integrations.google.callback', [
                'state' => str_repeat('b', 40),
                'code' => 'mock-code',
            ]));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('project_integrations', [
            'project_id' => $this->project->id,
            'provider' => 'gsc',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_connect_bing_webmaster_creates_integration_and_dispatches_sync(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post(route('workspaces.projects.integrations.bing.connect', [$this->workspace, $this->project]), [
                'api_key' => 'mock-bing-api-key-999',
                'site_url' => 'https://acmesaas.com/',
            ]);

        $response->assertRedirect(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]));
        $response->assertSessionHas('success');

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->where('project_id', $this->project->id)->where('provider', 'bing')->firstOrFail();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('https://acmesaas.com/', $integration->site_url);

        /** @var array<string, mixed> $creds */
        $creds = $integration->credentials_encrypted;
        $this->assertSame('mock-bing-api-key-999', $creds['api_key']);

        Queue::assertPushed(SyncBingWebmasterJob::class, function (SyncBingWebmasterJob $job) use ($integration): bool {
            return $job->integration->id === $integration->id;
        });
    }

    public function test_search_performance_sync_service_ingests_gsc_data_and_handles_upserts(): void
    {
        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'site_url' => 'https://acmesaas.com/',
            'external_identifier' => 'https://acmesaas.com/',
            'status' => 'connected',
            'credentials_encrypted' => [
                'access_token' => 'active-access-token',
                'refresh_token' => 'active-refresh-token',
                'expires_at' => Carbon::now()->addHour()->toIso8601String(),
            ],
        ]);

        $mockGscRows = [
            [
                'keys' => ['best saas tool', '2026-11-14'],
                'clicks' => 45,
                'impressions' => 500,
                'ctr' => 0.09,
                'position' => 3.2,
            ],
            [
                'keys' => ['seo automation', '2026-11-14'],
                'clicks' => 12,
                'impressions' => 200,
                'ctr' => 0.06,
                'position' => 5.1,
            ],
        ];

        Http::fake([
            'https://searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query*' => fn (Request $request) => Http::response([
                'rows' => $request['dimensions'] === ['page', 'date']
                    ? [['keys' => ['https://acmesaas.com/pricing/', '2026-11-14'], 'clicks' => 30, 'impressions' => 400, 'ctr' => 0.075, 'position' => 2.5]]
                    : $mockGscRows,
            ], 200),
        ]);

        /** @var SearchPerformanceSyncService $syncService */
        $syncService = app(SearchPerformanceSyncService::class);
        $result = $syncService->syncGoogleSearchConsole($integration);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['records_synced']);
        $this->assertSame(1, $result['pages_synced'] ?? null);

        // Page rows are keyed by normalized path, so "/pricing/" joins "/pricing".
        $this->assertDatabaseHas('search_performance_pages', [
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'url_hash' => PageUrl::hash('/pricing'),
            'date' => '2026-11-14',
            'clicks' => 30,
        ]);

        $this->assertDatabaseHas('search_performance_queries', [
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'query' => 'best saas tool',
            'date' => '2026-11-14',
            'clicks' => 45,
            'impressions' => 500,
        ]);

        $this->assertDatabaseHas('search_performance_queries', [
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'query' => 'seo automation',
            'date' => '2026-11-14',
            'clicks' => 12,
            'impressions' => 200,
        ]);
    }

    public function test_sync_service_transitions_to_needs_reconnect_on_revocation(): void
    {
        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'site_url' => 'https://acmesaas.com/',
            'external_identifier' => 'https://acmesaas.com/',
            'status' => 'connected',
            'credentials_encrypted' => [
                'access_token' => 'expired-token',
                'refresh_token' => 'revoked-refresh-token',
                'expires_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            ],
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        /** @var SearchPerformanceSyncService $syncService */
        $syncService = app(SearchPerformanceSyncService::class);
        $result = $syncService->syncGoogleSearchConsole($integration);

        $this->assertFalse($result['success']);

        $integration->refresh();
        $this->assertSame('needs_reconnect', $integration->status);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'integration.error',
            'title' => 'Google Search Console Authorization Expired',
        ]);
    }

    public function test_rank_tracker_blends_gsc_first_party_overlay_into_keywords_response(): void
    {
        /** @var TrackedKeyword $keyword */
        $keyword = $this->project->trackedKeywords()->create([
            'keyword' => 'cloud rank tracking',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
        ]);

        ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'site_url' => 'https://acmesaas.com/',
            'status' => 'connected',
            'credentials_encrypted' => ['access_token' => 'tok'],
        ]);

        // Seed 28d performance query
        SearchPerformanceQuery::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'query' => 'cloud rank tracking',
            'date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'clicks' => 35,
            'impressions' => 350,
            'ctr' => 0.10,
            'position' => 4.0,
        ]);

        $this->actingAs($this->user)
            ->get(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Keywords/Index')
                ->where('gscIntegration.status', 'connected')
                ->has('keywords', 1)
                ->where('keywords.0.keyword', 'cloud rank tracking')
                ->where('keywords.0.gsc_performance.clicks', 35)
                ->where('keywords.0.gsc_performance.impressions', 350)
                ->where('keywords.0.gsc_performance.ctr', 10)
                ->where('keywords.0.gsc_performance.position', 4)
            );
    }

    public function test_artisan_sync_search_performance_command(): void
    {
        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'site_url' => 'https://acmesaas.com/',
            'external_identifier' => 'https://acmesaas.com/',
            'status' => 'connected',
            'credentials_encrypted' => [
                'access_token' => 'tok',
                'refresh_token' => 'ref',
                'expires_at' => Carbon::now()->addHour()->toIso8601String(),
            ],
        ]);

        Http::fake([
            'https://searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query*' => Http::response([
                'rows' => [
                    [
                        'keys' => ['artisan test query', '2026-11-12'],
                        'clicks' => 10,
                        'impressions' => 100,
                        'ctr' => 0.10,
                        'position' => 2.5,
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('search-console:sync', [
            '--project' => $this->project->id,
            '--provider' => 'gsc',
            '--now' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('search_performance_queries', [
            'project_id' => $this->project->id,
            'query' => 'artisan test query',
            'clicks' => 10,
        ]);
    }

    public function test_disconnect_integration_deletes_record_and_logs_activity(): void
    {
        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'site_url' => 'https://acmesaas.com/',
            'status' => 'connected',
            'credentials_encrypted' => ['access_token' => 'tok'],
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('workspaces.projects.integrations.destroy', [
                'workspace' => $this->workspace,
                'project' => $this->project,
                'integration' => $integration,
            ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('project_integrations', [
            'id' => $integration->id,
        ]);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'integration.disconnected',
        ]);
    }

    public function test_google_redirect_uri_is_identical_for_every_project(): void
    {
        $second = $this->workspace->projects()->create([
            'name' => 'Second Site',
            'domain' => 'second-site.com',
            'canonical_url' => 'https://second-site.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'crawl_cadence' => 'weekly',
        ]);

        $redirectUris = [];
        foreach ([$this->project, $second] as $project) {
            $location = (string) $this->actingAs($this->user)
                ->get(route('workspaces.projects.integrations.google.connect', [$this->workspace, $project]))
                ->headers->get('Location');
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            $redirectUris[] = $query['redirect_uri'] ?? null;

            $this->assertSame($project->id, session('google_oauth.'.$query['state'])['project_id'] ?? null);
        }

        $this->assertSame([url('/integrations/google/callback'), url('/integrations/google/callback')], $redirectUris);
    }

    public function test_google_callback_exchanges_code_with_the_same_fixed_redirect_uri(): void
    {
        Queue::fake();
        config()->set('services.google.redirect_uri', 'https://app.coquiseo.com/integrations/google/callback');

        $state = str_repeat('c', 40);
        session(['google_oauth.'.$state => [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'issued_at' => now()->getTimestamp(),
        ]]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ], 200),
            'https://searchconsole.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
        ]);

        $this->actingAs($this->user)
            ->get(route('integrations.google.callback', ['state' => $state, 'code' => 'abc']))
            ->assertRedirect(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && ($request->data()['redirect_uri'] ?? null) === 'https://app.coquiseo.com/integrations/google/callback');

        // State is single-use.
        $this->assertNull(session('google_oauth.'.$state));
    }

    public function test_google_callback_rejects_expired_state(): void
    {
        Queue::fake();
        Http::fake();

        $state = str_repeat('d', 40);
        session(['google_oauth.'.$state => [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'issued_at' => now()->subHour()->getTimestamp(),
        ]]);

        $this->actingAs($this->user)
            ->get(route('integrations.google.callback', ['state' => $state, 'code' => 'abc']))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('project_integrations', ['project_id' => $this->project->id, 'provider' => 'gsc']);
    }
}
