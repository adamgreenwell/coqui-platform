<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacklinkIntersectTest extends TestCase
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

        // Configure 2 competitors
        ProjectCompetitor::query()->create([
            'project_id' => $this->project->id,
            'domain' => 'competitor-a.com',
        ]);

        ProjectCompetitor::query()->create([
            'project_id' => $this->project->id,
            'domain' => 'competitor-b.com',
        ]);
    }

    public function test_intersect_page_renders_with_opportunities_and_broken_reclaim(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/domain_intersection/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'domain' => 'tech-roundup.org',
                                        'rank' => 620,
                                        'backlinks' => 45,
                                        'spam_score' => 5,
                                        'first_seen' => '2025-01-01',
                                        'intersection' => [
                                            '1' => ['backlinks' => 25],
                                            '2' => ['backlinks' => 20],
                                        ],
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
                                        'page' => 'https://competitor-a.com/discontinued-guide',
                                        'status_code' => 404,
                                        'backlinks' => 38,
                                        'referring_domains' => 14,
                                        'dofollow' => 30,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks/intersect");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('configuredCompetitors', 2)
            ->has('activeCompetitors', 2)
            ->has('opportunities', 1)
            ->where('opportunities.0.domain', 'tech-roundup.org')
            ->where('opportunities.0.rank', 620)
            ->where('opportunities.0.intersections_count', 2)
            ->has('brokenReclaim', 1)
            ->where('brokenReclaim.0.path', '/discontinued-guide')
            ->where('brokenReclaim.0.status_code', 404)
            ->where('brokenReclaim.0.backlinks', 38)
            ->where('metrics.total_opportunities', 1)
            ->where('metrics.high_authority', 1)
        );
    }

    public function test_results_are_cached_for_competitor_set_and_prevent_duplicate_charges(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/domain_intersection/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'domain' => 'industry-news.com',
                                        'rank' => 540,
                                        'backlinks' => 12,
                                        'spam_score' => 2,
                                        'intersection' => [
                                            '1' => ['backlinks' => 6],
                                            '2' => ['backlinks' => 6],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
        ]);

        // First request calls DataForSEO
        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks/intersect");

        $response->assertOk();

        // Verify cached in database
        $this->assertDatabaseHas('dataforseo_cache', [
            'endpoint' => '/v3/backlinks/domain_intersection/live',
        ]);

        // Second request should serve from cache without calling DataForSEO
        Http::fake([
            '*' => fn () => throw new \RuntimeException('Should not make outbound API request!'),
        ]);

        $cachedResponse = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks/intersect");

        $cachedResponse->assertOk();
        $cachedResponse->assertInertia(fn ($page) => $page
            ->has('opportunities', 1)
            ->where('opportunities.0.domain', 'industry-news.com')
        );
    }

    public function test_filtering_by_min_intersections_and_spam_ceiling(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/domain_intersection/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    // Linked to only 1 competitor
                                    [
                                        'domain' => 'solo-fan.com',
                                        'rank' => 400,
                                        'backlinks' => 10,
                                        'spam_score' => 5,
                                        'intersection' => ['1' => ['backlinks' => 10]],
                                    ],
                                    // Linked to 2 competitors but high spam
                                    [
                                        'domain' => 'spammer.xyz',
                                        'rank' => 300,
                                        'backlinks' => 50,
                                        'spam_score' => 75,
                                        'intersection' => ['1' => ['backlinks' => 25], '2' => ['backlinks' => 25]],
                                    ],
                                    // Valid intersect: linked to 2 competitors, clean spam
                                    [
                                        'domain' => 'quality-hub.com',
                                        'rank' => 580,
                                        'backlinks' => 30,
                                        'spam_score' => 10,
                                        'intersection' => ['1' => ['backlinks' => 15], '2' => ['backlinks' => 15]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
        ]);

        // Request with min_intersections = 2, max_spam = 30
        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks/intersect?min_intersections=2&max_spam=30");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('opportunities', 1)
            ->where('opportunities.0.domain', 'quality-hub.com')
        );
    }

    public function test_refresh_action_forces_fresh_data_and_logs_activity(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/domain_intersection/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'domain' => 'fresh-partner.com',
                                        'rank' => 700,
                                        'backlinks' => 80,
                                        'spam_score' => 0,
                                        'intersection' => ['1' => ['backlinks' => 40], '2' => ['backlinks' => 40]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks/intersect/refresh", [
                'competitors' => ['competitor-a.com', 'competitor-b.com'],
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'backlinks.intersect_refreshed',
        ]);
    }
}
