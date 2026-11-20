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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UmamiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://umami.example';

    private const string WEBSITE_ID = '81a26534-e1ba-456d-80d3-0da36e4484e7';

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PublicUrlGuard::class, new StaticDnsPublicUrlGuard([
            'umami.example' => ['93.184.216.34'],
            'internal.example' => ['10.0.0.5'],
            'mixed.example' => ['93.184.216.34', '169.254.169.254'],
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
            && str_starts_with($request->url(), self::BASE.'/api/websites?'));
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

    // Sync

    public function test_sync_counts_search_and_ai_visits_per_page_from_referrers(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->fakeUmami();
        $integration = $this->umamiIntegration();

        $count = app(TrafficAttributionService::class)->syncIntegration($integration, 0);

        // "/features" and "/features/" merge into one page.
        $this->assertSame(3, $count);

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
        $this->assertSame(['/', '/features', '/kept', '/tiny'], $paths);
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
            'google.com' => [['x' => '/features', 'y' => 3], ['x' => '/', 'y' => 1], ['x' => '/tiny', 'y' => 2]],
            'bing.com' => [['x' => '/features/', 'y' => 1]],
            'chatgpt.com' => [['x' => '/features', 'y' => 2], ['x' => '/', 'y' => 1], ['x' => '/tiny', 'y' => 1]],
            'perplexity.ai' => [['x' => '/', 'y' => 1]],
        ];

        Http::fake(function (Request $request) use ($byReferrer) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/metrics/expanded')) {
                return Http::response([
                    ['name' => '/features', 'visitors' => 5, 'visits' => 6, 'pageviews' => '9', 'bounces' => 3, 'totaltime' => 120],
                    ['name' => '/features/', 'visitors' => 2, 'visits' => 2, 'pageviews' => '2', 'bounces' => 1, 'totaltime' => 30],
                    ['name' => '/', 'visitors' => 10, 'visits' => 10, 'pageviews' => '14', 'bounces' => 0, 'totaltime' => 0],
                    ['name' => '/tiny', 'visitors' => 1, 'visits' => 1, 'pageviews' => '1', 'bounces' => 1, 'totaltime' => 0],
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
