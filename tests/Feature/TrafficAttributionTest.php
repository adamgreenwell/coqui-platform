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

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
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

    public function test_can_connect_ga4_integration_and_store_credentials(): void
    {
        Http::fake([
            'https://analyticsdata.googleapis.com/*' => Http::response(['rows' => []], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/integrations/ga4/connect", [
                'property_id' => 'properties/987654321',
                'api_key' => 'ga4-test-secret',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('project_integrations', [
            'project_id' => $this->project->id,
            'provider' => 'ga4',
            'external_identifier' => 'properties/987654321',
            'status' => 'connected',
        ]);
    }

    public function test_can_connect_plausible_integration_and_store_credentials(): void
    {
        Http::fake([
            'https://plausible.io/*' => Http::response(['results' => []], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/integrations/plausible/connect", [
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
            'credentials_encrypted' => [
                'api_key' => 'test-api-key',
            ],
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

    public function test_sync_plausible_queries_breakdown_and_persists_snapshots(): void
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

        Http::fake([
            'https://plausible.io/api/v1/stats/breakdown*property=event%3Apage*' => Http::response([
                'results' => [
                    [
                        'page' => '/',
                        'visitors' => 500,
                        'pageviews' => 900,
                        'bounce_rate' => 40.0,
                        'visit_duration' => 60,
                    ],
                    [
                        'page' => '/features',
                        'visitors' => 200,
                        'pageviews' => 300,
                        'bounce_rate' => 50.0,
                        'visit_duration' => 45,
                    ],
                ],
            ], 200),
            'https://plausible.io/api/v1/stats/breakdown*property=visit%3Asource*' => Http::response([
                'results' => [
                    ['source' => 'Google', 'visitors' => 400],
                    ['source' => 'ChatGPT', 'visitors' => 100],
                    ['source' => 'Direct / None', 'visitors' => 200],
                ],
            ], 200),
        ]);

        $count = $this->trafficService->syncPlausible($integration, 30);
        $this->assertEquals(2, $count);

        $this->assertDatabaseHas('page_traffic_snapshots', [
            'project_id' => $this->project->id,
            'provider' => 'plausible',
            'path' => '/features',
        ]);
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
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/analytics/performance?days=30");

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
            'credentials_encrypted' => ['api_key' => 'token'],
        ]);

        Http::fake([
            'https://analyticsdata.googleapis.com/*' => Http::response(['rows' => []], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/analytics/sync");

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
            ->delete("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/integrations/{$ga4->id}");

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Google Analytics 4 disconnected.');
        $this->assertDatabaseMissing('project_integrations', ['id' => $ga4->id]);
    }
}
