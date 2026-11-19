<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeywordGapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    private ProjectCompetitor $competitor1;

    private ProjectCompetitor $competitor2;

    private DataForSeoCacheRepository $cacheRepo;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'mock-login');
        config()->set('coqui.dataforseo.password', 'mock-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');

        $this->user = User::factory()->create();

        $this->workspace = Workspace::query()->create([
            'name' => 'SEO Agency',
            'slug' => 'seo-agency',
            'owner_id' => $this->user->id,
            'monthly_dfs_budget_usd' => 50.00,
        ]);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui Analytics',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);

        $this->competitor1 = ProjectCompetitor::query()->create([
            'project_id' => $this->project->id,
            'domain' => 'ahrefs.com',
            'label' => 'Ahrefs',
            'sort_order' => 1,
        ]);

        $this->competitor2 = ProjectCompetitor::query()->create([
            'project_id' => $this->project->id,
            'domain' => 'semrush.com',
            'label' => 'Semrush',
            'sort_order' => 2,
        ]);

        $this->cacheRepo = app(DataForSeoCacheRepository::class);
    }

    public function test_renders_keyword_gap_inertia_page_with_competitors_and_tracked_keywords(): void
    {
        TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'serp intelligence',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Keywords/Gap')
            ->has('workspace')
            ->has('project')
            ->where('project.domain', 'coquiseo.com')
            ->has('competitors', 2)
            ->where('competitors.0.domain', 'ahrefs.com')
            ->where('trackedKeywords', ['serp intelligence:desktop:2840:en'])
            ->where('defaultLocationCode', 2840)
            ->where('defaultLanguageCode', 'en')
        );
    }

    public function test_analyzes_keyword_gap_and_categorizes_missing_vs_weak_vs_shared(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/dataforseo_labs/google/domain_intersection/live' => function ($request) {
                $body = $request->data();
                $target1 = $body[0]['target1'] ?? '';
                $intersections = $body[0]['intersections'] ?? true;

                // If intersections is false: target1=ahrefs.com, target2=coquiseo.com (Missing query)
                if (! $intersections) {
                    return Http::response([
                        'status_code' => 20000,
                        'cost' => 0.01212,
                        'tasks' => [
                            [
                                'status_code' => 20000,
                                'result' => [
                                    [
                                        'items' => [
                                            [
                                                'keyword_data' => [
                                                    'keyword' => 'backlink checker tool',
                                                    'keyword_info' => [
                                                        'search_volume' => 14000,
                                                        'cpc' => 4.20,
                                                        'competition' => 0.85,
                                                    ],
                                                    'keyword_properties' => [
                                                        'keyword_difficulty' => 45,
                                                    ],
                                                    'search_intent_info' => [
                                                        'main_intent' => 'commercial',
                                                    ],
                                                ],
                                                'first_domain_serp_element' => [
                                                    'rank_group' => 3,
                                                    'url' => 'https://ahrefs.com/backlink-checker',
                                                ],
                                                'second_domain_serp_element' => null,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                // If intersections is true: target1=coquiseo.com, target2=ahrefs.com (Overlapping query)
                return Http::response([
                    'status_code' => 20000,
                    'cost' => 0.01212,
                    'tasks' => [
                        [
                            'status_code' => 20000,
                            'result' => [
                                [
                                    'items' => [
                                        [
                                            'keyword_data' => [
                                                'keyword' => 'rank tracking software',
                                                'keyword_info' => [
                                                    'search_volume' => 8100,
                                                    'cpc' => 6.50,
                                                ],
                                                'keyword_properties' => [
                                                    'keyword_difficulty' => 60,
                                                ],
                                                'search_intent_info' => [
                                                    'main_intent' => 'commercial',
                                                ],
                                            ],
                                            'first_domain_serp_element' => [
                                                'rank_group' => 12,
                                                'url' => 'https://coquiseo.com/rank-tracker',
                                            ],
                                            'second_domain_serp_element' => [
                                                'rank_group' => 2,
                                                'url' => 'https://ahrefs.com/rank-tracker',
                                            ],
                                        ],
                                        [
                                            'keyword_data' => [
                                                'keyword' => 'serp analyzer free',
                                                'keyword_info' => [
                                                    'search_volume' => 3200,
                                                ],
                                                'keyword_properties' => [
                                                    'keyword_difficulty' => 35,
                                                ],
                                                'search_intent_info' => [
                                                    'main_intent' => 'informational',
                                                ],
                                            ],
                                            'first_domain_serp_element' => [
                                                'rank_group' => 5,
                                                'url' => 'https://coquiseo.com/serp-analyzer',
                                            ],
                                            'second_domain_serp_element' => [
                                                'rank_group' => 5,
                                                'url' => 'https://ahrefs.com/serp-checker',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/search", [
                'competitors' => ['ahrefs.com'],
                'location_code' => 2840,
                'language_code' => 'en',
                'gap_mode' => 'all',
            ]);

        $response->assertOk();
        $response->assertJsonPath('summary.total_keywords', 3);
        $response->assertJsonPath('summary.missing_count', 1);
        $response->assertJsonPath('summary.weak_count', 1);
        $response->assertJsonPath('summary.shared_count', 1);

        $keywords = $response->json('keywords');
        $this->assertCount(3, $keywords);

        // Check missing keyword: competitor in top 20 (rank 3), project absent (null)
        $missingItem = collect($keywords)->firstWhere('keyword', 'backlink checker tool');
        $this->assertNotNull($missingItem);
        $this->assertSame('missing', $missingItem['gap_type']);
        $this->assertNull($missingItem['project_rank']);
        $this->assertSame(3, $missingItem['best_competitor_rank']);
        $this->assertGreaterThan(0, $missingItem['opportunity_score']);

        // Check weak keyword: competitor rank 2 outranks project rank 12
        $weakItem = collect($keywords)->firstWhere('keyword', 'rank tracking software');
        $this->assertNotNull($weakItem);
        $this->assertSame('weak', $weakItem['gap_type']);
        $this->assertSame(12, $weakItem['project_rank']);
        $this->assertSame(2, $weakItem['best_competitor_rank']);

        // Check shared keyword: both at rank 5
        $sharedItem = collect($keywords)->firstWhere('keyword', 'serp analyzer free');
        $this->assertNotNull($sharedItem);
        $this->assertSame('shared', $sharedItem['gap_type']);
        $this->assertSame(5, $sharedItem['project_rank']);
        $this->assertSame(5, $sharedItem['best_competitor_rank']);
    }

    public function test_caches_domain_pair_signatures_for_14_days_to_prevent_duplicate_spend(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/dataforseo_labs/google/domain_intersection/live' => Http::response([
                'status_code' => 20000,
                'cost' => 0.01212,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'keyword_data' => [
                                            'keyword' => 'cached gap query',
                                            'keyword_info' => ['search_volume' => 5000],
                                            'keyword_properties' => ['keyword_difficulty' => 40],
                                        ],
                                        'first_domain_serp_element' => ['rank_group' => 4, 'url' => 'https://ahrefs.com/cached'],
                                        'second_domain_serp_element' => null,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // First call: misses cache, executes API request
        $response1 = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/search", [
                'competitors' => ['ahrefs.com'],
                'gap_mode' => 'missing',
            ]);
        $response1->assertOk();
        Http::assertSentCount(1);

        // Second call: serves from cache, 0 new API calls sent
        Http::fake(); // Reset fake to ensure nothing is sent
        $response2 = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/search", [
                'competitors' => ['ahrefs.com'],
                'gap_mode' => 'missing',
            ]);
        $response2->assertOk();
        $response2->assertJsonPath('keywords.0.keyword', 'cached gap query');
        Http::assertNothingSent();
    }

    public function test_multi_competitor_comparison_aggregates_up_to_3_competitors(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/dataforseo_labs/google/domain_intersection/live' => function ($request) {
                $body = $request->data();
                $target1 = $body[0]['target1'] ?? '';

                $rank = $target1 === 'ahrefs.com' ? 4 : 2;

                return Http::response([
                    'status_code' => 20000,
                    'cost' => 0.012,
                    'tasks' => [
                        [
                            'status_code' => 20000,
                            'result' => [
                                [
                                    'items' => [
                                        [
                                            'keyword_data' => [
                                                'keyword' => 'seo audit tools',
                                                'keyword_info' => ['search_volume' => 9000],
                                                'keyword_properties' => ['keyword_difficulty' => 50],
                                            ],
                                            'first_domain_serp_element' => ['rank_group' => $rank, 'url' => "https://{$target1}/audit"],
                                            'second_domain_serp_element' => null,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/search", [
                'competitors' => ['ahrefs.com', 'semrush.com'],
                'gap_mode' => 'missing',
            ]);

        $response->assertOk();
        $item = $response->json('keywords.0');
        $this->assertSame('seo audit tools', $item['keyword']);
        $this->assertArrayHasKey('ahrefs.com', $item['competitor_ranks']);
        $this->assertArrayHasKey('semrush.com', $item['competitor_ranks']);
        $this->assertSame(4, $item['competitor_ranks']['ahrefs.com']['rank']);
        $this->assertSame(2, $item['competitor_ranks']['semrush.com']['rank']);
        $this->assertSame(2, $item['best_competitor_rank']);

        // Validation rejects more than 3 competitors
        $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/search", [
                'competitors' => ['c1.com', 'c2.com', 'c3.com', 'c4.com'],
            ])
            ->assertUnprocessable();

        // Validation rejects 0 competitors
        $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/search", [
                'competitors' => [],
            ])
            ->assertUnprocessable();
    }

    public function test_blocks_requests_exceeding_workspace_budget_caps(): void
    {
        config()->set('coqui.dataforseo.base_url', 'https://api.dataforseo.com');
        $this->workspace->update(['monthly_dfs_budget_usd' => 0.00]);

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/search", [
                'competitors' => ['ahrefs.com'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'budget_exceeded');
    }

    public function test_track_action_promotes_gap_keywords_to_rank_tracker_with_cadence_preservation(): void
    {
        // Existing keyword with daily cadence
        TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'existing gap kw',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'search_volume' => null,
            'keyword_difficulty' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/track", [
                'keywords' => [
                    [
                        'keyword' => 'existing gap kw',
                        'search_volume' => 7800,
                        'keyword_difficulty' => 48,
                    ],
                    [
                        'keyword' => 'brand new gap kw',
                        'search_volume' => 3100,
                        'keyword_difficulty' => 28,
                    ],
                ],
                'device' => 'desktop',
                'cadence' => 'weekly',
            ]);

        $response->assertOk();
        $response->assertJsonPath('count', 2);

        // Assert existing keyword preserved daily cadence and backfilled metrics
        $existing = TrackedKeyword::query()
            ->where('project_id', $this->project->id)
            ->where('keyword', 'existing gap kw')
            ->first();

        $this->assertNotNull($existing);
        $this->assertSame('daily', $existing->cadence);
        $this->assertSame(7800, $existing->search_volume);
        $this->assertSame(48, $existing->keyword_difficulty);

        // Assert new keyword created
        $newKw = TrackedKeyword::query()
            ->where('project_id', $this->project->id)
            ->where('keyword', 'brand new gap kw')
            ->first();

        $this->assertNotNull($newKw);
        $this->assertSame('weekly', $newKw->cadence);
        $this->assertSame(3100, $newKw->search_volume);
        $this->assertSame(28, $newKw->keyword_difficulty);

        // Assert activity logged
        $activity = ProjectActivity::query()
            ->where('project_id', $this->project->id)
            ->where('event_type', 'keyword.created')
            ->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Keyword Gap', $activity->title);
    }

    public function test_unauthorized_user_cannot_access_or_run_gap_analysis(): void
    {
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap")
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/search", [
                'competitors' => ['ahrefs.com'],
            ])
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-gap/track", [
                'keywords' => ['forbidden test'],
                'device' => 'desktop',
                'cadence' => 'weekly',
            ])
            ->assertForbidden();
    }
}
