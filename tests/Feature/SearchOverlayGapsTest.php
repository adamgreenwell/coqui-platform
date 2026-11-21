<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\BingCrawlStat;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Models\SearchPerformanceQuery;
use App\Domains\Integrations\Services\BingWebmasterClient;
use App\Domains\Integrations\Services\SearchPerformanceSyncService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchOverlayGapsTest extends TestCase
{
    use RefreshDatabase;

    private const BING = 'https://ssl.bing.com/webmaster/api.svc/json';

    private const GSC_QUERY = 'https://searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query*';

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'mock-google-client-id');
        config()->set('services.google.client_secret', 'mock-google-client-secret');
        config()->set('services.google.search_console_base_url', 'https://searchconsole.googleapis.com');
        config()->set('services.bing.base_url', self::BING);

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

    private function gsc(array $credentials): ProjectIntegration
    {
        /** @var ProjectIntegration */
        return ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'site_url' => 'https://acmesaas.com/',
            'external_identifier' => 'https://acmesaas.com/',
            'status' => 'connected',
            'credentials_encrypted' => $credentials,
        ]);
    }

    private function bing(): ProjectIntegration
    {
        /** @var ProjectIntegration */
        return ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'bing',
            'site_url' => 'https://acmesaas.com/',
            'external_identifier' => 'https://acmesaas.com/',
            'status' => 'connected',
            'credentials_encrypted' => ['api_key' => 'bing-key'],
        ]);
    }

    private function keyword(string $keyword, string $device): void
    {
        $this->project->trackedKeywords()->create([
            'keyword' => $keyword,
            'device' => $device,
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
        ]);
    }

    public function test_the_gsc_reconnect_banner_gets_the_actual_reason_and_reconnecting_clears_it(): void
    {
        $integration = $this->gsc([
            'access_token' => 'expired-token',
            'refresh_token' => 'revoked-refresh-token',
            'expires_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
        ]);

        app(SearchPerformanceSyncService::class)->syncGoogleSearchConsole($integration);

        $integration->refresh();
        $this->assertSame('needs_reconnect', $integration->status);
        $this->assertSame('Token refresh failed: Token has been expired or revoked.', $integration->status_reason);

        $this->actingAs($this->user)
            ->get(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('gscIntegration.status', 'needs_reconnect')
                ->where('gscIntegration.error_message', 'Token refresh failed: Token has been expired or revoked.'));

        $integration->markConnected();
        $this->assertNull($integration->refresh()->status_reason);
    }

    public function test_a_failed_refresh_during_a_query_keeps_the_specific_reason(): void
    {
        $integration = $this->gsc([
            'access_token' => 'stale-token',
            'refresh_token' => 'revoked-refresh-token',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]);
        Http::fake([
            self::GSC_QUERY => Http::response(['error' => 'unauthorized'], 401),
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        app(SearchPerformanceSyncService::class)->syncGoogleSearchConsole($integration);

        $this->assertSame('Token refresh failed: invalid_grant', $integration->refresh()->status_reason);
    }

    public function test_the_bing_reconnect_reason_reaches_the_keywords_page_and_overview(): void
    {
        $integration = $this->bing();
        Http::fake([self::BING.'/GetQueryStats*' => Http::response('Unauthorized', 401)]);

        app(SearchPerformanceSyncService::class)->syncBingWebmaster($integration);

        $this->assertSame('Bing API key invalid or unauthorized.', $integration->refresh()->status_reason);

        $this->actingAs($this->user)
            ->get(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bingIntegration.status', 'needs_reconnect')
                ->where('bingIntegration.error_message', 'Bing API key invalid or unauthorized.'));

        $this->actingAs($this->user)
            ->get(route('workspaces.projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('integrations.0.provider', 'bing')
                ->where('integrations.0.status_reason', 'Bing API key invalid or unauthorized.'));
    }

    public function test_an_expired_token_is_refreshed_and_used_for_the_query(): void
    {
        $integration = $this->gsc([
            'access_token' => 'old-token',
            'refresh_token' => 'good-refresh-token',
            'expires_at' => Carbon::now()->subMinute()->toIso8601String(),
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600], 200),
            self::GSC_QUERY => Http::response(['rows' => []], 200),
        ]);

        $result = app(SearchPerformanceSyncService::class)->syncGoogleSearchConsole($integration);

        $this->assertTrue($result['success']);
        $integration->refresh();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('fresh-token', $integration->getAccessToken());
        $this->assertSame('good-refresh-token', $integration->getRefreshToken());
        $this->assertFalse($integration->isTokenExpired());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'searchAnalytics/query')
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer old-token'));
    }

    public function test_bing_query_stats_parse_every_date_format(): void
    {
        $this->assertSame('2026-11-10', BingWebmasterClient::parseDate('/Date(1794268800000)/'));
        $this->assertSame('2026-11-11', BingWebmasterClient::parseDate('/Date(1794355200000-0800)/'));
        $this->assertSame('2026-11-12', BingWebmasterClient::parseDate('2026-11-12T00:00:00'));
        $this->assertNull(BingWebmasterClient::parseDate(''));
        $this->assertNull(BingWebmasterClient::parseDate(null));

        $integration = $this->bing();
        Http::fake([
            self::BING.'/GetQueryStats*' => Http::response(['d' => [
                ['Query' => 'Rank Tracker', 'Clicks' => 4, 'Impressions' => 40, 'AvgImpressionPosition' => 3.5, 'Date' => '/Date(1794268800000)/'],
                ['Query' => 'rank tracker', 'Clicks' => 2, 'Impressions' => 10, 'AvgImpressionPosition' => 4.0, 'Date' => '/Date(1794355200000-0800)/'],
                ['Query' => '', 'Clicks' => 9, 'Impressions' => 9, 'Date' => '/Date(1794355200000)/'],
            ]], 200),
            self::BING.'/GetCrawlStats*' => Http::response(['d' => []], 200),
        ]);

        $result = app(SearchPerformanceSyncService::class)->syncBingWebmaster($integration);

        $this->assertSame(2, $result['records_synced']);
        $this->assertDatabaseHas('search_performance_queries', ['provider' => 'bing', 'query' => 'Rank Tracker', 'device' => 'all', 'date' => '2026-11-10', 'clicks' => 4]);
        $this->assertDatabaseHas('search_performance_queries', ['provider' => 'bing', 'query' => 'rank tracker', 'device' => 'all', 'date' => '2026-11-11', 'clicks' => 2]);
    }

    public function test_bing_crawl_stats_are_synced_and_summarised_on_the_overview(): void
    {
        $integration = $this->bing();
        $today = Carbon::now()->startOfDay();
        Http::fake([
            self::BING.'/GetQueryStats*' => Http::response(['d' => []], 200),
            self::BING.'/GetCrawlStats*' => Http::response(['d' => [
                ['Date' => '/Date('.($today->copy()->subDays(2)->getTimestamp() * 1000).')/', 'CrawledPages' => 120, 'CrawlErrors' => 6, 'InIndex' => 300, 'Code4xx' => 4, 'Code5xx' => 2, 'BlockedByRobotsTxt' => 1],
                ['Date' => '/Date('.($today->copy()->subDay()->getTimestamp() * 1000).')/', 'CrawledPages' => 80, 'CrawlErrors' => 0, 'InIndex' => 310, 'Code4xx' => 0, 'Code5xx' => 0],
                ['Date' => 'not a date', 'CrawledPages' => 999],
            ]], 200),
        ]);

        app(SearchPerformanceSyncService::class)->syncBingWebmaster($integration);
        // A second sync updates the same days rather than duplicating them.
        app(SearchPerformanceSyncService::class)->syncBingWebmaster($integration);

        $this->assertSame(2, BingCrawlStat::query()->where('project_id', $this->project->id)->count());

        $this->actingAs($this->user)
            ->get(route('workspaces.projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bingCrawl.days', 2)
                ->where('bingCrawl.latestDate', $today->copy()->subDay()->toDateString())
                ->where('bingCrawl.crawledPages', 200)
                ->where('bingCrawl.inIndex', 310)
                ->where('bingCrawl.crawlErrors', 6)
                ->where('bingCrawl.code4xx', 4)
                ->where('bingCrawl.code5xx', 2)
                ->where('bingCrawl.blockedByRobots', 1)
                ->etc());
    }

    public function test_gsc_rows_are_stored_per_device_and_matched_to_each_keyword_row(): void
    {
        $integration = $this->gsc([
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]);
        $date = Carbon::now()->subDays(10)->toDateString();

        // A row synced before the device split, inside the re-synced window.
        SearchPerformanceQuery::query()->create([
            'project_id' => $this->project->id, 'provider' => 'gsc', 'query' => 'rank tracker', 'device' => 'all',
            'date' => $date, 'clicks' => 999, 'impressions' => 9999, 'ctr' => 0.1, 'position' => 9,
        ]);

        Http::fake([
            self::GSC_QUERY => fn (Request $request) => Http::response([
                'rows' => $request['dimensions'] === ['query', 'date', 'device']
                    ? [
                        ['keys' => ['rank tracker', $date, 'DESKTOP'], 'clicks' => 30, 'impressions' => 300, 'ctr' => 0.1, 'position' => 2.0],
                        ['keys' => ['rank tracker', $date, 'MOBILE'], 'clicks' => 12, 'impressions' => 400, 'ctr' => 0.03, 'position' => 6.0],
                    ]
                    : [],
            ], 200),
        ]);

        app(SearchPerformanceSyncService::class)->syncGoogleSearchConsole($integration);

        $this->assertDatabaseMissing('search_performance_queries', ['device' => 'all', 'query' => 'rank tracker']);
        $this->assertDatabaseHas('search_performance_queries', ['device' => 'desktop', 'clicks' => 30]);
        $this->assertDatabaseHas('search_performance_queries', ['device' => 'mobile', 'clicks' => 12]);

        $this->keyword('rank tracker', 'desktop');
        $this->keyword('rank tracker', 'mobile');

        $this->actingAs($this->user)
            ->get(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]))
            ->assertInertia(function (Assert $page): void {
                $keywords = collect($page->toArray()['props']['keywords'])->keyBy('device');
                $this->assertSame(30, $keywords['desktop']['gsc_performance']['clicks']);
                $this->assertSame(300, $keywords['desktop']['gsc_performance']['impressions']);
                $this->assertSame(12, $keywords['mobile']['gsc_performance']['clicks']);
                $this->assertSame(400, $keywords['mobile']['gsc_performance']['impressions']);
            });
    }

    public function test_rows_from_before_the_device_split_still_show_until_the_next_sync(): void
    {
        SearchPerformanceQuery::query()->create([
            'project_id' => $this->project->id, 'provider' => 'gsc', 'query' => 'rank tracker', 'device' => 'all',
            'date' => Carbon::now()->subDays(10)->toDateString(), 'clicks' => 7, 'impressions' => 70, 'ctr' => 0.1, 'position' => 3,
        ]);
        $this->keyword('rank tracker', 'mobile');

        $this->actingAs($this->user)
            ->get(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page->where('keywords.0.gsc_performance.clicks', 7));
    }

    public function test_older_rows_stay_authoritative_on_dates_a_capped_sync_only_partly_covered(): void
    {
        $first = Carbon::now()->subDays(10)->toDateString();
        $second = Carbon::now()->subDays(9)->toDateString();
        $row = fn (string $device, string $date, int $clicks, int $impressions, float $position) => SearchPerformanceQuery::query()->create([
            'project_id' => $this->project->id, 'provider' => 'gsc', 'query' => 'rank tracker', 'device' => $device,
            'date' => $date, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => 0.1, 'position' => $position,
        ]);

        $third = Carbon::now()->subDays(8)->toDateString();

        // A capped device sync kept the older all-device rows on the first
        // two dates and fetched desktop (but not mobile) for the first. The
        // third date was synced per device in full.
        $row('all', $first, 30, 300, 2.0);
        $row('all', $second, 20, 200, 6.0);
        $row('desktop', $first, 25, 250, 2.0);
        $row('desktop', $third, 4, 40, 3.0);
        $row('mobile', $third, 6, 60, 5.0);
        $this->keyword('rank tracker', 'desktop');
        $this->keyword('rank tracker', 'mobile');

        $this->actingAs($this->user)
            ->get(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]))
            ->assertInertia(function (Assert $page): void {
                $keywords = collect($page->toArray()['props']['keywords'])->keyBy('device');
                // The older rows on the first two dates, the device row on the third.
                $desktop = $keywords['desktop']['gsc_performance'];
                $this->assertSame(54, $desktop['clicks']);
                $this->assertSame(540, $desktop['impressions']);
                // (2 * 300 + 6 * 200 + 3 * 40) / 540
                $this->assertEqualsWithDelta(3.6, $desktop['position'], 0.05);

                // Mobile keeps the first date's traffic though only desktop was fetched.
                $mobile = $keywords['mobile']['gsc_performance'];
                $this->assertSame(56, $mobile['clicks']);
                $this->assertSame(560, $mobile['impressions']);
            });
    }

    public function test_a_capped_device_fetch_keeps_the_older_rows_as_a_fallback(): void
    {
        $integration = $this->gsc([
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]);
        $date = Carbon::now()->subDays(10)->toDateString();
        SearchPerformanceQuery::query()->create([
            'project_id' => $this->project->id, 'provider' => 'gsc', 'query' => 'long tail query', 'device' => 'all',
            'date' => $date, 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 8,
        ]);

        // Every page comes back full, so the fetch stops at the row cap.
        Http::fake([
            self::GSC_QUERY => fn (Request $request) => Http::response([
                'rows' => $request['dimensions'] === ['query', 'date', 'device']
                    ? array_map(fn (int $i): array => [
                        'keys' => ['query '.($request['startRow'] + $i), $date, 'DESKTOP'],
                        'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5.0,
                    ], range(0, $request['rowLimit'] - 1))
                    : [],
            ], 200),
        ]);

        app(SearchPerformanceSyncService::class)->syncGoogleSearchConsole($integration);

        $this->assertSame(25000, SearchPerformanceQuery::query()->where('device', 'desktop')->count());
        $this->assertDatabaseHas('search_performance_queries', ['query' => 'long tail query', 'device' => 'all', 'clicks' => 5]);
    }

    public function test_rolling_back_folds_device_rows_into_one_row_per_query_and_date(): void
    {
        $date = Carbon::now()->subDays(10)->toDateString();
        foreach ([['desktop', 30, 300, 2.0], ['mobile', 10, 100, 6.0]] as [$device, $clicks, $impressions, $position]) {
            SearchPerformanceQuery::query()->create([
                'project_id' => $this->project->id, 'provider' => 'gsc', 'query' => 'rank tracker', 'device' => $device,
                'date' => $date, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => 0.1, 'position' => $position,
            ]);
        }

        $migration = require database_path('migrations/0001_01_25_000000_add_integration_reasons_gsc_devices_and_bing_crawl_stats.php');
        $migration->down();

        $rows = DB::table('search_performance_queries')->where('query', 'rank tracker')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(40, (int) $rows[0]->clicks);
        $this->assertSame(400, (int) $rows[0]->impressions);
        // Impression-weighted: (2 * 300 + 6 * 100) / 400.
        $this->assertEqualsWithDelta(3.0, (float) $rows[0]->position, 0.001);

        $migration->up();
    }

    public function test_rolling_back_keeps_a_surviving_all_device_row_instead_of_adding_device_rows_to_it(): void
    {
        $date = Carbon::now()->subDays(10)->toDateString();
        foreach ([['all', 50, 500, 3.0], ['desktop', 30, 300, 2.0]] as [$device, $clicks, $impressions, $position]) {
            SearchPerformanceQuery::query()->create([
                'project_id' => $this->project->id, 'provider' => 'gsc', 'query' => 'rank tracker', 'device' => $device,
                'date' => $date, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => 0.1, 'position' => $position,
            ]);
        }

        $migration = require database_path('migrations/0001_01_25_000000_add_integration_reasons_gsc_devices_and_bing_crawl_stats.php');
        $migration->down();

        $rows = DB::table('search_performance_queries')->where('query', 'rank tracker')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(50, (int) $rows[0]->clicks);
        $this->assertSame(500, (int) $rows[0]->impressions);
        $this->assertEqualsWithDelta(3.0, (float) $rows[0]->position, 0.001);

        $migration->up();
    }
}
