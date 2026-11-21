<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Events\BacklinkAlertTriggered;
use App\Domains\Backlinks\Models\BacklinkAlert;
use App\Domains\Backlinks\Models\BacklinkHistorySnapshot;
use App\Domains\Backlinks\Services\BacklinkProfileService;
use App\Domains\Backlinks\Services\BacklinkTimelineService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacklinkTimelineTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sandbox.dataforseo.com';

    private const SUMMARY_SERIES = self::BASE.'/v3/backlinks/timeseries_summary/live';

    private const NEW_LOST_SERIES = self::BASE.'/v3/backlinks/timeseries_new_lost_summary/live';

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'test-login');
        config()->set('coqui.dataforseo.password', 'test-password');
        config()->set('coqui.dataforseo.base_url', self::BASE);

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => 'SEO Agency',
            'slug' => 'seo-agency',
            'owner_id' => $this->user->id,
            'monthly_dfs_budget_usd' => 100.00,
        ]);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui Analytics',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function itemsResponse(array $items): array
    {
        return ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]];
    }

    /**
     * @param  list<array<string, mixed>>  $totals
     * @param  list<array<string, mixed>>  $newLost
     * @param  list<array<string, mixed>>  $domains
     */
    private function fakeProvider(array $totals = [], array $newLost = [], array $domains = []): void
    {
        Http::fake([
            self::BASE.'/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['backlinks' => 1500, 'referring_domains' => 150, 'rank' => 420]]]],
            ]),
            self::BASE.'/v3/backlinks/referring_domains/live' => Http::response($this->itemsResponse($domains)),
            self::BASE.'/v3/backlinks/anchors/live' => Http::response($this->itemsResponse([])),
            self::BASE.'/v3/backlinks/pages/live' => Http::response($this->itemsResponse([])),
            self::SUMMARY_SERIES => Http::response($this->itemsResponse($totals)),
            self::NEW_LOST_SERIES => Http::response($this->itemsResponse($newLost)),
        ]);
    }

    private function seriesCalls(): int
    {
        return Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/v3/backlinks/timeseries_'))->count();
    }

    private function backlinksUrl(string $query = ''): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks{$query}";
    }

    /**
     * @param  array<string, int>  $values
     */
    private function snapshot(string $date, array $values = []): BacklinkHistorySnapshot
    {
        return BacklinkHistorySnapshot::query()->create(array_merge([
            'project_id' => $this->project->id,
            'snapshot_date' => $date,
            'backlinks_count' => 800,
            'referring_domains_count' => 90,
            'new_backlinks' => 10,
            'lost_backlinks' => 2,
            'new_referring_domains' => 2,
            'lost_referring_domains' => 0,
            'rank' => 380,
        ], $values));
    }

    /**
     * @param  list<array<string, mixed>>  $domains
     */
    private function cacheDomains(array $domains): void
    {
        app(DataForSeoCacheRepository::class)->put(
            BacklinkProfileService::DOMAINS_ENDPOINT,
            ['target' => 'coquiseo.com', 'limit' => 100],
            ['items' => $domains],
            24,
        );
    }

    public function test_timeline_page_renders_daily_timeseries_and_velocity_from_snapshots(): void
    {
        $tenDaysAgo = now()->subDays(10)->toDateString();
        $today = now()->toDateString();

        $this->fakeProvider(
            totals: [
                ['date' => $tenDaysAgo.' 00:00:00 +00:00', 'backlinks' => 1400, 'referring_domains' => 140, 'rank' => 410],
                ['date' => $today.' 00:00:00 +00:00', 'backlinks' => 1500, 'referring_domains' => 150, 'rank' => 420],
            ],
            newLost: [
                ['date' => $tenDaysAgo.' 00:00:00 +00:00', 'new_backlinks' => 30, 'lost_backlinks' => 5, 'new_referring_domains' => 4, 'lost_referring_domains' => 1],
                ['date' => $today.' 00:00:00 +00:00', 'new_backlinks' => 105, 'lost_backlinks' => 5, 'new_referring_domains' => 11, 'lost_referring_domains' => 1],
            ],
            domains: [
                ['domain' => 'techcrunch.com', 'rank' => 850, 'backlinks' => 20, 'spam_score' => 2, 'first_seen' => now()->subDays(5)->toIso8601String(), 'last_seen' => now()->toIso8601String()],
                ['domain' => 'old-partner.org', 'rank' => 410, 'backlinks' => 5, 'spam_score' => 5, 'first_seen' => now()->subYears(2)->toIso8601String(), 'last_seen' => now()->subDays(120)->toIso8601String()],
            ],
        );

        $this->actingAs($this->user)
            ->get($this->backlinksUrl('?days=90'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('timeline', 2)
                ->where('selectedDays', 90)
                ->where('timeline.1.backlinks', 1500)
                ->where('timeline.1.new_backlinks', 105)
                ->where('timeline.1.rank', 420)
                ->has('newDomains', 1)
                ->where('newDomains.0.domain', 'techcrunch.com')
                ->has('lostDomains', 1)
                ->where('lostDomains.0.domain', 'old-partner.org')
                // Totals come from the daily series, not the domain sample.
                ->where('velocity.total_new', 15)
                ->where('velocity.total_lost', 2)
                ->where('velocity.net_velocity', 13)
                ->where('velocity.trend', 'growing')
            );

        $latest = BacklinkHistorySnapshot::query()
            ->where('project_id', $this->project->id)
            ->where('backlinks_count', 1500)
            ->firstOrFail();
        $this->assertSame($today, $latest->snapshot_date->toDateString());
        $this->assertSame(11, $latest->new_referring_domains);
        $this->assertNotNull($this->project->fresh()?->backlink_history_checked_at);

        // Both series are requested by day.
        Http::assertSent(fn (Request $r): bool => $r->url() === self::SUMMARY_SERIES && ($r->data()[0]['group_range'] ?? null) === 'day');
    }

    public function test_timeline_serves_from_database_snapshots_without_calling_the_history_api(): void
    {
        $this->snapshot(now()->subDays(5)->toDateString());
        $this->fakeProvider();

        $this->actingAs($this->user)
            ->get($this->backlinksUrl('?days=30'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('timeline', 1)
                ->where('timeline.0.backlinks', 800)
                ->where('timeline.0.referring_domains', 90)
            );

        $this->assertSame(0, $this->seriesCalls());
    }

    public function test_an_empty_history_is_fetched_once_not_on_every_view(): void
    {
        $this->fakeProvider();

        $this->actingAs($this->user)->get($this->backlinksUrl())->assertOk()->assertInertia(fn ($page) => $page->has('timeline', 0));
        $this->assertSame(2, $this->seriesCalls());

        $this->actingAs($this->user)->get($this->backlinksUrl())->assertOk();
        $this->actingAs($this->user)->get($this->backlinksUrl('?days=30'))->assertOk();
        $this->assertSame(2, $this->seriesCalls());

        // After the recheck window, a view fetches again.
        $this->travel(8)->days();
        $this->actingAs($this->user)->get($this->backlinksUrl())->assertOk();
        $this->assertSame(4, $this->seriesCalls());
    }

    public function test_a_failed_history_fetch_is_not_retried_on_every_view(): void
    {
        Http::fake([
            self::BASE.'/v3/backlinks/timeseries_*' => Http::response(['status_code' => 50000, 'status_message' => 'Internal error'], 500),
            self::BASE.'/*' => Http::response($this->itemsResponse([])),
        ]);

        $this->actingAs($this->user)->get($this->backlinksUrl())->assertOk();
        $calls = $this->seriesCalls();
        $this->assertGreaterThan(0, $calls);

        $this->actingAs($this->user)->get($this->backlinksUrl())->assertOk();
        $this->assertSame($calls, $this->seriesCalls());
    }

    public function test_a_task_error_in_one_series_leaves_stored_history_alone(): void
    {
        $date = now()->subDays(3)->toDateString();
        $this->snapshot($date, ['backlinks_count' => 800, 'new_referring_domains' => 2]);

        Http::fake([
            self::SUMMARY_SERIES => Http::response($this->itemsResponse([
                ['date' => $date.' 00:00:00 +00:00', 'backlinks' => 900, 'referring_domains' => 95, 'rank' => 390],
            ])),
            // An internal error delivered with HTTP 200.
            self::NEW_LOST_SERIES => Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 50000, 'status_message' => 'Internal error.', 'result' => null]]]),
        ]);

        $this->assertSame(0, app(BacklinkTimelineService::class)->syncHistorySnapshots($this->project));

        $stored = BacklinkHistorySnapshot::query()->where('project_id', $this->project->id)->sole();
        $this->assertSame(800, $stored->backlinks_count);
        $this->assertSame(2, $stored->new_referring_domains);
        // Still counts as checked, so views don't retry it.
        $this->assertNotNull($this->project->fresh()?->backlink_history_checked_at);
    }

    public function test_read_only_members_never_trigger_a_history_fetch(): void
    {
        $viewer = User::factory()->create();
        $this->workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $this->fakeProvider();

        $this->actingAs($viewer)->get($this->backlinksUrl())->assertOk()->assertInertia(fn ($page) => $page->has('timeline', 0));

        $this->assertSame(0, $this->seriesCalls());
    }

    public function test_viewing_the_page_never_sends_alerts_even_when_the_data_qualifies(): void
    {
        Event::fake([BacklinkAlertTriggered::class]);
        // 30 of 100 links lost today.
        $this->snapshot(now()->toDateString(), ['backlinks_count' => 100, 'lost_backlinks' => 30]);
        $this->fakeProvider();

        $this->actingAs($this->user)->get($this->backlinksUrl())->assertOk()->assertInertia(fn ($page) => $page->has('alerts', 0));
        $this->actingAs($this->user)->get($this->backlinksUrl())->assertOk();

        Event::assertNotDispatched(BacklinkAlertTriggered::class);
        $this->assertSame(0, BacklinkAlert::query()->count());
    }

    public function test_a_sudden_drop_is_alerted_once_by_the_scheduled_check(): void
    {
        Event::fake([BacklinkAlertTriggered::class]);
        Http::fake();
        $date = now()->toDateString();
        $this->snapshot($date, ['backlinks_count' => 100, 'lost_backlinks' => 30]);

        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful()->expectsOutputToContain('recorded 1 new backlink alerts');
        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful()->expectsOutputToContain('recorded 0 new backlink alerts');

        Event::assertDispatchedTimes(BacklinkAlertTriggered::class, 1);
        Event::assertDispatched(BacklinkAlertTriggered::class, fn (BacklinkAlertTriggered $e): bool => $e->projectId === $this->project->id
            && $e->alertType === 'backlink_drop'
            && $e->severity === 'warning');

        $alert = BacklinkAlert::query()->sole();
        $this->assertSame('drop:'.$date, $alert->dedupe_key);
        $this->assertSame(30, $alert->meta['lost_backlinks'] ?? null);
        // The check uses stored data only.
        Http::assertNothingSent();

        $this->fakeProvider();
        $this->actingAs($this->user)
            ->get($this->backlinksUrl())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts', 1)
                ->where('alerts.0.type', 'backlink_drop')
                ->where('alerts.0.severity', 'warning')
            );
        Event::assertDispatchedTimes(BacklinkAlertTriggered::class, 1);
    }

    public function test_a_spam_spike_is_alerted_once_per_set_of_spammy_domains(): void
    {
        Event::fake([BacklinkAlertTriggered::class]);
        Http::fake();
        $this->snapshot(now()->subDays(2)->toDateString());

        $spam = fn (string $domain, int $score, int $daysAgo = 1): array => [
            'domain' => $domain,
            'spam_score' => $score,
            'first_seen' => now()->subDays($daysAgo)->toIso8601String(),
            'last_seen' => now()->toIso8601String(),
        ];
        $this->cacheDomains([
            $spam('spam-site-1.xyz', 75),
            $spam('spam-site-2.top', 65),
            $spam('spam-site-3.click', 80),
            $spam('fine.example', 5),
            $spam('old-spam.example', 90, 200),
        ]);

        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful();
        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful();

        Event::assertDispatchedTimes(BacklinkAlertTriggered::class, 1);
        $alert = BacklinkAlert::query()->sole();
        $this->assertSame('spam_spike', $alert->type);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame(3, $alert->meta['high_spam_domains_count'] ?? null);

        // A new spammy domain is a new event.
        $this->cacheDomains([
            $spam('spam-site-1.xyz', 75),
            $spam('spam-site-2.top', 65),
            $spam('spam-site-3.click', 80),
            $spam('spam-site-4.biz', 70),
        ]);
        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful();

        Event::assertDispatchedTimes(BacklinkAlertTriggered::class, 2);
        $this->assertSame(2, BacklinkAlert::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_drop_on_an_earlier_newly_fetched_day_is_alerted(): void
    {
        Event::fake([BacklinkAlertTriggered::class]);
        Http::fake();
        $dropDay = now()->subDays(4)->toDateString();
        $this->snapshot($dropDay, ['backlinks_count' => 100, 'lost_backlinks' => 30]);
        $this->snapshot(now()->subDays(3)->toDateString());
        $this->snapshot(now()->toDateString());
        // Too old to be from the latest refresh.
        $this->snapshot(now()->subDays(40)->toDateString(), ['backlinks_count' => 100, 'lost_backlinks' => 50]);

        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful();
        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful();

        Event::assertDispatchedTimes(BacklinkAlertTriggered::class, 1);
        $this->assertSame('drop:'.$dropDay, BacklinkAlert::query()->sole()->dedupe_key);
    }

    public function test_the_scheduled_check_refreshes_stale_history_only_when_scheduled_spend_is_on(): void
    {
        $this->snapshot(now()->subDays(20)->toDateString());
        $this->project->forceFill(['backlink_history_checked_at' => now()->subDays(8)])->save();
        $this->fakeProvider(totals: [['date' => now()->toDateString(), 'backlinks' => 900, 'referring_domains' => 95, 'rank' => 390]]);

        config()->set('coqui.dataforseo.scheduled_spend', false);
        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful();
        $this->assertSame(0, $this->seriesCalls());

        config()->set('coqui.dataforseo.scheduled_spend', true);
        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful()->expectsOutputToContain('Refreshed 1 backlink histories');
        $this->assertSame(2, $this->seriesCalls());
        $this->assertDatabaseHas('backlink_history_snapshots', ['project_id' => $this->project->id, 'backlinks_count' => 900]);

        // Checked now, so the next run doesn't fetch again.
        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful();
        $this->assertSame(2, $this->seriesCalls());
    }

    public function test_the_scheduled_check_skips_workspaces_without_backlink_intelligence(): void
    {
        Event::fake([BacklinkAlertTriggered::class]);
        $this->workspace->forceFill(['plan_tier' => 'free'])->save();
        $this->snapshot(now()->toDateString(), ['backlinks_count' => 100, 'lost_backlinks' => 30]);

        $this->artisan('backlinks:evaluate-alerts')->assertSuccessful();

        Event::assertNotDispatched(BacklinkAlertTriggered::class);
        $this->assertSame(0, BacklinkAlert::query()->count());
    }
}
