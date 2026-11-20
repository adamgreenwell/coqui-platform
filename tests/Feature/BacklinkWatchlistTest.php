<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Events\BacklinkAlertTriggered;
use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
}
