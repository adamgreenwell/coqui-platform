<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Events\BacklinkAlertTriggered;
use App\Domains\Backlinks\Jobs\VerifyMonitoredBacklinksJob;
use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Backlinks\Services\BacklinkVerificationService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BacklinkWatchlistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // No pacing between same-host checks unless a test asks for it.
        config()->set('coqui.backlinks.verify_host_delay_ms', 0);

        config()->set('coqui.dataforseo.login', 'test-login');
        config()->set('coqui.dataforseo.password', 'test-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');

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

    public function test_watchlist_page_renders_with_inertia(): void
    {
        MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://techcrunch.com/article/top-tools',
            'source_url_hash' => hash('sha256', 'https://techcrunch.com/article/top-tools'),
            'target_url' => 'https://coquiseo.com/',
            'expected_anchor' => 'Coqui SEO Platform',
            'expected_rel' => 'dofollow',
            'state' => 'active',
            'last_http_status' => 200,
            'last_observed_rel' => 'dofollow',
            'last_page_noindex' => false,
            'last_verified_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('links', 1)
            ->where('links.0.source_url', 'https://techcrunch.com/article/top-tools')
            ->where('links.0.state', 'active')
            ->where('metrics.total', 1)
            ->where('metrics.active', 1)
            ->where('metrics.lost', 0)
        );
    }

    public function test_store_creates_monitored_backlink(): void
    {
        Http::fake([
            'https://venturebeat.com/ai-seo' => Http::response(
                '<html><body><a href="https://coquiseo.com/" rel="dofollow">Coqui</a></body></html>',
                200
            ),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist", [
                'source_url' => 'https://venturebeat.com/ai-seo',
                'target_url' => 'https://coquiseo.com/',
                'expected_anchor' => 'Coqui',
                'expected_rel' => 'dofollow',
            ]);

        $response->assertRedirect(route('workspaces.projects.backlinks.watchlist.index', [$this->workspace, $this->project]));

        $this->assertDatabaseHas('monitored_backlinks', [
            'project_id' => $this->project->id,
            'source_url' => 'https://venturebeat.com/ai-seo',
            'expected_rel' => 'dofollow',
            'state' => 'active',
            'last_http_status' => 200,
        ]);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'backlink.monitored_added',
        ]);
    }

    public function test_verify_all_inspects_links_and_updates_states(): void
    {
        /** @var MonitoredBacklink $activeLink */
        $activeLink = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://site-a.com/page',
            'source_url_hash' => hash('sha256', 'https://site-a.com/page'),
            'target_url' => 'https://coquiseo.com/',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ]);

        /** @var MonitoredBacklink $brokenLink */
        $brokenLink = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://site-b.com/page',
            'source_url_hash' => hash('sha256', 'https://site-b.com/page'),
            'target_url' => 'https://coquiseo.com/',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ]);

        /** @var MonitoredBacklink $relFlippedLink */
        $relFlippedLink = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://site-c.com/page',
            'source_url_hash' => hash('sha256', 'https://site-c.com/page'),
            'target_url' => 'https://coquiseo.com/',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ]);

        /** @var MonitoredBacklink $noindexLink */
        $noindexLink = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://site-d.com/page',
            'source_url_hash' => hash('sha256', 'https://site-d.com/page'),
            'target_url' => 'https://coquiseo.com/',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ]);

        Http::fake([
            // Link A: Healthy 200 with matching target and dofollow
            'https://site-a.com/page' => Http::response(
                '<html><body><a href="https://coquiseo.com/" rel="dofollow">Coqui</a></body></html>',
                200
            ),
            // Link B: 404 Not Found
            'https://site-b.com/page' => Http::response('Not Found', 404),
            // Link C: Rel flipped to sponsored
            'https://site-c.com/page' => Http::response(
                '<html><body><a href="https://coquiseo.com/" rel="sponsored">Coqui</a></body></html>',
                200
            ),
            // Link D: Meta robots noindex tag
            'https://site-d.com/page' => Http::response(
                '<html><head><meta name="robots" content="noindex, follow"></head><body><a href="https://coquiseo.com/">Coqui</a></body></html>',
                200
            ),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/verify");

        $response->assertRedirect(route('workspaces.projects.backlinks.watchlist.index', [$this->workspace, $this->project]));

        $this->assertSame('active', $activeLink->fresh()->state);
        $this->assertSame('error', $brokenLink->fresh()->state);
        $this->assertSame('rel_changed', $relFlippedLink->fresh()->state);
        $this->assertSame('lost', $noindexLink->fresh()->state);
        $this->assertTrue($noindexLink->fresh()->last_page_noindex);
    }

    public function test_state_change_to_lost_dispatches_alert_event(): void
    {
        Event::fake([BacklinkAlertTriggered::class]);

        /** @var MonitoredBacklink $link */
        $link = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://partner.com/article',
            'source_url_hash' => hash('sha256', 'https://partner.com/article'),
            'target_url' => 'https://coquiseo.com/',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ]);

        // Link was removed from the source page
        Http::fake([
            'https://partner.com/article' => Http::response(
                '<html><body><p>We removed all third-party links.</p></body></html>',
                200
            ),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/{$link->uuid}/verify");

        $response->assertRedirect(route('workspaces.projects.backlinks.watchlist.index', [$this->workspace, $this->project]));

        $this->assertSame('lost', $link->fresh()->state);

        Event::assertDispatched(BacklinkAlertTriggered::class, function (BacklinkAlertTriggered $event) use ($link) {
            return $event->projectId === $this->project->id
                && $event->alertType === 'link_rot_lost'
                && $event->severity === 'critical'
                && $event->targetUrl === $link->source_url;
        });
    }

    public function test_import_from_data_for_seo_populates_watchlist(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/backlinks/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'url_from' => 'https://forbes.com/seo-guide',
                                        'url_to' => 'https://coquiseo.com/features',
                                        'anchor' => 'Coqui Analytics Features',
                                        'dofollow' => true,
                                        'rank' => 880,
                                    ],
                                    [
                                        'url_from' => 'https://medium.com/@dev/search-tools',
                                        'url_to' => 'https://coquiseo.com/',
                                        'anchor' => 'modern search tools',
                                        'dofollow' => false,
                                        'rank' => 650,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/import-dfs");

        $response->assertRedirect(route('workspaces.projects.backlinks.watchlist.index', [$this->workspace, $this->project]));

        $this->assertDatabaseHas('monitored_backlinks', [
            'project_id' => $this->project->id,
            'source_url' => 'https://forbes.com/seo-guide',
            'target_url' => 'https://coquiseo.com/features',
            'expected_rel' => 'dofollow',
        ]);

        $this->assertDatabaseHas('monitored_backlinks', [
            'project_id' => $this->project->id,
            'source_url' => 'https://medium.com/@dev/search-tools',
            'expected_rel' => 'nofollow',
        ]);
    }

    public function test_import_csv_parses_and_persists_backlinks(): void
    {
        $csvContent = <<<'CSV'
source_url,target_url,expected_anchor,expected_rel
https://nytimes.com/tech-review,https://coquiseo.com/,Best Tech,dofollow
https://wired.com/software-awards,https://coquiseo.com/awards,Software Winner,sponsored
CSV;

        $file = UploadedFile::fake()->createWithContent('backlinks.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/import-csv", [
                'csv_file' => $file,
            ]);

        $response->assertRedirect(route('workspaces.projects.backlinks.watchlist.index', [$this->workspace, $this->project]));

        $this->assertDatabaseHas('monitored_backlinks', [
            'project_id' => $this->project->id,
            'source_url' => 'https://nytimes.com/tech-review',
            'expected_anchor' => 'Best Tech',
            'expected_rel' => 'dofollow',
        ]);

        $this->assertDatabaseHas('monitored_backlinks', [
            'project_id' => $this->project->id,
            'source_url' => 'https://wired.com/software-awards',
            'expected_rel' => 'sponsored',
        ]);
    }

    public function test_destroy_removes_link_from_watchlist(): void
    {
        /** @var MonitoredBacklink $link */
        $link = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://delete-me.org/bad-link',
            'source_url_hash' => hash('sha256', 'https://delete-me.org/bad-link'),
            'target_url' => 'https://coquiseo.com/',
            'state' => 'lost',
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/{$link->uuid}");

        $response->assertRedirect(route('workspaces.projects.backlinks.watchlist.index', [$this->workspace, $this->project]));

        $this->assertDatabaseMissing('monitored_backlinks', [
            'id' => $link->id,
        ]);
    }

    public function test_the_worker_is_told_which_page_each_link_should_point_at(): void
    {
        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'worker-secret');

        /** @var MonitoredBacklink $link */
        $link = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://partner.com/review',
            'source_url_hash' => hash('sha256', 'https://partner.com/review'),
            'target_url' => 'https://coquiseo.com/pricing',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ]);

        Http::fake([
            'http://coqui-worker:8085/jobs/inspect' => Http::response(['results' => [[
                'url' => 'https://partner.com/review',
                'status' => 200,
                'hasTargetLink' => false,
                'observedRel' => null,
                'noindex' => false,
            ]]], 200),
        ]);

        $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/{$link->uuid}/verify");

        $this->assertSame('lost', $link->fresh()->state);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://coqui-worker:8085/jobs/inspect'
            && json_decode($request->body(), true)['targetUrls'] === ['https://partner.com/review' => 'https://coquiseo.com/pricing']);
    }

    public function test_an_inconclusive_worker_result_is_checked_directly(): void
    {
        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'worker-secret');

        /** @var MonitoredBacklink $link */
        $link = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://partner.com/huge-page',
            'source_url_hash' => hash('sha256', 'https://partner.com/huge-page'),
            'target_url' => 'https://coquiseo.com/',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ]);

        Http::fake([
            'http://coqui-worker:8085/jobs/inspect' => Http::response(['results' => [[
                'url' => 'https://partner.com/huge-page',
                'status' => null,
                'hasTargetLink' => false,
                'observedRel' => null,
                'noindex' => false,
                'error' => "page is larger than 2 MB and the link wasn't in the part read",
            ]]], 200),
            'https://partner.com/huge-page' => Http::response('<html><body><a href="https://coquiseo.com/">Coqui</a></body></html>', 200),
        ]);

        $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/{$link->uuid}/verify");

        $this->assertSame('active', $link->fresh()->state);
    }

    private function monitor(string $sourceUrl, array $attributes = []): MonitoredBacklink
    {
        /** @var MonitoredBacklink $link */
        $link = MonitoredBacklink::query()->create(array_merge([
            'project_id' => $this->project->id,
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
            'target_url' => 'https://coquiseo.com/',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ], $attributes));

        return $link;
    }

    private function useWorker(): void
    {
        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'worker-secret');
    }

    public function test_a_failing_alert_broadcast_does_not_stop_the_run(): void
    {
        // Stands in for Reverb being down when the alert is broadcast.
        Event::listen(BacklinkAlertTriggered::class, fn () => throw new RuntimeException('Reverb unavailable'));

        $first = $this->monitor('https://site-a.com/gone');
        $second = $this->monitor('https://site-b.com/gone');

        Http::fake([
            'https://site-a.com/*' => Http::response('<html><body>No links</body></html>', 200),
            'https://site-b.com/*' => Http::response('<html><body>No links</body></html>', 200),
        ]);

        $verified = app(BacklinkVerificationService::class)->verifyProjectWatchlist($this->project);

        $this->assertSame(2, $verified);
        $this->assertSame('lost', $first->fresh()->state);
        $this->assertSame('lost', $second->fresh()->state);
        $this->assertDatabaseCount('project_activities', 2);
    }

    public function test_a_blocked_url_is_left_out_of_the_worker_batch_instead_of_failing_it(): void
    {
        $this->useWorker();
        $public = $this->monitor('https://partner.com/review');
        $private = $this->monitor('http://10.0.0.5/internal');

        Http::fake([
            'http://coqui-worker:8085/jobs/inspect' => Http::response(['results' => [[
                'url' => 'https://partner.com/review',
                'status' => 200,
                'hasTargetLink' => true,
                'observedRel' => 'dofollow',
                'observedAnchor' => 'Coqui',
                'noindex' => false,
            ]]], 200),
        ]);

        app(BacklinkVerificationService::class)->verifyProjectWatchlist($this->project);

        $this->assertSame('active', $public->fresh()->state);
        $this->assertSame('error', $private->fresh()->state);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://coqui-worker:8085/jobs/inspect'
            && json_decode($request->body(), true)['urls'] === ['https://partner.com/review']);
        // The worker answered for the public page: nothing was fetched one by one.
        Http::assertSentCount(1);
    }

    public function test_a_changed_anchor_is_flagged_from_the_worker_and_from_a_direct_check(): void
    {
        $this->useWorker();
        $changed = $this->monitor('https://partner.com/review', ['expected_anchor' => 'Coqui SEO platform']);
        $sameText = $this->monitor('https://partner.com/roundup', ['expected_anchor' => 'Coqui  SEO']);
        $direct = $this->monitor('https://blog.example/post', ['expected_anchor' => 'Coqui SEO']);

        Http::fake([
            'http://coqui-worker:8085/jobs/inspect' => Http::response(['results' => [
                ['url' => 'https://partner.com/review', 'status' => 200, 'hasTargetLink' => true, 'observedRel' => 'dofollow', 'observedAnchor' => 'cheap widgets', 'noindex' => false],
                ['url' => 'https://partner.com/roundup', 'status' => 200, 'hasTargetLink' => true, 'observedRel' => 'dofollow', 'observedAnchor' => 'coqui seo', 'noindex' => false],
                // Left out by the worker: checked directly.
            ]], 200),
            'https://blog.example/post' => Http::response('<html><body><a href="https://coquiseo.com/">  Click   here </a></body></html>', 200),
        ]);

        app(BacklinkVerificationService::class)->verifyProjectWatchlist($this->project);

        $this->assertSame('anchor_changed', $changed->fresh()->state);
        $this->assertSame('cheap widgets', $changed->fresh()->last_observed_anchor);
        // Case and spacing don't count as a change.
        $this->assertSame('active', $sameText->fresh()->state);
        $this->assertSame('anchor_changed', $direct->fresh()->state);
        $this->assertSame('Click here', $direct->fresh()->last_observed_anchor);

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist")
            ->assertInertia(fn ($page) => $page->where('metrics.anchor_changed', 2));
    }

    public function test_verify_all_queues_batched_jobs_on_the_backlinks_queue(): void
    {
        Queue::fake();
        $this->monitor('https://site-a.com/page');
        $this->monitor('https://site-b.com/page');

        $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/verify")
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushedOn('backlinks', VerifyMonitoredBacklinksJob::class, fn (VerifyMonitoredBacklinksJob $job): bool => $job->projectId === $this->project->id
            && count($job->linkIds) === 2);
    }

    public function test_a_run_checks_one_batch_and_queues_the_rest(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $ids = [];
        foreach (range(1, VerifyMonitoredBacklinksJob::BATCH + 5) as $i) {
            $ids[] = $this->monitor("https://site-{$i}.com/page")->id;
        }

        Queue::fake();
        (new VerifyMonitoredBacklinksJob($this->project->id, $ids))->handle(app(BacklinkVerificationService::class));

        $this->assertSame(VerifyMonitoredBacklinksJob::BATCH, MonitoredBacklink::query()->where('state', 'error')->count());
        Queue::assertPushed(VerifyMonitoredBacklinksJob::class, fn (VerifyMonitoredBacklinksJob $job): bool => $job->linkIds === array_slice($ids, VerifyMonitoredBacklinksJob::BATCH));
    }

    public function test_direct_checks_stop_at_the_deadline_and_return_what_is_left(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $links = collect([
            $this->monitor('https://site-a.com/page'),
            $this->monitor('https://site-b.com/page'),
            $this->monitor('https://site-c.com/page'),
        ]);

        // Already past the deadline: one link is still checked, so every run makes progress.
        $left = app(BacklinkVerificationService::class)->verifyLinks($this->project, $links, microtime(true) - 1);

        $this->assertCount(2, $left);
        Http::assertSentCount(1);
    }

    public function test_direct_checks_spread_out_and_pace_pages_on_the_same_host(): void
    {
        config()->set('coqui.backlinks.verify_host_delay_ms', 150);
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $this->monitor('https://busy.example/one');
        $this->monitor('https://busy.example/two');
        $this->monitor('https://other.example/one');

        $started = microtime(true);
        app(BacklinkVerificationService::class)->verifyProjectWatchlist($this->project);

        $urls = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->all();
        $this->assertSame(['https://busy.example/one', 'https://other.example/one', 'https://busy.example/two'], $urls);
        $this->assertGreaterThanOrEqual(0.15, microtime(true) - $started);
    }

    public function test_a_slot_reserved_by_another_run_pushes_the_next_request_back(): void
    {
        config()->set('coqui.backlinks.verify_host_delay_ms', 150);
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $this->monitor('https://busy.example/one');

        // Another worker has already reserved the next slot for this host.
        $key = 'backlink-verify:host:'.md5('busy.example');
        $reserved = microtime(true) + 0.1;
        Cache::put($key, $reserved, now()->addMinutes(5));

        $started = microtime(true);
        app(BacklinkVerificationService::class)->verifyProjectWatchlist($this->project);

        // It waited for that slot plus the gap, and reserved its own after it.
        $this->assertGreaterThanOrEqual(0.25, microtime(true) - $started);
        $this->assertEqualsWithDelta($reserved + 0.15, (float) Cache::get($key), 0.001);
    }

    public function test_pacing_never_waits_past_the_deadline(): void
    {
        config()->set('coqui.backlinks.verify_host_delay_ms', 150);
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $link = $this->monitor('https://busy.example/one');

        // Another run holds this host's slots for the next ten seconds.
        $key = 'backlink-verify:host:'.md5('busy.example');
        $reserved = microtime(true) + 10;
        Cache::put($key, $reserved, now()->addMinutes(5));

        $started = microtime(true);
        $left = app(BacklinkVerificationService::class)->verifyLinks($this->project, collect([$link]), microtime(true) + 0.5);

        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertCount(1, $left);
        Http::assertNothingSent();
        // No slot was taken for a request that didn't happen.
        $this->assertEqualsWithDelta($reserved, (float) Cache::get($key), 0.001);
    }

    public function test_a_run_including_one_last_direct_check_fits_inside_the_job_timeout(): void
    {
        $job = new VerifyMonitoredBacklinksJob($this->project->id, []);

        $this->assertLessThan(
            $job->timeout,
            VerifyMonitoredBacklinksJob::BUDGET_SECONDS + BacklinkVerificationService::DIRECT_INSPECTION_MAX_SECONDS,
        );
    }

    public function test_the_scheduled_command_queues_by_default_and_can_run_inline(): void
    {
        $this->monitor('https://site-a.com/page');

        Queue::fake();
        $this->artisan('coqui:verify-backlinks')
            ->expectsOutputToContain('Queued verification of 1 monitored backlinks')
            ->assertSuccessful();
        Queue::assertPushed(VerifyMonitoredBacklinksJob::class);

        Http::fake(['*' => Http::response('Not Found', 404)]);
        $this->artisan('coqui:verify-backlinks', ['--sync' => true])
            ->expectsOutputToContain('Verified 1 monitored backlinks')
            ->assertSuccessful();
    }

    public function test_imports_and_adds_respect_the_per_project_link_cap(): void
    {
        config()->set('coqui.backlinks.max_monitored_links', 2);
        Http::fake(['*' => Http::response('<html><body><a href="https://coquiseo.com/">Coqui</a></body></html>', 200)]);
        $this->monitor('https://existing.com/page');

        $csv = "source_url,target_url\nhttps://existing.com/page,https://coquiseo.com/\nhttps://new-one.com/a,https://coquiseo.com/\nhttps://new-two.com/b,https://coquiseo.com/\n";
        $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist/import-csv", [
                'csv_file' => UploadedFile::fake()->createWithContent('links.csv', $csv),
            ])
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 more were skipped'));

        $this->assertSame(2, $this->project->monitoredBacklinks()->count());

        $base = "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/watchlist";
        $this->actingAs($this->user)->post($base, ['source_url' => 'https://another.com/page'])->assertSessionHas('error');
        $this->assertSame(2, $this->project->monitoredBacklinks()->count());

        // Updating a link that's already monitored is still allowed at the cap.
        $this->actingAs($this->user)->post($base, ['source_url' => 'https://existing.com/page', 'expected_anchor' => 'Coqui'])->assertSessionHas('success');
    }
}
