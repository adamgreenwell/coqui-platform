<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
            // Broken backlinks: one row per link, grouped into pages.
            'https://sandbox.dataforseo.com/v3/backlinks/backlinks/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    ['url_to' => 'https://competitor-a.com/discontinued-guide', 'url_to_status_code' => 404, 'domain_from' => 'blog-one.com', 'dofollow' => true],
                                    ['url_to' => 'https://competitor-a.com/discontinued-guide', 'url_to_status_code' => 404, 'domain_from' => 'blog-one.com', 'dofollow' => false],
                                    ['url_to' => 'https://competitor-a.com/discontinued-guide', 'url_to_status_code' => 404, 'domain_from' => 'news-two.com', 'dofollow' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/intersect");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('configuredCompetitors', 2)
            ->has('activeCompetitors', 2)
            ->has('opportunities', 1)
            ->where('opportunities.0.domain', 'tech-roundup.org')
            ->where('opportunities.0.rank', 620)
            ->where('opportunities.0.intersections_count', 2)
            ->has('brokenReclaim', 2)
            ->where('brokenReclaim.0.path', '/discontinued-guide')
            ->where('brokenReclaim.0.status_code', 404)
            ->where('brokenReclaim.0.backlinks', 3)
            ->where('brokenReclaim.0.referring_domains', 2)
            ->where('brokenReclaim.0.dofollow', 2)
            ->where('brokenReclaim.0.dofollow_ratio', 67)
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
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/intersect");

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
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/intersect");

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
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/intersect?min_intersections=2&max_spam=30");

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
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/intersect/refresh", [
                'competitors' => ['competitor-a.com', 'competitor-b.com'],
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'backlinks.intersect_refreshed',
        ]);
    }

    public function test_broken_reclaim_asks_for_broken_backlinks_only(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/domain_intersection/live' => Http::response(['status_code' => 20000, 'tasks' => [['result' => [['items' => []]]]]], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/backlinks/live' => Http::response(['status_code' => 20000, 'tasks' => [['result' => [['items' => []]]]]], 200),
        ]);

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/intersect")
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v3/backlinks/backlinks/live')) {
                return false;
            }
            $task = $request->data()[0] ?? [];

            return ($task['filters'] ?? null) === ['is_broken', '=', true];
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v3/backlinks/pages/live'));
    }

    public function test_a_domain_without_an_intersection_map_is_not_counted_as_linking_to_every_competitor(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/domain_intersection/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => [
                    ['domain' => 'no-map.org', 'rank' => 500, 'backlinks' => 10, 'spam_score' => 1],
                    ['domain' => 'one-link.org', 'rank' => 500, 'backlinks' => 4, 'spam_score' => 1, 'intersection' => ['1' => ['backlinks' => 4], '2' => null]],
                ]]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/backlinks/live' => Http::response(['status_code' => 20000, 'tasks' => [['result' => [['items' => []]]]]], 200),
        ]);

        $url = "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/intersect";

        $this->actingAs($this->user)->get($url.'?min_intersections=1')
            ->assertInertia(fn ($page) => $page
                ->has('opportunities', 2)
                ->where('opportunities', fn ($rows): bool => collect($rows)->firstWhere('domain', 'no-map.org')['intersections_count'] === null
                    && collect($rows)->firstWhere('domain', 'no-map.org')['linked_competitors'] === []
                    && collect($rows)->firstWhere('domain', 'one-link.org')['intersections_count'] === 1));

        // The default (links to at least two competitors) excludes both.
        $this->actingAs($this->user)->get($url)
            ->assertInertia(fn ($page) => $page->has('opportunities', 0));
    }

    public function test_the_same_competitors_in_another_order_share_one_cached_request(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/domain_intersection/live' => Http::response(['status_code' => 20000, 'tasks' => [['result' => [['items' => []]]]]], 200),
        ]);

        $client = app(DataForSeoClient::class);
        $client->backlinksDomainIntersection(['1' => 'Beta.com', '2' => 'alpha.com'], ['acme.com'], $this->project);
        $client->backlinksDomainIntersection(['1' => 'alpha.com', '2' => 'beta.com'], ['acme.com'], $this->project);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => ($request->data()[0]['targets'] ?? null) === ['1' => 'alpha.com', '2' => 'beta.com']);
    }

    public function test_broken_reclaim_counts_from_a_sample_are_marked_as_minimums(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/domain_intersection/live' => Http::response(['status_code' => 20000, 'tasks' => [['result' => [['items' => []]]]]], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/backlinks/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [[
                    'total_count' => 4200,
                    'items' => [
                        ['url_to' => 'https://competitor-a.com/old', 'url_to_status_code' => 404, 'domain_from' => 'one.com', 'dofollow' => true],
                    ],
                ]]]],
            ], 200),
        ]);

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks/intersect")
            ->assertInertia(fn ($page) => $page
                ->where('brokenReclaim.0.sampled', true)
                ->where('brokenReclaim.0.pitch_idea', fn (string $pitch): bool => str_contains($pitch, 'at least 1 broken backlinks')));
    }
}
