<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Models\CrawlUrl;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacklinkProfileTest extends TestCase
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

    public function test_backlink_profile_page_renders_with_inertia(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'target' => 'coquiseo.com',
                                'rank' => 450,
                                'backlinks' => 1250,
                                'referring_pages' => 890,
                                'referring_domains' => 120,
                                'referring_main_domains' => 110,
                                'dofollow' => 1000,
                                'nofollow' => 250,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/referring_domains/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'domain' => 'techcrunch.com',
                                        'rank' => 850,
                                        'backlinks' => 15,
                                        'dofollow' => 15,
                                        'nofollow' => 0,
                                        'spam_score' => 2,
                                        'first_seen' => '2025-01-10T00:00:00Z',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/anchors/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'anchor' => 'coqui seo platform',
                                        'backlinks' => 45,
                                        'referring_domains' => 20,
                                        'dofollow' => 40,
                                        'nofollow' => 5,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'page' => 'https://coquiseo.com/',
                                        'rank' => 380,
                                        'backlinks' => 500,
                                        'referring_domains' => 80,
                                        'dofollow' => 450,
                                        'nofollow' => 50,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/history/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'date' => '2026-11-01',
                                        'backlinks' => 1250,
                                        'referring_domains' => 120,
                                        'new_backlinks' => 20,
                                        'lost_backlinks' => 5,
                                        'new_referring_domains' => 3,
                                        'lost_referring_domains' => 1,
                                        'rank' => 450,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Backlinks/Index')
            ->has('workspace')
            ->has('project')
            ->has('summary')
            ->has('referringDomains')
            ->has('anchors')
            ->has('topPages')
            ->has('brokenPages')
            ->where('summary.rank', 450)
            ->where('summary.backlinks', 1250)
            ->where('summary.referring_domains', 120)
        );
    }

    public function test_unauthorized_user_cannot_view_or_refresh_backlinks(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks");

        $response->assertForbidden();

        $postResponse = $this->actingAs($otherUser)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/refresh");

        $postResponse->assertForbidden();
    }

    public function test_backlinks_are_cached_for_14_days_in_database(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'rank' => 300,
                                'backlinks' => 400,
                                'referring_domains' => 50,
                                'referring_main_domains' => 45,
                                'referring_pages' => 200,
                                'dofollow' => 300,
                                'nofollow' => 100,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/referring_domains/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/anchors/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/history/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'date' => now()->toDateString(),
                                        'backlinks' => 400,
                                        'referring_domains' => 50,
                                        'new_backlinks' => 5,
                                        'lost_backlinks' => 2,
                                        'new_referring_domains' => 2,
                                        'lost_referring_domains' => 1,
                                        'rank' => 300,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // First request: calls DataForSEO
        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks");

        $response->assertOk();
        $this->assertDatabaseHas('dataforseo_cache', [
            'endpoint' => '/v3/backlinks/summary/live',
        ]);

        // Clear HTTP fakes to ensure second request serves from cache without making network requests
        Http::fake([
            '*' => fn () => throw new \RuntimeException('Should not be called!'),
        ]);

        $cachedResponse = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks");

        $cachedResponse->assertOk();
        $cachedResponse->assertInertia(fn ($page) => $page
            ->where('summary.cached', true)
            ->where('summary.backlinks', 400)
        );
    }

    public function test_joining_backlink_pages_against_latest_crawl_detects_high_authority_404(): void
    {
        /** @var CrawlRun $crawlRun */
        $crawlRun = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '00000000-0000-0000-0000-000000000001',
            'seed' => 'https://coquiseo.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'source' => 'cloud',
            'config' => ['maxDepth' => 3, 'maxUrls' => 500],
            'total_urls' => 2,
            'finalized_at' => now(),
        ]);

        CrawlUrl::query()->create([
            'crawl_run_id' => $crawlRun->id,
            'url' => 'https://coquiseo.com/deleted-seo-checklist',
            'url_hash' => hash('sha256', 'https://coquiseo.com/deleted-seo-checklist'),
            'status' => 404,
        ]);

        CrawlUrl::query()->create([
            'crawl_run_id' => $crawlRun->id,
            'url' => 'https://coquiseo.com/features',
            'url_hash' => hash('sha256', 'https://coquiseo.com/features'),
            'status' => 200,
        ]);

        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'rank' => 500,
                                'backlinks' => 100,
                                'referring_domains' => 20,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/referring_domains/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/anchors/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'page' => 'https://coquiseo.com/deleted-seo-checklist',
                                        'rank' => 35,
                                        'backlinks' => 18,
                                        'referring_domains' => 7,
                                        'dofollow' => 15,
                                        'nofollow' => 3,
                                    ],
                                    [
                                        'page' => 'https://coquiseo.com/features',
                                        'rank' => 25,
                                        'backlinks' => 50,
                                        'referring_domains' => 12,
                                        'dofollow' => 45,
                                        'nofollow' => 5,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('brokenPages', 1)
            ->where('brokenPages.0.url', 'https://coquiseo.com/deleted-seo-checklist')
            ->where('brokenPages.0.crawl_status', 404)
            ->where('brokenPages.0.backlinks', 18)
            ->where('brokenPages.0.redirect_priority', 'high')
        );
    }

    public function test_refresh_action_forces_fresh_data_and_logs_activity(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'rank' => 520,
                                'backlinks' => 2100,
                                'referring_domains' => 190,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/referring_domains/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/anchors/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/history/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'date' => now()->toDateString(),
                                        'backlinks' => 400,
                                        'referring_domains' => 50,
                                        'new_backlinks' => 5,
                                        'lost_backlinks' => 2,
                                        'new_referring_domains' => 2,
                                        'lost_referring_domains' => 1,
                                        'rank' => 300,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/refresh");

        $response->assertRedirect(route('workspaces.projects.backlinks.index', [$this->workspace, $this->project]));

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'backlinks.refreshed',
        ]);
    }
}
