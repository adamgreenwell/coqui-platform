<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Models\CrawlUrl;
use App\Domains\Integrations\Models\PageTrafficSnapshot;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Models\SearchPerformancePage;
use App\Domains\Integrations\Services\TrafficAttributionService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrafficAttributionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    private TrafficAttributionService $trafficService;

    /**
     * A GA4 integration connected through Google OAuth, token still valid.
     */
    private const GA4_TOKENS = [
        'access_token' => 'ga4-access-token',
        'refresh_token' => 'ga4-refresh-token',
        'token_type' => 'Bearer',
        'expires_at' => '2099-01-01T00:00:00+00:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->trafficService = app(TrafficAttributionService::class);
    }

    public function test_can_connect_plausible_integration_and_store_credentials(): void
    {
        Http::fake([
            'https://plausible.io/*' => Http::response(['results' => []], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/plausible/connect", [
                'site_id' => 'trafficsite.com',
                'api_key' => 'plausible-test-token',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('project_integrations', [
            'project_id' => $this->project->id,
            'provider' => 'plausible',
            'external_identifier' => 'trafficsite.com',
            'status' => 'connected',
        ]);
    }

    public function test_sync_ga4_queries_api_and_persists_traffic_snapshots_with_ai_referral_breakdown(): void
    {
        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'site_url' => 'properties/123456789',
            'external_identifier' => 'properties/123456789',
            'status' => 'connected',
            'credentials_encrypted' => self::GA4_TOKENS,
        ]);

        $today = Carbon::now()->format('Ymd');
        $expectedDate = Carbon::now()->format('Y-m-d');

        Http::fake([
            'https://analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [
                    [
                        'dimensionValues' => [
                            ['value' => '/'],
                            ['value' => 'Organic Search'],
                            ['value' => 'google'],
                            ['value' => $today],
                        ],
                        'metricValues' => [
                            ['value' => '250'],
                            ['value' => '0.35'],
                            ['value' => '75'],
                        ],
                    ],
                    [
                        'dimensionValues' => [
                            ['value' => '/docs/api'],
                            ['value' => 'Referral'],
                            ['value' => 'chatgpt.com'],
                            ['value' => $today],
                        ],
                        'metricValues' => [
                            ['value' => '45'],
                            ['value' => '0.20'],
                            ['value' => '120'],
                        ],
                    ],
                    [
                        'dimensionValues' => [
                            ['value' => '/docs/api'],
                            ['value' => 'Referral'],
                            ['value' => 'perplexity.ai'],
                            ['value' => $today],
                        ],
                        'metricValues' => [
                            ['value' => '20'],
                            ['value' => '0.25'],
                            ['value' => '95'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $count = $this->trafficService->syncGa4($integration, 30);
        $this->assertEquals(2, $count);

        // Verify root path
        $rootSnapshot = PageTrafficSnapshot::query()
            ->where('project_id', $this->project->id)
            ->where('provider', 'ga4')
            ->where('path', '/')
            ->first();

        $this->assertNotNull($rootSnapshot);
        $this->assertEquals(250, $rootSnapshot->organic_sessions);
        $this->assertEquals(0, $rootSnapshot->ai_referral_sessions);

        // Verify /docs/api AI assistant grouping
        $docsSnapshot = PageTrafficSnapshot::query()
            ->where('project_id', $this->project->id)
            ->where('provider', 'ga4')
            ->where('path', '/docs/api')
            ->first();

        $this->assertNotNull($docsSnapshot);
        $this->assertEquals(65, $docsSnapshot->ai_referral_sessions);
        $this->assertEquals([
            'ChatGPT' => 45,
            'Perplexity' => 20,
        ], $docsSnapshot->ai_referrals_breakdown);
    }

    private function plausibleIntegration(): ProjectIntegration
    {
        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'plausible',
            'site_url' => 'trafficsite.com',
            'external_identifier' => 'trafficsite.com',
            'status' => 'connected',
            'credentials_encrypted' => [
                'api_key' => 'plausible-token',
            ],
        ]);

        return $integration;
    }

    /**
     * Fakes Plausible's v1 breakdown API from per-day data; days not listed
     * have no traffic.
     *
     * @param  array<string, array{pages: list<array<string, mixed>>, sources: list<array<string, mixed>>}>  $days
     */
    private function fakePlausibleDays(array $days): void
    {
        Http::fake(function (HttpRequest $request) use ($days) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $day = $days[(string) ($query['date'] ?? '')] ?? ['pages' => [], 'sources' => []];

            return Http::response([
                'results' => ($query['property'] ?? '') === 'event:page' ? $day['pages'] : $day['sources'],
            ], 200);
        });
    }

    public function test_sync_plausible_stores_one_row_per_page_per_day(): void
    {
        $integration = $this->plausibleIntegration();
        $yesterday = Carbon::today()->subDay()->format('Y-m-d');
        $today = Carbon::today()->format('Y-m-d');

        $this->fakePlausibleDays([
            $yesterday => [
                'pages' => [['page' => '/', 'visitors' => 100, 'pageviews' => 150, 'bounce_rate' => 40.0, 'visit_duration' => 60]],
                'sources' => [
                    ['source' => 'Google', 'visitors' => 50],
                    ['source' => 'ChatGPT', 'visitors' => 10],
                    ['source' => 'Direct / None', 'visitors' => 40],
                ],
            ],
            $today => [
                'pages' => [
                    ['page' => '/', 'visitors' => 200, 'pageviews' => 300, 'bounce_rate' => 50.0, 'visit_duration' => 45],
                    ['page' => '/features', 'visitors' => 20, 'pageviews' => 25, 'bounce_rate' => 30.0, 'visit_duration' => 90],
                ],
                'sources' => [['source' => 'Google', 'visitors' => 220]],
            ],
        ]);

        $this->assertSame(3, $this->trafficService->syncPlausible($integration, 30));

        $home = PageTrafficSnapshot::query()->where('provider', 'plausible')->where('path', '/')->orderBy('date')->get();
        $this->assertCount(2, $home);
        $this->assertSame($yesterday, $home[0]->date->format('Y-m-d'));
        $this->assertSame(100, $home[0]->total_sessions);
        $this->assertSame(50, $home[0]->organic_sessions);
        $this->assertSame(10, $home[0]->ai_referral_sessions);
        $this->assertSame(['ChatGPT' => 10], $home[0]->ai_referrals_breakdown);
        $this->assertSame(200, $home[1]->total_sessions);
        $this->assertSame(200, $home[1]->organic_sessions);
        $this->assertSame(0, $home[1]->ai_referral_sessions);
    }

    public function test_repeated_plausible_syncs_do_not_inflate_the_report(): void
    {
        $integration = $this->plausibleIntegration();
        $today = Carbon::today()->format('Y-m-d');

        $this->fakePlausibleDays([
            $today => [
                'pages' => [['page' => '/', 'visitors' => 200, 'pageviews' => 300, 'bounce_rate' => 50.0, 'visit_duration' => 45]],
                'sources' => [['source' => 'Google', 'visitors' => 200]],
            ],
        ]);

        $this->trafficService->syncPlausible($integration, 30);
        $this->trafficService->syncPlausible($integration->refresh(), 30);
        $this->trafficService->syncPlausible($integration->refresh(), 30);

        $report = $this->trafficService->getPagePerformanceReport($this->project, 30);
        $this->assertSame(200, $report['kpis']['total_organic_sessions']);
        $this->assertSame(200, collect($report['pages'])->firstWhere('path', '/')['total_sessions']);
    }

    public function test_later_plausible_syncs_only_fetch_recent_days(): void
    {
        $integration = $this->plausibleIntegration();
        $this->fakePlausibleDays([]);

        $this->trafficService->syncPlausible($integration, 30);
        // 31 days (today and the 30 before it), one page and one source call each.
        Http::assertSentCount(62);

        $this->fakePlausibleDays([]);
        $this->trafficService->syncPlausible($integration->refresh(), 30);
        // Only the last sync day and the two before it are fetched again.
        Http::assertSentCount(6);
    }

    public function test_a_resynced_day_drops_pages_that_no_longer_have_visits(): void
    {
        $integration = $this->plausibleIntegration();
        $today = Carbon::today()->format('Y-m-d');

        PageTrafficSnapshot::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'plausible',
            'path' => '/old',
            'path_hash' => hash('sha256', '/old'),
            'date' => $today,
            'organic_sessions' => 900,
            'ai_referral_sessions' => 0,
            'direct_sessions' => 0,
            'total_sessions' => 900,
            'bounce_rate' => 0,
            'avg_engagement_time_sec' => 0,
            'ai_referrals_breakdown' => [],
        ]);

        $this->fakePlausibleDays([
            $today => [
                'pages' => [['page' => '/', 'visitors' => 10, 'pageviews' => 10, 'bounce_rate' => 0.0, 'visit_duration' => 0]],
                'sources' => [['source' => 'Google', 'visitors' => 10]],
            ],
        ]);

        $this->trafficService->syncPlausible($integration, 30);

        $this->assertDatabaseMissing('page_traffic_snapshots', ['provider' => 'plausible', 'path' => '/old']);
    }

    public function test_the_report_uses_one_provider_when_ga4_and_plausible_both_have_data(): void
    {
        foreach (['ga4' => 120, 'plausible' => 100] as $provider => $sessions) {
            PageTrafficSnapshot::query()->create([
                'project_id' => $this->project->id,
                'provider' => $provider,
                'path' => '/pricing',
                'path_hash' => hash('sha256', '/pricing'),
                'date' => Carbon::today()->subDay()->format('Y-m-d'),
                'organic_sessions' => $sessions,
                'ai_referral_sessions' => 5,
                'direct_sessions' => 0,
                'total_sessions' => $sessions,
                'bounce_rate' => 40.0,
                'avg_engagement_time_sec' => 30,
                'ai_referrals_breakdown' => ['ChatGPT' => 5],
            ]);
        }

        $report = $this->trafficService->getPagePerformanceReport($this->project, 30);

        $this->assertSame('ga4', $report['kpis']['traffic_provider']);
        $this->assertSame(120, $report['kpis']['total_organic_sessions']);
        $this->assertSame(5, $report['kpis']['total_ai_referral_sessions']);
        $this->assertSame(120, collect($report['pages'])->firstWhere('path', '/pricing')['total_sessions']);
    }

    public function test_the_report_falls_back_to_plausible_when_ga4_has_no_data_in_the_window(): void
    {
        PageTrafficSnapshot::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'plausible',
            'path' => '/',
            'path_hash' => hash('sha256', '/'),
            'date' => Carbon::today()->format('Y-m-d'),
            'organic_sessions' => 40,
            'ai_referral_sessions' => 0,
            'direct_sessions' => 0,
            'total_sessions' => 40,
            'bounce_rate' => 0,
            'avg_engagement_time_sec' => 0,
            'ai_referrals_breakdown' => [],
        ]);

        $report = $this->trafficService->getPagePerformanceReport($this->project, 30);

        $this->assertSame('plausible', $report['kpis']['traffic_provider']);
        $this->assertSame(40, $report['kpis']['total_organic_sessions']);
    }

    public function test_the_report_ignores_snapshots_from_a_removed_ga4_integration(): void
    {
        $this->plausibleIntegration();

        foreach (['ga4' => 120, 'plausible' => 100] as $provider => $sessions) {
            PageTrafficSnapshot::query()->create([
                'project_id' => $this->project->id,
                'provider' => $provider,
                'path' => '/pricing',
                'path_hash' => hash('sha256', '/pricing'),
                'date' => Carbon::today()->subDay()->format('Y-m-d'),
                'organic_sessions' => $sessions,
                'ai_referral_sessions' => 0,
                'direct_sessions' => 0,
                'total_sessions' => $sessions,
                'bounce_rate' => 0,
                'avg_engagement_time_sec' => 0,
                'ai_referrals_breakdown' => [],
            ]);
        }

        // GA4's integration was removed, but its snapshots are still stored.
        $report = $this->trafficService->getPagePerformanceReport($this->project, 30);

        $this->assertSame('plausible', $report['kpis']['traffic_provider']);
        $this->assertSame(100, $report['kpis']['total_organic_sessions']);
    }

    public function test_ga4_uses_the_configured_api_base_url(): void
    {
        config()->set('services.google.ga4_base_url', 'https://ga4-proxy.test');

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'site_url' => 'properties/123456789',
            'external_identifier' => 'properties/123456789',
            'status' => 'connected',
            'credentials_encrypted' => self::GA4_TOKENS,
        ]);

        Http::fake(['https://ga4-proxy.test/*' => Http::response(['rows' => []], 200)]);

        $this->trafficService->syncGa4($integration, 30);

        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://ga4-proxy.test/v1beta/properties/123456789:runReport'));
    }

    public function test_page_performance_report_joins_crawl_urls_gsc_and_analytics_traffic(): void
    {
        // 1. Crawl URLs
        /** @var CrawlRun $crawlRun */
        $crawlRun = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'external_crawl_id' => 'crawl_perf_123',
            'status' => 'completed',
            'source' => 'desktop_sync',
            'seed' => 'https://trafficsite.com/',
            'total_urls' => 2,
            'created_at' => Carbon::now(),
            'finalized_at' => Carbon::now(),
            'config' => [],
        ]);

        CrawlUrl::query()->create([
            'crawl_run_id' => $crawlRun->id,
            'client_row_id' => 'row_1',
            'url' => 'https://trafficsite.com/broken-page',
            'url_hash' => hash('sha256', 'https://trafficsite.com/broken-page'),
            'status' => 404,
            'title' => 'Page Not Found',
        ]);

        CrawlUrl::query()->create([
            'crawl_run_id' => $crawlRun->id,
            'client_row_id' => 'row_2',
            'url' => 'https://trafficsite.com/docs',
            'url_hash' => hash('sha256', 'https://trafficsite.com/docs'),
            'status' => 200,
            'title' => 'API Docs',
            'h1_count' => 1,
        ]);

        // 2. Search Performance Pages (GSC)
        SearchPerformancePage::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'url' => 'https://trafficsite.com/docs/',
            'url_hash' => hash('sha256', 'https://trafficsite.com/docs/'),
            'date' => Carbon::now()->subDays(2)->format('Y-m-d'),
            'clicks' => 120,
            'impressions' => 1500,
            'ctr' => 0.08,
            'position' => 3.2,
        ]);

        // 3. Traffic Attribution Snapshots (GA4)
        PageTrafficSnapshot::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'path' => '/docs',
            'path_hash' => hash('sha256', '/docs'),
            'date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'organic_sessions' => 250,
            'ai_referral_sessions' => 65,
            'direct_sessions' => 30,
            'total_sessions' => 345,
            'bounce_rate' => 28.5,
            'avg_engagement_time_sec' => 110,
            'ai_referrals_breakdown' => ['ChatGPT' => 45, 'Perplexity' => 20],
        ]);

        // Broken page getting traffic (High Traffic At Risk!)
        PageTrafficSnapshot::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'path' => '/broken-page',
            'path_hash' => hash('sha256', '/broken-page'),
            'date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'organic_sessions' => 0,
            'ai_referral_sessions' => 18,
            'direct_sessions' => 5,
            'total_sessions' => 23,
            'bounce_rate' => 95.0,
            'avg_engagement_time_sec' => 5,
            'ai_referrals_breakdown' => ['Claude' => 18],
        ]);

        $report = $this->trafficService->getPagePerformanceReport($this->project, 30);

        // Verify KPIs
        $this->assertEquals(250, $report['kpis']['total_organic_sessions']);
        $this->assertEquals(83, $report['kpis']['total_ai_referral_sessions']);
        $this->assertEquals(120, $report['kpis']['total_gsc_clicks']);
        $this->assertEquals(1, $report['kpis']['at_risk_pages_count']);
        $this->assertEquals('ChatGPT', $report['kpis']['top_ai_assistant']);

        // Verify Pages Join
        $atRiskPage = collect($report['pages'])->firstWhere('path', '/broken-page');
        $this->assertNotNull($atRiskPage);
        $this->assertTrue($atRiskPage['is_at_risk']);
        $this->assertEquals(404, $atRiskPage['crawl_status']);
        $this->assertContains('HTTP 404 Client Error', $atRiskPage['issues']);
        $this->assertEquals(18, $atRiskPage['ai_referral_sessions']);

        $docsPage = collect($report['pages'])->firstWhere('path', '/docs');
        $this->assertNotNull($docsPage);
        $this->assertFalse($docsPage['is_at_risk']);
        $this->assertEquals(120, $docsPage['gsc_clicks']);
        $this->assertEquals(250, $docsPage['organic_sessions']);
        $this->assertEquals(65, $docsPage['ai_referral_sessions']);
    }

    public function test_performance_web_route_renders_inertia_view_with_report(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/analytics/performance?days=30");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Performance')
            ->has('workspace')
            ->has('project')
            ->has('kpis')
            ->has('pages')
            ->has('integrations')
            ->where('days', 30)
            ->where('canEdit', true)
        );
    }

    public function test_manual_traffic_sync_web_action(): void
    {
        ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'site_url' => 'properties/123456789',
            'external_identifier' => 'properties/123456789',
            'status' => 'connected',
            'credentials_encrypted' => self::GA4_TOKENS,
        ]);

        Http::fake([
            'https://analyticsdata.googleapis.com/*' => Http::response(['rows' => []], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/analytics/sync");

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_disconnect_ga4_and_plausible_integrations(): void
    {
        $ga4 = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'site_url' => 'properties/123',
            'status' => 'connected',
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/{$ga4->uuid}");

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Google Analytics 4 disconnected.');
        $this->assertDatabaseMissing('project_integrations', ['id' => $ga4->id]);
    }
}
