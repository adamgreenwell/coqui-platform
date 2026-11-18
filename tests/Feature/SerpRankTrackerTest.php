<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Actions\RecordSerpRankSnapshots;
use App\Domains\Serp\Events\SerpBatchCompleted;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\Services\SerpResultParser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SerpRankTrackerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    private ProjectCompetitor $competitor1;

    private ProjectCompetitor $competitor2;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'test-dfs-login');
        config()->set('coqui.dataforseo.password', 'test-dfs-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Agency Growth',
            'slug' => 'agency-growth',
            'owner_id' => $this->owner->id,
        ]);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui App',
            'domain' => 'coqui.io',
            'canonical_url' => 'https://coqui.io/',
            'location_code' => 2840,
            'language_code' => 'en',
            'crawl_cadence' => 'weekly',
        ]);

        $this->competitor1 = ProjectCompetitor::query()->create([
            'project_id' => $this->project->id,
            'domain' => 'competitor-a.com',
            'label' => 'Comp A',
            'sort_order' => 1,
        ]);

        $this->competitor2 = ProjectCompetitor::query()->create([
            'project_id' => $this->project->id,
            'domain' => 'competitor-b.org',
            'label' => 'Comp B',
            'sort_order' => 2,
        ]);
    }

    public function test_serp_result_parser_extracts_project_and_competitor_positions_ai_overview_and_snippets(): void
    {
        $parser = new SerpResultParser;

        $payload = [
            'tasks' => [
                [
                    'result' => [
                        [
                            'item_types' => ['organic', 'ai_overview', 'featured_snippet', 'people_also_ask'],
                            'items' => [
                                [
                                    'type' => 'ai_overview',
                                    'references' => [
                                        ['domain' => 'coqui.io', 'url' => 'https://coqui.io/docs/voice'],
                                        ['domain' => 'reference-source.com', 'url' => 'https://reference-source.com/speech'],
                                    ],
                                ],
                                [
                                    'type' => 'featured_snippet',
                                    'domain' => 'competitor-a.com',
                                    'url' => 'https://competitor-a.com/features',
                                    'rank_group' => 1,
                                    'rank_absolute' => 1,
                                    'title' => 'Top Voice Tools by Comp A',
                                ],
                                [
                                    'type' => 'people_also_ask',
                                    'items' => [
                                        ['question' => 'How does voice AI work?'],
                                    ],
                                ],
                                [
                                    'type' => 'organic',
                                    'domain' => 'coqui.io',
                                    'url' => 'https://coqui.io/voice-ai',
                                    'rank_group' => 2,
                                    'rank_absolute' => 3,
                                    'title' => 'Coqui Voice AI Platform',
                                ],
                                [
                                    'type' => 'organic',
                                    'domain' => 'random-other-domain.com',
                                    'url' => 'https://random-other-domain.com/blog',
                                    'rank_group' => 3,
                                    'rank_absolute' => 4,
                                    'title' => 'Other Voice Blog',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res = $parser->parse($payload, 'coqui.io', ['competitor-a.com', 'competitor-b.org']);

        $this->assertContains('ai_overview', $res['detected_features']);
        $this->assertContains('featured_snippet', $res['detected_features']);
        $this->assertContains('people_also_ask', $res['detected_features']);

        // Check project domain snapshot
        $projSnap = $res['domain_snapshots']['coqui.io'];
        $this->assertSame(2, $projSnap['rank_group']);
        $this->assertSame(3, $projSnap['rank_absolute']);
        $this->assertSame('https://coqui.io/voice-ai', $projSnap['ranking_url']);
        $this->assertTrue($projSnap['has_ai_overview']);
        $this->assertFalse($projSnap['has_featured_snippet']);

        // Check competitor A snapshot (owns featured snippet)
        $compASnap = $res['domain_snapshots']['competitor-a.com'];
        $this->assertSame(1, $compASnap['rank_group']);
        $this->assertSame(1, $compASnap['rank_absolute']);
        $this->assertTrue($compASnap['has_featured_snippet']);
        $this->assertFalse($compASnap['has_ai_overview']);

        // Check competitor B snapshot (unranked >100)
        $compBSnap = $res['domain_snapshots']['competitor-b.org'];
        $this->assertNull($compBSnap['rank_group']);
        $this->assertNull($compBSnap['rank_absolute']);
        $this->assertNull($compBSnap['ranking_url']);
        $this->assertFalse($compBSnap['has_ai_overview']);
        $this->assertFalse($compBSnap['has_featured_snippet']);
    }

    public function test_record_serp_rank_snapshots_persists_records_dispatches_event_and_logs_activity(): void
    {
        Event::fake([SerpBatchCompleted::class]);

        $keyword = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'ai voice cloning',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
        ]);

        $serpPayload = [
            'tasks' => [
                [
                    'result' => [
                        [
                            'items' => [
                                [
                                    'type' => 'organic',
                                    'domain' => 'coqui.io',
                                    'url' => 'https://coqui.io/cloning',
                                    'rank_group' => 4,
                                    'rank_absolute' => 4,
                                    'title' => 'Instant AI Voice Cloning',
                                ],
                                [
                                    'type' => 'organic',
                                    'domain' => 'competitor-a.com',
                                    'url' => 'https://competitor-a.com/cloning',
                                    'rank_group' => 7,
                                    'rank_absolute' => 8,
                                    'title' => 'Comp A Voice Clone',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $action = new RecordSerpRankSnapshots;
        $snapshots = $action->handle($keyword, $serpPayload, '2026-11-18');

        $this->assertCount(3, $snapshots); // project + 2 competitors

        $this->assertDatabaseHas('keyword_rank_snapshots', [
            'tracked_keyword_id' => $keyword->id,
            'project_id' => $this->project->id,
            'domain' => 'coqui.io',
            'rank_group' => 4,
            'ranking_url' => 'https://coqui.io/cloning',
        ]);

        $this->assertDatabaseHas('keyword_rank_snapshots', [
            'tracked_keyword_id' => $keyword->id,
            'project_id' => $this->project->id,
            'domain' => 'competitor-a.com',
            'rank_group' => 7,
        ]);

        $this->assertDatabaseHas('keyword_rank_snapshots', [
            'tracked_keyword_id' => $keyword->id,
            'project_id' => $this->project->id,
            'domain' => 'competitor-b.org',
            'rank_group' => null,
        ]);

        $this->assertSame(
            '2026-11-18',
            KeywordRankSnapshot::query()
                ->where('tracked_keyword_id', $keyword->id)
                ->where('domain', 'coqui.io')
                ->firstOrFail()
                ->checked_on
                ->toDateString()
        );

        $keyword->refresh();
        $this->assertNotNull($keyword->last_checked_at);

        Event::assertDispatched(SerpBatchCompleted::class, function (SerpBatchCompleted $e): bool {
            return $e->projectId === $this->project->id
                && $e->keywordCount === 1
                && $e->updatedCount === 3
                && $e->status === 'completed';
        });

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'serp.checked',
            'title' => 'Rank checked: ai voice cloning',
        ]);
    }

    public function test_run_rank_batches_command_submits_due_keywords_to_dataforseo(): void
    {
        config()->set('coqui.dataforseo.scheduled_spend', true);

        // Due keyword 1 (null last_checked_at)
        $kw1 = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'text to speech software',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
            'last_checked_at' => null,
        ]);

        // Due keyword 2 (older than daily cadence)
        $kw2 = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'voice generator mobile',
            'device' => 'mobile',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
            'last_checked_at' => now()->subHours(25),
        ]);

        // Not due keyword (checked 2 hours ago)
        TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'recent check',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
            'last_checked_at' => now()->subHours(2),
        ]);

        $externalTaskId1 = (string) Str::uuid();
        $externalTaskId2 = (string) Str::uuid();

        Http::fake([
            'https://sandbox.dataforseo.com/v3/serp/google/organic/task_post' => Http::response([
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    ['id' => $externalTaskId1, 'status_code' => 20000],
                    ['id' => $externalTaskId2, 'status_code' => 20000],
                ],
            ], 200),
        ]);

        $this->artisan('dataforseo:run-rank-batches', ['--project' => $this->project->id])
            ->assertSuccessful()
            ->expectsOutputToContain('Found 2 keywords due for rank tracking')
            ->expectsOutputToContain('Successfully submitted 2 SERP rank tracking tasks');

        $this->assertDatabaseHas('dataforseo_tasks', [
            'external_task_id' => $externalTaskId1,
            'task_type' => 'serp_rank_tracking',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('dataforseo_tasks', [
            'external_task_id' => $externalTaskId2,
            'task_type' => 'serp_rank_tracking',
            'status' => 'pending',
        ]);
    }

    public function test_webhook_and_collector_record_serp_snapshots_when_task_is_collected(): void
    {
        $keyword = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'best ai voice generator',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'weekly',
            'is_active' => true,
        ]);

        $externalTaskId = (string) Str::uuid();
        $token = Str::random(32);

        DB::table('dataforseo_tasks')->insert([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'external_task_id' => $externalTaskId,
            'endpoint' => '/v3/serp/google/organic/task_post',
            'task_type' => 'serp_rank_tracking',
            'status' => 'pending',
            'postback_token' => $token,
            'request_context' => json_encode(['tracked_keyword_id' => $keyword->id], JSON_THROW_ON_ERROR),
            'result_payload' => null,
            'submitted_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $webhookPayload = [
            'id' => $externalTaskId,
            'tasks' => [
                [
                    'result' => [
                        [
                            'items' => [
                                [
                                    'type' => 'organic',
                                    'domain' => 'coqui.io',
                                    'url' => 'https://coqui.io/best-generator',
                                    'rank_group' => 3,
                                    'rank_absolute' => 3,
                                    'title' => 'Best AI Voice Generator',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // 1. Test Webhook execution
        $response = $this->postJson(route('webhooks.dataforseo', ['token' => $token]), $webhookPayload);
        $response->assertOk()->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('dataforseo_tasks', [
            'external_task_id' => $externalTaskId,
            'status' => 'collected',
        ]);

        $this->assertDatabaseHas('keyword_rank_snapshots', [
            'tracked_keyword_id' => $keyword->id,
            'domain' => 'coqui.io',
            'rank_group' => 3,
        ]);
    }

    public function test_tracked_keywords_index_renders_inertia_dashboard_with_metrics_and_deltas(): void
    {
        $kw = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'realistic voice cloning',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'weekly',
            'is_active' => true,
        ]);

        // Previous snapshot (rank 5)
        KeywordRankSnapshot::query()->create([
            'tracked_keyword_id' => $kw->id,
            'project_id' => $this->project->id,
            'domain' => 'coqui.io',
            'rank_group' => 5,
            'rank_absolute' => 5,
            'ranking_url' => 'https://coqui.io/voice-clone',
            'checked_on' => '2026-11-10',
        ]);

        // Latest snapshot (rank 2, improved +3)
        KeywordRankSnapshot::query()->create([
            'tracked_keyword_id' => $kw->id,
            'project_id' => $this->project->id,
            'domain' => 'coqui.io',
            'rank_group' => 2,
            'rank_absolute' => 2,
            'ranking_url' => 'https://coqui.io/voice-clone',
            'has_ai_overview' => true,
            'checked_on' => '2026-11-17',
        ]);

        // Competitor A snapshot (rank 4)
        KeywordRankSnapshot::query()->create([
            'tracked_keyword_id' => $kw->id,
            'project_id' => $this->project->id,
            'domain' => 'competitor-a.com',
            'rank_group' => 4,
            'rank_absolute' => 4,
            'ranking_url' => 'https://competitor-a.com/clone',
            'checked_on' => '2026-11-17',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]));

        $response->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('Keywords/Index')
                    ->has('keywords', 1)
                    ->where('keywords.0.keyword', 'realistic voice cloning')
                    ->where('keywords.0.current_rank', 2)
                    ->where('keywords.0.previous_rank', 5)
                    ->where('keywords.0.rank_delta', 3)
                    ->where('keywords.0.delta_type', 'up')
                    ->where('keywords.0.has_ai_overview', true)
                    ->where('metrics.total_keywords', 1)
                    ->where('metrics.top_3', 1)
                    ->where('metrics.top_10', 1)
                    ->where('metrics.avg_rank', 2);

                /** @var list<array{competitor_ranks: array<string, array{rank: int|null}>}> $keywords */
                $keywords = $page->toArray()['props']['keywords'];
                $this->assertSame(4, $keywords[0]['competitor_ranks']['competitor-a.com']['rank']);
            });
    }

    public function test_user_can_bulk_store_tracked_keywords_and_activity_is_logged(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('workspaces.projects.keywords.store', [$this->workspace, $this->project]), [
                'keywords' => "natural voice tts\nneural text to speech\nnatural voice tts", // duplicate in batch
                'device' => 'desktop',
                'cadence' => 'daily',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('tracked_keywords', [
            'project_id' => $this->project->id,
            'keyword' => 'natural voice tts',
            'device' => 'desktop',
            'cadence' => 'daily',
        ]);

        $this->assertDatabaseHas('tracked_keywords', [
            'project_id' => $this->project->id,
            'keyword' => 'neural text to speech',
            'device' => 'desktop',
            'cadence' => 'daily',
        ]);

        // Duplicate was deduplicated
        $this->assertSame(
            1,
            TrackedKeyword::query()
                ->where('project_id', $this->project->id)
                ->where('keyword', 'natural voice tts')
                ->count()
        );

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'keyword.created',
        ]);
    }

    public function test_user_can_delete_tracked_keyword_and_snapshots_cascade(): void
    {
        $kw = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'obsolete keyword',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'weekly',
            'is_active' => true,
        ]);

        KeywordRankSnapshot::query()->create([
            'tracked_keyword_id' => $kw->id,
            'project_id' => $this->project->id,
            'domain' => 'coqui.io',
            'rank_group' => 12,
            'checked_on' => '2026-11-18',
        ]);

        $response = $this->actingAs($this->owner)
            ->delete(route('workspaces.projects.keywords.destroy', [$this->workspace, $this->project, $kw]));

        $response->assertRedirect();

        $this->assertDatabaseMissing('tracked_keywords', ['id' => $kw->id]);
        $this->assertDatabaseMissing('keyword_rank_snapshots', ['tracked_keyword_id' => $kw->id]);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'keyword.deleted',
            'title' => 'Deleted keyword: obsolete keyword',
        ]);
    }

    public function test_snapshots_endpoint_returns_historical_series_json(): void
    {
        $kw = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'multilingual voice api',
            'device' => 'mobile',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'weekly',
            'is_active' => true,
        ]);

        KeywordRankSnapshot::query()->create([
            'tracked_keyword_id' => $kw->id,
            'project_id' => $this->project->id,
            'domain' => 'coqui.io',
            'rank_group' => 8,
            'checked_on' => '2026-11-01',
        ]);

        KeywordRankSnapshot::query()->create([
            'tracked_keyword_id' => $kw->id,
            'project_id' => $this->project->id,
            'domain' => 'coqui.io',
            'rank_group' => 3,
            'checked_on' => '2026-11-08',
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson(route('workspaces.projects.keywords.snapshots', [$this->workspace, $this->project, $kw]));

        $response->assertOk()
            ->assertJsonPath('keyword.keyword', 'multilingual voice api')
            ->assertJsonPath('keyword.device', 'mobile')
            ->assertJsonCount(2, 'snapshots')
            ->assertJsonPath('snapshots.0.rank_group', 8)
            ->assertJsonPath('snapshots.1.rank_group', 3);
    }

    public function test_outsider_cannot_access_or_modify_project_tracked_keywords(): void
    {
        $outsider = User::factory()->create();

        $kw = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'private project keyword',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'weekly',
            'is_active' => true,
        ]);

        $this->actingAs($outsider)
            ->get(route('workspaces.projects.keywords.index', [$this->workspace, $this->project]))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->post(route('workspaces.projects.keywords.store', [$this->workspace, $this->project]), [
                'keywords' => 'unauthorized keyword',
            ])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->delete(route('workspaces.projects.keywords.destroy', [$this->workspace, $this->project, $kw]))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->getJson(route('workspaces.projects.keywords.snapshots', [$this->workspace, $this->project, $kw]))
            ->assertForbidden();
    }
}
