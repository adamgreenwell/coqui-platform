<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Jobs\SyncTrafficAnalyticsJob;
use App\Domains\Integrations\Models\PageTrafficSnapshot;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\TrafficAttributionService;
use App\Domains\Integrations\Services\UmamiAnalyticsClient;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use App\Support\Http\PublicUrlGuard;
use App\Support\Http\UnsafeUrlException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UmamiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://umami.example';

    private const string WEBSITE_ID = '81a26534-e1ba-456d-80d3-0da36e4484e7';

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    /** Requests for days from this date on fail with a 500. */
    private ?string $failFrom = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PublicUrlGuard::class, new StaticDnsPublicUrlGuard([
            'umami.example' => ['93.184.216.34'],
            'internal.example' => ['10.0.0.5'],
            'mixed.example' => ['93.184.216.34', '169.254.169.254'],
            'api.umami.is' => ['93.184.216.40'],
        ]));

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => 'Umami',
            'slug' => 'umami-workspace',
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->members()->attach($this->owner->id, ['role' => 'owner']);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Guard Dog',
            'domain' => 'guarddog.cc',
            'canonical_url' => 'https://guarddog.cc/',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // URL guard

    public function test_guard_accepts_a_public_https_address_and_pins_its_ip(): void
    {
        $target = app(PublicUrlGuard::class)->check('https://umami.example/analytics/');

        $this->assertSame('https://umami.example/analytics', $target['base']);
        $this->assertSame('93.184.216.34', $target['ip']);
        $this->assertSame(
            ['umami.example:443:93.184.216.34'],
            app(PublicUrlGuard::class)->curlOptions($target)['curl'][CURLOPT_RESOLVE],
        );
        $this->assertFalse(app(PublicUrlGuard::class)->curlOptions($target)['allow_redirects']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'plain http' => ['http://umami.example'],
            'no scheme' => ['umami.example'],
            'userinfo' => ['https://admin:secret@umami.example'],
            'query string' => ['https://umami.example/?next=/admin'],
            'private dns' => ['https://internal.example'],
            'one private record' => ['https://mixed.example'],
            'unresolvable' => ['https://nowhere.example'],
            'loopback' => ['https://127.0.0.1'],
            'rfc1918' => ['https://192.168.1.10'],
            'metadata' => ['https://169.254.169.254'],
            'cgnat' => ['https://100.64.0.1'],
            'ipv6 loopback' => ['https://[::1]'],
            'ipv6 unique local' => ['https://[fd00::1]'],
            'ipv6 link local' => ['https://[fe80::1]'],
            'ipv4 mapped' => ['https://[::ffff:10.0.0.1]'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_guard_rejects_unsafe_addresses(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);

        app(PublicUrlGuard::class)->check($url);
    }

    // Client

    public function test_client_lists_websites_with_the_api_key(): void
    {
        Http::fake([
            self::BASE.'/api/websites*' => Http::response(['data' => [
                ['id' => self::WEBSITE_ID, 'name' => 'Guard Dog', 'domain' => 'guarddog.cc'],
                ['name' => 'missing id'],
            ]]),
        ]);

        $websites = app(UmamiAnalyticsClient::class)->listWebsites(self::BASE.'/', 'umami_test');

        $this->assertSame([['id' => self::WEBSITE_ID, 'name' => 'Guard Dog', 'domain' => 'guarddog.cc']], $websites);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer umami_test')
            && str_starts_with($request->url(), self::BASE.'/api/websites?')
            && str_contains($request->url(), 'includeTeams=true'));
    }

    public function test_client_fetches_each_day_with_millisecond_bounds(): void
    {
        $this->fakeUmami();

        $daily = app(UmamiAnalyticsClient::class)->fetchDailyMetrics(
            $this->umamiIntegration(),
            Carbon::parse('2026-11-19'),
            Carbon::parse('2026-11-20'),
        );

        $this->assertSame(['2026-11-19', '2026-11-20'], array_keys($daily));
        $this->assertSame(
            ['page' => '/features', 'visitors' => 5, 'visits' => 6, 'pageviews' => 9, 'bounces' => 3, 'totaltime' => 120],
            $daily['2026-11-20']['pages'][0],
        );
        $this->assertContains(['referrer' => 'google.com', 'visitors' => 4], $daily['2026-11-20']['referrers']);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/metrics/expanded')
                && (int) $query['startAt'] === Carbon::parse('2026-11-20 00:00:00', 'UTC')->getTimestampMs()
                && (int) $query['endAt'] === Carbon::parse('2026-11-21 00:00:00', 'UTC')->getTimestampMs() - 1;
        });
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function apiRoots(): array
    {
        return [
            'self-hosted' => [self::BASE, self::BASE.'/api/websites'],
            'self-hosted with /api' => [self::BASE.'/api', self::BASE.'/api/websites'],
            'cloud' => ['https://api.umami.is', 'https://api.umami.is/v1/websites'],
            'cloud with /v1' => ['https://api.umami.is/v1', 'https://api.umami.is/v1/websites'],
            'cloud region' => ['https://api.umami.is/v1/eu', 'https://api.umami.is/v1/eu/websites'],
        ];
    }

    #[DataProvider('apiRoots')]
    public function test_client_uses_the_right_api_root(string $baseUrl, string $expected): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        app(UmamiAnalyticsClient::class)->listWebsites($baseUrl, 'umami_test');

        Http::assertSent(fn (Request $request): bool => strtok($request->url(), '?') === $expected);
    }

    public function test_cloud_requests_are_paced_under_the_rate_limit(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        Http::fake(['*' => Http::response([])]);
        $integration = $this->umamiIntegration();
        $integration->update(['credentials_encrypted' => ['base_url' => 'https://api.umami.is', 'api_key' => 'umami_test']]);

        // 31 days at two calls a day is more than one window allows.
        app(UmamiAnalyticsClient::class)->fetchDailyMetrics($integration->fresh() ?? $integration, Carbon::parse('2026-10-20'), Carbon::parse('2026-11-19'));

        Sleep::assertSleptTimes(1);
        Http::assertSentCount(62);
    }

    public function test_self_hosted_requests_are_not_paced(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response([])]);

        app(UmamiAnalyticsClient::class)->fetchDailyMetrics($this->umamiIntegration(), Carbon::parse('2026-10-20'), Carbon::parse('2026-11-19'));

        Sleep::assertNeverSlept();
    }

    // Sync

    public function test_sync_counts_search_and_ai_visits_per_page_from_referrers(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->fakeUmami();
        $integration = $this->umamiIntegration();

        $count = app(TrafficAttributionService::class)->syncIntegration($integration, 0);

        // "/features" and "/features/" merge into one page.
        $this->assertSame(4, $count);

        $features = $this->snapshot('/features');
        $this->assertSame(7, $features->total_sessions);
        $this->assertSame(4, $features->organic_sessions); // google 3 + bing 1
        $this->assertSame(2, $features->ai_referral_sessions);
        $this->assertSame(1, $features->direct_sessions);
        $this->assertEquals(50.0, $features->bounce_rate);
        $this->assertSame(18, $features->avg_engagement_time_sec); // 150s over 8 visits
        $this->assertSame(['ChatGPT' => 2], $this->breakdown($features));

        $home = $this->snapshot('/');
        $this->assertSame([10, 1, 2, 7], [$home->total_sessions, $home->organic_sessions, $home->ai_referral_sessions, $home->direct_sessions]);
        $this->assertSame(['ChatGPT' => 1, 'Perplexity' => 1], $this->breakdown($home));

        // Referrer counts larger than the page's total are capped.
        $tiny = $this->snapshot('/tiny');
        $this->assertSame([1, 1, 0, 0], [$tiny->total_sessions, $tiny->organic_sessions, $tiny->ai_referral_sessions, $tiny->direct_sessions]);
        $this->assertSame([], $this->breakdown($tiny));

        // A capped AI total scales the per-assistant counts down with it.
        $split = $this->snapshot('/split');
        $this->assertSame([3, 1, 2, 0], [$split->total_sessions, $split->organic_sessions, $split->ai_referral_sessions, $split->direct_sessions]);
        $this->assertSame(['ChatGPT' => 1, 'Perplexity' => 1], $this->breakdown($split));

        // Only search and AI referrers get a per-page lookup.
        Http::assertNotSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'referrer=news.ycombinator.com'));

        $fresh = $integration->fresh();
        $this->assertSame('connected', $fresh?->status);
        $this->assertNotNull($fresh?->last_synced_at);
    }

    public function test_later_syncs_refetch_only_recent_days_and_replace_them(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->fakeUmami();
        $integration = $this->umamiIntegration();
        $integration->update(['last_synced_at' => Carbon::parse('2026-11-18 04:30:00')]);

        $this->storeSnapshot('/stale', '2026-11-17');
        $this->storeSnapshot('/kept', '2026-11-10');

        app(TrafficAttributionService::class)->syncIntegration($integration->fresh() ?? $integration);

        $expanded = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/metrics/expanded'));
        $this->assertCount(5, $expanded); // 16th through 20th

        $paths = PageTrafficSnapshot::query()->where('provider', 'umami')->pluck('path')->unique()->sort()->values()->all();
        $this->assertSame(['/', '/features', '/kept', '/split', '/tiny'], $paths);
    }

    public function test_an_interrupted_first_sync_keeps_finished_weeks_and_resumes_after_them(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $integration = $this->umamiIntegration();

        // Weeks run Oct 21-27, Oct 28-Nov 3, Nov 4-10, ...; the third fails.
        $this->fakeUmami();
        $this->failFrom = '2026-11-04';
        try {
            app(TrafficAttributionService::class)->syncIntegration($integration);
            $this->fail('Expected the third week to fail.');
        } catch (RuntimeException) {
        }

        $integration->refresh();
        $this->assertSame('2026-11-03', $integration->last_synced_at?->format('Y-m-d'));
        $this->assertSame(14 * 4, PageTrafficSnapshot::query()->where('provider', 'umami')->count());

        $this->failFrom = null;
        $before = Http::recorded()->count();
        app(TrafficAttributionService::class)->syncIntegration($integration);

        // Resumes two days before the last stored day.
        $expanded = Http::recorded()->slice($before)
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/metrics/expanded'));
        $this->assertCount(20, $expanded);
        $this->assertSame(31 * 4, PageTrafficSnapshot::query()->where('provider', 'umami')->count());
        $this->assertSame('2026-11-20', $integration->fresh()?->last_synced_at?->format('Y-m-d'));
    }

    public function test_a_first_sync_runs_as_a_chain_of_week_long_jobs(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->fakeUmami();
        $integration = $this->umamiIntegration();
        Queue::fake();

        (new SyncTrafficAnalyticsJob($integration))->handle(app(TrafficAttributionService::class));

        // Oct 21-27 stored, the rest queued for the next job.
        $this->assertSame('2026-10-27', $integration->fresh()?->last_synced_at?->format('Y-m-d'));
        $this->assertSame(7 * 4, PageTrafficSnapshot::query()->where('provider', 'umami')->count());
        Queue::assertPushed(SyncTrafficAnalyticsJob::class, fn (SyncTrafficAnalyticsJob $job): bool => $job->integration->is($integration));

        // Run the chain to the end.
        for ($run = 0; $run < 10 && app(TrafficAttributionService::class)->umamiSyncPending($integration); $run++) {
            (new SyncTrafficAnalyticsJob($integration->fresh() ?? $integration))->handle(app(TrafficAttributionService::class));
        }

        $this->assertSame(31 * 4, PageTrafficSnapshot::query()->where('provider', 'umami')->count());
        $this->assertSame('2026-11-20', $integration->fresh()?->last_synced_at?->format('Y-m-d'));
        $this->assertFalse(app(TrafficAttributionService::class)->umamiSyncPending($integration));
    }

    public function test_full_metric_pages_are_followed_by_offset(): void
    {
        $firstPage = array_map(fn (int $i): array => ['x' => "/p{$i}", 'y' => 1], range(1, 1000));
        Http::fake(function (Request $request) use ($firstPage) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['type'] ?? null) === 'path' && isset($query['referrer'])) {
                return Http::response(match ((int) ($query['offset'] ?? 0)) {
                    0 => $firstPage,
                    1000 => [['x' => '/last', 'y' => 2]],
                    default => [],
                });
            }

            return Http::response([]);
        });

        $pages = app(UmamiAnalyticsClient::class)->fetchPagesByReferrer($this->umamiIntegration(), '2026-11-20', ['google.com']);

        $this->assertCount(1001, $pages['google.com']);
        $this->assertSame(['page' => '/last', 'visitors' => 2], $pages['google.com'][1000]);
    }

    public function test_a_day_with_more_pages_than_the_guard_allows_fails_instead_of_storing_part_of_it(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) ($query['offset'] ?? 0);

            // Every page is full and different, so paging never ends.
            return Http::response(array_map(fn (int $i): array => ['x' => '/p'.($offset + $i), 'y' => 1], range(1, 1000)));
        });

        try {
            app(UmamiAnalyticsClient::class)->fetchPagesByReferrer($this->umamiIntegration(), '2026-11-20', ['google.com']);
            $this->fail('Expected the page guard to stop the sync.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not storing a partial day', $e->getMessage());
        }

        Http::assertSentCount(25);
    }

    public function test_an_instance_that_ignores_offset_is_not_paged_forever(): void
    {
        $fullPage = array_map(fn (int $i): array => ['x' => "/p{$i}", 'y' => 1], range(1, 1000));
        Http::fake(['*' => Http::response($fullPage)]);

        $pages = app(UmamiAnalyticsClient::class)->fetchPagesByReferrer($this->umamiIntegration(), '2026-11-20', ['google.com']);

        $this->assertCount(1000, $pages['google.com']);
        Http::assertSentCount(2);
    }

    public function test_a_rejected_key_marks_the_integration_for_reconnect_without_retrying(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);
        $integration = $this->umamiIntegration();

        (new SyncTrafficAnalyticsJob($integration))->handle(app(TrafficAttributionService::class));

        $this->assertSame('needs_reconnect', $integration->fresh()?->status);
    }

    public function test_sync_rechecks_the_address_and_refuses_one_now_pointing_inside(): void
    {
        Http::fake();
        $integration = $this->umamiIntegration();
        $integration->update(['credentials_encrypted' => ['base_url' => 'https://internal.example', 'api_key' => 'umami_test']]);

        try {
            app(TrafficAttributionService::class)->syncIntegration($integration->fresh() ?? $integration);
            $this->fail('Expected the private address to be refused.');
        } catch (UnsafeUrlException) {
            Http::assertNothingSent();
        }
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

        $this->expectExceptionMessage('redirected');

        app(UmamiAnalyticsClient::class)->listWebsites(self::BASE, 'umami_test');
    }

    public function test_report_prefers_ga4_then_umami_then_plausible(): void
    {
        $since = Carbon::parse('2026-11-01');
        $this->storeSnapshot('/', '2026-11-10', 'plausible');
        $this->storeSnapshot('/', '2026-11-10', 'umami');
        foreach (['plausible', 'umami'] as $provider) {
            $this->connected($provider);
        }

        $service = app(TrafficAttributionService::class);
        $this->assertSame('umami', $service->trafficProviderFor($this->project, $since));

        $this->storeSnapshot('/', '2026-11-10', 'ga4');
        $this->connected('ga4');
        $this->assertSame('ga4', $service->trafficProviderFor($this->project, $since));
    }

    // Connect

    public function test_connect_matches_the_project_domain_and_queues_the_first_sync(): void
    {
        Queue::fake();
        $this->fakeWebsites([
            ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Other', 'domain' => 'other.example'],
            ['id' => self::WEBSITE_ID, 'name' => 'Guard Dog', 'domain' => 'www.GuardDog.cc'],
        ]);

        $this->connect(['base_url' => self::BASE.'/', 'api_key' => ' umami_test '])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $integration = ProjectIntegration::query()->where('project_id', $this->project->id)->where('provider', 'umami')->firstOrFail();
        $this->assertSame(self::WEBSITE_ID, $integration->external_identifier);
        $this->assertSame('www.GuardDog.cc', $integration->site_url);
        $this->assertSame('connected', $integration->status);
        $this->assertSame(['base_url' => self::BASE, 'api_key' => 'umami_test'], $integration->credentials_encrypted);

        Queue::assertPushed(SyncTrafficAnalyticsJob::class, fn (SyncTrafficAnalyticsJob $job): bool => $job->integration->is($integration));
    }

    public function test_connect_finds_a_website_beyond_the_first_page(): void
    {
        Queue::fake();
        $firstPage = array_map(fn (int $i): array => [
            'id' => sprintf('00000000-0000-4000-8000-%012d', $i),
            'name' => "Site {$i}",
            'domain' => "site{$i}.example",
        ], range(1, 200));
        Http::fake(function (Request $request) use ($firstPage) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(match ((int) $query['page']) {
                1 => ['data' => $firstPage, 'count' => 201],
                2 => ['data' => [['id' => self::WEBSITE_ID, 'name' => 'Guard Dog', 'domain' => 'guarddog.cc']], 'count' => 201],
                default => ['data' => [], 'count' => 201],
            });
        });

        $this->connect(['base_url' => self::BASE, 'api_key' => 'umami_test'])->assertSessionHasNoErrors();

        $this->assertSame(self::WEBSITE_ID, ProjectIntegration::query()->where('provider', 'umami')->value('external_identifier'));
        Http::assertSentCount(2);
    }

    public function test_connect_uses_the_website_id_when_given(): void
    {
        Queue::fake();
        $otherId = '11111111-1111-4111-8111-111111111111';
        $this->fakeWebsites([
            ['id' => self::WEBSITE_ID, 'name' => 'Guard Dog', 'domain' => 'guarddog.cc'],
            ['id' => $otherId, 'name' => 'Staging', 'domain' => 'staging.guarddog.cc'],
        ]);

        $this->connect(['base_url' => self::BASE, 'api_key' => 'umami_test', 'website_id' => $otherId])
            ->assertSessionHasNoErrors();

        $this->assertSame($otherId, ProjectIntegration::query()->where('provider', 'umami')->value('external_identifier'));
    }

    public function test_connect_lists_the_available_websites_when_none_match(): void
    {
        Queue::fake();
        $this->fakeWebsites([['id' => self::WEBSITE_ID, 'name' => 'Blog', 'domain' => 'blog.example']]);

        $this->connect(['base_url' => self::BASE, 'api_key' => 'umami_test'])
            ->assertSessionHasErrors(['website_id' => 'No Umami website matches guarddog.cc. Enter its website ID. Available: Blog (blog.example): '.self::WEBSITE_ID]);

        $this->assertDatabaseMissing('project_integrations', ['provider' => 'umami']);
        Queue::assertNothingPushed();
    }

    public function test_connect_reports_an_unsafe_address_on_the_url_field(): void
    {
        Http::fake();

        $this->connect(['base_url' => 'https://internal.example', 'api_key' => 'umami_test'])
            ->assertSessionHasErrors('base_url');

        Http::assertNothingSent();
    }

    public function test_connect_reports_a_rejected_key_on_the_key_field(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->connect(['base_url' => self::BASE, 'api_key' => 'umami_wrong'])
            ->assertSessionHasErrors(['api_key' => 'Umami rejected this API key.']);
    }

    public function test_connect_reports_an_unreachable_instance_on_the_url_field(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->connect(['base_url' => self::BASE, 'api_key' => 'umami_test'])
            ->assertSessionHasErrors('base_url');
    }

    public function test_manual_syncs_queue_umami_instead_of_running_in_the_request(): void
    {
        Queue::fake();
        Http::fake();
        $integration = $this->umamiIntegration();

        $this->actingAs($this->owner)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/{$integration->uuid}/sync")
            ->assertSessionHas('success', fn (string $message): bool => str_starts_with($message, 'Umami sync queued'));

        $this->actingAs($this->owner)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/analytics/sync")
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Umami is syncing in the background'));

        Queue::assertPushed(SyncTrafficAnalyticsJob::class, 2);
        Http::assertNothingSent();
    }

    public function test_connect_requires_traffic_attribution(): void
    {
        $this->workspace->applyPlanTier('free');
        Http::fake();

        $this->connect(['base_url' => self::BASE, 'api_key' => 'umami_test'])->assertRedirect();
        $this->assertDatabaseMissing('project_integrations', ['provider' => 'umami']);

        Http::assertNothingSent();
    }

    // Helpers

    /**
     * @param  array<string, string>  $data
     */
    private function connect(array $data): TestResponse
    {
        return $this->actingAs($this->owner)
            ->from("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}")
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/umami/connect", $data);
    }

    /**
     * @param  list<array{id: string, name: string, domain: string}>  $websites
     */
    private function fakeWebsites(array $websites): void
    {
        Http::fake([self::BASE.'/api/websites*' => Http::response(['data' => $websites])]);
    }

    /**
     * The same day of traffic for every requested date.
     */
    private function fakeUmami(): void
    {
        $byReferrer = [
            'google.com' => [['x' => '/features', 'y' => 3], ['x' => '/', 'y' => 1], ['x' => '/tiny', 'y' => 2], ['x' => '/split', 'y' => 1]],
            'bing.com' => [['x' => '/features/', 'y' => 1]],
            'chatgpt.com' => [['x' => '/features', 'y' => 2], ['x' => '/', 'y' => 1], ['x' => '/tiny', 'y' => 1], ['x' => '/split', 'y' => 2]],
            'perplexity.ai' => [['x' => '/', 'y' => 1], ['x' => '/split', 'y' => 2]],
        ];

        Http::fake(function (Request $request) use ($byReferrer) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($this->failFrom !== null && (int) $query['startAt'] >= Carbon::parse($this->failFrom, 'UTC')->getTimestampMs()) {
                return Http::response('upstream down', 500);
            }

            if (str_ends_with($path, '/metrics/expanded')) {
                return Http::response([
                    ['name' => '/features', 'visitors' => 5, 'visits' => 6, 'pageviews' => '9', 'bounces' => 3, 'totaltime' => 120],
                    ['name' => '/features/', 'visitors' => 2, 'visits' => 2, 'pageviews' => '2', 'bounces' => 1, 'totaltime' => 30],
                    ['name' => '/', 'visitors' => 10, 'visits' => 10, 'pageviews' => '14', 'bounces' => 0, 'totaltime' => 0],
                    ['name' => '/tiny', 'visitors' => 1, 'visits' => 1, 'pageviews' => '1', 'bounces' => 1, 'totaltime' => 0],
                    ['name' => '/split', 'visitors' => 3, 'visits' => 3, 'pageviews' => '3', 'bounces' => 0, 'totaltime' => 0],
                ]);
            }

            if (str_ends_with($path, '/metrics') && ($query['type'] ?? null) === 'referrer') {
                return Http::response([
                    ['x' => 'google.com', 'y' => 4],
                    ['x' => 'chatgpt.com', 'y' => 3],
                    ['x' => 'perplexity.ai', 'y' => 1],
                    ['x' => 'bing.com', 'y' => 1],
                    ['x' => 'news.ycombinator.com', 'y' => 2],
                ]);
            }

            if (str_ends_with($path, '/metrics') && isset($query['referrer'])) {
                return Http::response($byReferrer[$query['referrer']] ?? []);
            }

            return Http::response('unexpected request', 500);
        });
    }

    private function umamiIntegration(): ProjectIntegration
    {
        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'umami',
            'site_url' => 'guarddog.cc',
            'external_identifier' => self::WEBSITE_ID,
            'status' => 'connected',
            'credentials_encrypted' => ['base_url' => self::BASE, 'api_key' => 'umami_test'],
        ]);

        return $integration;
    }

    private function connected(string $provider): void
    {
        ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => $provider,
            'site_url' => 'guarddog.cc',
            'external_identifier' => 'guarddog.cc',
            'status' => 'connected',
            'credentials_encrypted' => ['api_key' => 'test-key'],
        ]);
    }

    private function storeSnapshot(string $path, string $date, string $provider = 'umami'): void
    {
        PageTrafficSnapshot::query()->create([
            'project_id' => $this->project->id,
            'provider' => $provider,
            'path' => $path,
            'path_hash' => hash('sha256', $path),
            'date' => $date,
            'organic_sessions' => 1,
            'ai_referral_sessions' => 0,
            'direct_sessions' => 0,
            'total_sessions' => 1,
            'bounce_rate' => 0,
            'avg_engagement_time_sec' => 0,
            'ai_referrals_breakdown' => '[]',
        ]);
    }

    private function snapshot(string $path): PageTrafficSnapshot
    {
        return PageTrafficSnapshot::query()
            ->where('project_id', $this->project->id)
            ->where('provider', 'umami')
            ->where('path', $path)
            ->sole();
    }

    /**
     * @return array<string, int>
     */
    private function breakdown(PageTrafficSnapshot $snapshot): array
    {
        $value = $snapshot->ai_referrals_breakdown;
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $breakdown = is_array($decoded) ? $decoded : [];
        ksort($breakdown);

        return $breakdown;
    }
}

class StaticDnsPublicUrlGuard extends PublicUrlGuard
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(private readonly array $records) {}

    protected function resolve(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}
