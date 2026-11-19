<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeywordExplorerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    private DataForSeoCacheRepository $cacheRepo;

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

        $this->cacheRepo = app(DataForSeoCacheRepository::class);
    }

    public function test_keyword_explorer_page_renders_with_inertia_props(): void
    {
        TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'rank tracking tool',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'weekly',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Keywords/Explorer')
            ->has('workspace')
            ->has('project')
            ->where('project.domain', 'coquiseo.com')
            ->where('trackedKeywords', ['rank tracking tool'])
            ->where('defaultLocationCode', 2840)
            ->where('defaultLanguageCode', 'en')
        );
    }

    public function test_entering_50_cached_keywords_serves_instantly_with_zero_api_spend(): void
    {
        Http::fake();

        // Populate 50 cached keywords in the cache repository
        $keywords = [];
        for ($i = 1; $i <= 50; $i++) {
            $kw = "seo tool {$i}";
            $keywords[] = $kw;
            $this->cacheRepo->putKeywordMetrics(
                keyword: $kw,
                metrics: [
                    'keyword' => $kw,
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'search_volume' => 1000 * $i,
                    'cpc' => 2.50,
                    'competition' => 0.65,
                    'competition_level' => 'MEDIUM',
                    'keyword_difficulty' => 40,
                    'main_intent' => 'commercial',
                    'secondary_intents' => ['investigational'],
                    'monthly_searches' => [
                        ['year' => 2026, 'month' => 1, 'search_volume' => 1000 * $i],
                    ],
                ],
                locationCode: 2840,
                languageCode: 'en',
            );
        }

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/overview", [
                'keywords' => $keywords,
                'location_code' => 2840,
                'language_code' => 'en',
            ]);

        $response->assertOk();
        $response->assertJsonPath('summary.total_keywords', 50);
        $response->assertJsonPath('summary.cached_count', 50);
        $response->assertJsonPath('summary.fetched_count', 0);
        $response->assertJsonPath('summary.intent_breakdown.commercial', 50);

        // Verify zero HTTP requests were made to DataForSEO
        Http::assertNothingSent();
    }

    public function test_queries_dataforseo_only_for_cache_misses_and_persists_new_metrics(): void
    {
        // 20 cached keywords, 30 cache misses
        for ($i = 1; $i <= 20; $i++) {
            $kw = "cached keyword {$i}";
            $this->cacheRepo->putKeywordMetrics(
                keyword: $kw,
                metrics: [
                    'keyword' => $kw,
                    'location_code' => 2840,
                    'language_code' => 'en',
                    'search_volume' => 500,
                    'cpc' => 1.20,
                    'competition' => 0.40,
                    'competition_level' => 'LOW',
                    'keyword_difficulty' => 25,
                    'main_intent' => 'informational',
                    'secondary_intents' => [],
                    'monthly_searches' => [],
                ],
                locationCode: 2840,
                languageCode: 'en',
            );
        }

        $allKeywords = [];
        for ($i = 1; $i <= 20; $i++) {
            $allKeywords[] = "cached keyword {$i}";
        }
        for ($i = 1; $i <= 30; $i++) {
            $allKeywords[] = "miss keyword {$i}";
        }

        Http::fake([
            'https://sandbox.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live' => Http::response([
                'version' => '0.1.20230605',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => 0.0156,
                'tasks' => [
                    [
                        'id' => '06051515-2840-0066-0000-54b036511a51',
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items_count' => 30,
                                'items' => array_map(static fn (int $i): array => [
                                    'keyword' => "miss keyword {$i}",
                                    'keyword_info' => [
                                        'search_volume' => 2400,
                                        'cpc' => 3.10,
                                        'competition' => 0.80,
                                        'competition_level' => 'HIGH',
                                        'monthly_searches' => [
                                            ['year' => 2026, 'month' => 1, 'search_volume' => 2400],
                                        ],
                                    ],
                                    'keyword_properties' => [
                                        'keyword_difficulty' => 65,
                                    ],
                                    'search_intent_info' => [
                                        'main_intent' => 'transactional',
                                        'secondary_intents' => [],
                                    ],
                                ], range(1, 30)),
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/overview", [
                'keywords' => $allKeywords,
                'location_code' => 2840,
                'language_code' => 'en',
            ]);

        $response->assertOk();
        $response->assertJsonPath('summary.total_keywords', 50);
        $response->assertJsonPath('summary.cached_count', 20);
        $response->assertJsonPath('summary.fetched_count', 30);
        $response->assertJsonPath('summary.intent_breakdown.informational', 20);
        $response->assertJsonPath('summary.intent_breakdown.transactional', 30);

        // Verify DataForSEO was called exactly ONCE with only the 30 misses
        Http::assertSent(function ($request) {
            $body = $request->data();
            $sentKeywords = $body[0]['keywords'] ?? [];

            return count($sentKeywords) === 30 && in_array('miss keyword 1', $sentKeywords, true);
        });

        // Verify that the previously missed keywords are now cached
        $cachedCheck = $this->cacheRepo->getKeywordMetrics('miss keyword 1', 2840, 'en');
        $this->assertNotNull($cachedCheck);
        $this->assertSame(2400, $cachedCheck['search_volume']);
        $this->assertSame(65, $cachedCheck['keyword_difficulty']);
        $this->assertSame('transactional', $cachedCheck['main_intent']);
    }

    public function test_displays_volume_kd_intent_and_12_month_trend(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live' => Http::response([
                'version' => '0.1.20230605',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => 0.01212,
                'tasks' => [
                    [
                        'id' => '06051515-2840-0066-0000-54b036511a51',
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items_count' => 1,
                                'items' => [
                                    [
                                        'keyword' => 'serp intelligence software',
                                        'keyword_info' => [
                                            'search_volume' => 8100,
                                            'cpc' => 5.40,
                                            'competition' => 0.72,
                                            'competition_level' => 'HIGH',
                                            'monthly_searches' => [
                                                ['year' => 2025, 'month' => 2, 'search_volume' => 7000],
                                                ['year' => 2025, 'month' => 3, 'search_volume' => 7500],
                                                ['year' => 2026, 'month' => 1, 'search_volume' => 8100],
                                            ],
                                        ],
                                        'keyword_properties' => [
                                            'keyword_difficulty' => 58,
                                        ],
                                        'search_intent_info' => [
                                            'main_intent' => 'commercial',
                                            'secondary_intents' => ['investigational'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/overview", [
                'keywords' => 'serp intelligence software',
            ]);

        $response->assertOk();
        $response->assertJsonPath('keywords.0.keyword', 'serp intelligence software');
        $response->assertJsonPath('keywords.0.search_volume', 8100);
        $response->assertJsonPath('keywords.0.keyword_difficulty', 58);
        $response->assertJsonPath('keywords.0.main_intent', 'commercial');
        $response->assertJsonPath('keywords.0.monthly_searches.0.search_volume', 7000);
        $response->assertJsonPath('summary.avg_difficulty', 58);
        $response->assertJsonPath('summary.total_volume', 8100);
    }

    public function test_blocks_requests_exceeding_workspace_credit_caps(): void
    {
        // Set workspace monthly budget to $0.00
        $this->workspace->update(['monthly_dfs_budget_usd' => 0.00]);

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/overview", [
                'keywords' => ['uncached keyword'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'budget_exceeded');
    }

    public function test_related_keywords_discovery_fetches_and_persists_suggestions(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/dataforseo_labs/google/related_keywords/live' => Http::response([
                'version' => '0.1.20230605',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => 0.024,
                'tasks' => [
                    [
                        'id' => '06051515-2840-0066-0000-54b036511a51',
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items_count' => 2,
                                'items' => [
                                    [
                                        'keyword_data' => [
                                            'keyword' => 'best seo tool',
                                            'keyword_info' => [
                                                'search_volume' => 12000,
                                                'cpc' => 4.50,
                                                'competition' => 0.85,
                                                'competition_level' => 'HIGH',
                                                'monthly_searches' => [
                                                    ['year' => 2026, 'month' => 1, 'search_volume' => 12000],
                                                ],
                                            ],
                                            'keyword_properties' => [
                                                'keyword_difficulty' => 72,
                                            ],
                                            'search_intent_info' => [
                                                'main_intent' => 'commercial',
                                                'secondary_intents' => [],
                                            ],
                                        ],
                                    ],
                                    [
                                        'keyword_data' => [
                                            'keyword' => 'free seo audit tool',
                                            'keyword_info' => [
                                                'search_volume' => 6500,
                                                'cpc' => 1.80,
                                                'competition' => 0.55,
                                                'competition_level' => 'MEDIUM',
                                                'monthly_searches' => [
                                                    ['year' => 2026, 'month' => 1, 'search_volume' => 6500],
                                                ],
                                            ],
                                            'keyword_properties' => [
                                                'keyword_difficulty' => 38,
                                            ],
                                            'search_intent_info' => [
                                                'main_intent' => 'transactional',
                                                'secondary_intents' => [],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/related", [
                'keyword' => 'seo audit',
                'limit' => 50,
            ]);

        $response->assertOk();
        $response->assertJsonPath('seed', 'seo audit');
        $response->assertJsonCount(2, 'keywords');
        $response->assertJsonPath('keywords.0.keyword', 'best seo tool');
        $response->assertJsonPath('keywords.0.search_volume', 12000);
        $response->assertJsonPath('keywords.0.keyword_difficulty', 72);
        $response->assertJsonPath('keywords.1.keyword', 'free seo audit tool');
        $response->assertJsonPath('summary.total_keywords', 2);
        $response->assertJsonPath('summary.total_volume', 18500);

        // Verify individual keywords were stored in cache
        $cachedItem = $this->cacheRepo->getKeywordMetrics('best seo tool', 2840, 'en');
        $this->assertNotNull($cachedItem);
        $this->assertSame(12000, $cachedItem['search_volume']);
    }

    public function test_one_click_add_to_rank_tracker_persists_keyword_with_pre_filled_metrics(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/track", [
                'keywords' => [
                    [
                        'keyword' => 'local seo audit software',
                        'search_volume' => 4500,
                        'keyword_difficulty' => 44,
                    ],
                ],
                'device' => 'desktop',
                'cadence' => 'daily',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('count', 1);

        $tracked = TrackedKeyword::query()
            ->where('project_id', $this->project->id)
            ->where('keyword', 'local seo audit software')
            ->first();

        $this->assertNotNull($tracked);
        $this->assertSame('desktop', $tracked->device);
        $this->assertSame('daily', $tracked->cadence);
        $this->assertSame(4500, $tracked->search_volume);
        $this->assertSame(44, $tracked->keyword_difficulty);
        $this->assertTrue($tracked->is_active);

        $activity = ProjectActivity::query()
            ->where('project_id', $this->project->id)
            ->where('event_type', 'keyword.created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('Keyword Explorer', $activity->title);
    }

    public function test_bulk_add_to_rank_tracker_handles_multiple_keywords_and_populates_cached_metrics(): void
    {
        // Cache metrics for a keyword
        $this->cacheRepo->putKeywordMetrics(
            keyword: 'cached keyword auto',
            metrics: [
                'keyword' => 'cached keyword auto',
                'location_code' => 2840,
                'language_code' => 'en',
                'search_volume' => 9900,
                'keyword_difficulty' => 52,
            ],
            locationCode: 2840,
            languageCode: 'en',
        );

        $response = $this->actingAs($this->user)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/track", [
                'keywords' => [
                    'cached keyword auto',
                    [
                        'keyword' => 'explicit keyword',
                        'search_volume' => 3200,
                        'keyword_difficulty' => 31,
                    ],
                ],
                'device' => 'mobile',
                'cadence' => 'weekly',
            ]);

        $response->assertOk();
        $response->assertJsonPath('count', 2);

        $kw1 = TrackedKeyword::query()
            ->where('project_id', $this->project->id)
            ->where('keyword', 'cached keyword auto')
            ->first();
        $this->assertNotNull($kw1);
        $this->assertSame(9900, $kw1->search_volume);
        $this->assertSame(52, $kw1->keyword_difficulty);
        $this->assertSame('mobile', $kw1->device);

        $kw2 = TrackedKeyword::query()
            ->where('project_id', $this->project->id)
            ->where('keyword', 'explicit keyword')
            ->first();
        $this->assertNotNull($kw2);
        $this->assertSame(3200, $kw2->search_volume);
        $this->assertSame(31, $kw2->keyword_difficulty);
    }

    public function test_unauthorized_user_cannot_access_keyword_explorer_or_track_keywords(): void
    {
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer")
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/overview", [
                'keywords' => ['forbidden test'],
            ])
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->postJson("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/keyword-explorer/track", [
                'keywords' => ['forbidden test'],
            ])
            ->assertForbidden();
    }
}
