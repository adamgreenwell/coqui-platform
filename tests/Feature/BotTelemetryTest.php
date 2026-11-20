<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\BotCrawlLog;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BotTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests exercise the Runnr surface, which is hidden unless the flag is on.
        RunnrIntegration::activate();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Telemetry Workspace',
            'slug' => 'telemetry-workspace',
            'has_runnr_addon' => true,
            'owner_id' => $this->user->id,
        ]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Telemetry Site',
            'domain' => 'telemetry-site.com',
            'canonical_url' => 'https://telemetry-site.com/',
            'runnr_site_id' => 'site_telemetry_123',
        ]);
    }

    public function test_rejects_unauthorized_telemetry_ingest(): void
    {
        config()->set('services.runnr.telemetry_token', 'secret-telemetry-token');

        $this->postJson('/api/v1/telemetry/bot-logs', [
            'siteId' => 'site_telemetry_123',
            'entries' => [
                [
                    'botFamily' => 'googlebot',
                    'statusBucket' => 200,
                    'hitCount' => 10,
                    'bucketHour' => '2026-11-19T04:00:00Z',
                ],
            ],
        ])->assertStatus(401);
    }

    public function test_ingests_bot_crawler_logs_batch_with_valid_runnr_telemetry_token(): void
    {
        config()->set('services.runnr.telemetry_token', 'secret-telemetry-token');

        $hour = '2026-11-19T04:00:00Z';
        $response = $this->withToken('secret-telemetry-token')
            ->postJson('/api/v1/telemetry/bot-logs', [
                'siteId' => 'site_telemetry_123',
                'entries' => [
                    [
                        'botFamily' => 'Googlebot',
                        'statusBucket' => 200,
                        'hitCount' => 350,
                        'bytesServed' => 1024000,
                        'bucketHour' => $hour,
                    ],
                    [
                        'botFamily' => 'googlebot',
                        'statusBucket' => 404,
                        'hitCount' => 12,
                        'bytesServed' => 4800,
                        'bucketHour' => $hour,
                        'topErrorPaths' => [
                            ['path' => '/missing-guide', 'hits' => 12, 'status' => 404],
                        ],
                    ],
                    [
                        'botFamily' => 'GPTBot',
                        'statusBucket' => 200,
                        'hitCount' => 85,
                        'bytesServed' => 450000,
                        'bucketHour' => $hour,
                    ],
                    [
                        'botFamily' => 'ClaudeBot',
                        'statusBucket' => 200,
                        'hitCount' => 42,
                        'bytesServed' => 210000,
                        'bucketHour' => $hour,
                    ],
                ],
            ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'ingested',
                'ingestedCount' => 4,
                'totalHits' => 489,
                'projectId' => $this->project->id,
            ]);

        $this->assertDatabaseHas('bot_crawl_logs', [
            'project_id' => $this->project->id,
            'bot_family' => 'googlebot',
            'status_bucket' => 200,
            'hit_count' => 350,
        ]);

        $this->assertDatabaseHas('bot_crawl_logs', [
            'project_id' => $this->project->id,
            'bot_family' => 'googlebot',
            'status_bucket' => 400,
            'hit_count' => 12,
        ]);

        $this->assertDatabaseHas('bot_crawl_logs', [
            'project_id' => $this->project->id,
            'bot_family' => 'gptbot',
            'status_bucket' => 200,
            'hit_count' => 85,
        ]);

        // Realtime activity alert triggered on high-frequency Googlebot 404
        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'telemetry.bot_404_detected',
        ]);
    }

    public function test_ingests_bot_crawler_logs_merging_existing_bucket_hour(): void
    {
        config()->set('services.runnr.telemetry_token', 'secret-telemetry-token');
        $hour = '2026-11-19T05:00:00Z';

        // First ingest
        $this->withToken('secret-telemetry-token')
            ->postJson('/api/v1/telemetry/bot-logs', [
                'siteId' => 'site_telemetry_123',
                'entries' => [
                    [
                        'botFamily' => 'googlebot',
                        'statusBucket' => 400,
                        'hitCount' => 10,
                        'bytesServed' => 5000,
                        'bucketHour' => $hour,
                        'topErrorPaths' => [
                            ['path' => '/dead-link', 'hits' => 10, 'status' => 404],
                        ],
                    ],
                ],
            ])->assertStatus(202);

        // Second ingest for same hour should accumulate
        $this->withToken('secret-telemetry-token')
            ->postJson('/api/v1/telemetry/bot-logs', [
                'siteId' => 'site_telemetry_123',
                'entries' => [
                    [
                        'botFamily' => 'googlebot',
                        'statusBucket' => 400,
                        'hitCount' => 15,
                        'bytesServed' => 7500,
                        'bucketHour' => $hour,
                        'topErrorPaths' => [
                            ['path' => '/dead-link', 'hits' => 15, 'status' => 404],
                        ],
                    ],
                ],
            ])->assertStatus(202);

        /** @var BotCrawlLog $log */
        $log = BotCrawlLog::query()->where('project_id', $this->project->id)
            ->where('bot_family', 'googlebot')
            ->where('status_bucket', 400)
            ->firstOrFail();

        $this->assertSame(25, $log->hit_count);
        $this->assertSame(12500, $log->bytes_served);
        $this->assertIsArray($log->top_error_paths);
        $this->assertSame(25, $log->top_error_paths[0]['hits']);
    }

    public function test_telemetry_dashboard_aggregates_kpis_and_classifies_bots(): void
    {
        Sanctum::actingAs($this->user, ['projects:read']);

        $hour = Carbon::now()->subHours(2)->startOfHour();

        BotCrawlLog::query()->create([
            'project_id' => $this->project->id,
            'bot_family' => 'googlebot',
            'status_bucket' => 200,
            'hit_count' => 1000,
            'bytes_served' => 15000000,
            'bucket_hour' => $hour,
        ]);

        BotCrawlLog::query()->create([
            'project_id' => $this->project->id,
            'bot_family' => 'bingbot',
            'status_bucket' => 200,
            'hit_count' => 250,
            'bytes_served' => 3000000,
            'bucket_hour' => $hour,
        ]);

        BotCrawlLog::query()->create([
            'project_id' => $this->project->id,
            'bot_family' => 'gptbot',
            'status_bucket' => 200,
            'hit_count' => 150,
            'bytes_served' => 1000000,
            'bucket_hour' => $hour,
        ]);

        BotCrawlLog::query()->create([
            'project_id' => $this->project->id,
            'bot_family' => 'perplexitybot',
            'status_bucket' => 400,
            'hit_count' => 50,
            'bytes_served' => 20000,
            'bucket_hour' => $hour,
            'top_error_paths' => [
                ['path' => '/obsolete-doc', 'hits' => 50, 'status' => 404],
            ],
        ]);

        $response = $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7");

        $response->assertOk()
            ->assertJsonPath('projectId', $this->project->id)
            ->assertJsonPath('days', 7)
            ->assertJsonPath('kpis.totalHits', 1450)
            ->assertJsonPath('kpis.searchEngineHits', 1250)
            ->assertJsonPath('kpis.aiCrawlerHits', 200)
            ->assertJsonPath('kpis.wastedHits', 50)
            ->assertJsonPath('statusBreakdown.status2xx', 1400)
            ->assertJsonPath('statusBreakdown.status4xx', 50)
            ->assertJsonPath('flagged404s.0.path', '/obsolete-doc')
            ->assertJsonPath('flagged404s.0.hits', 50);
    }

    public function test_telemetry_web_view_renders_with_inertia(): void
    {
        $hour = Carbon::now()->subHours(1)->startOfHour();

        BotCrawlLog::query()->create([
            'project_id' => $this->project->id,
            'bot_family' => 'googlebot',
            'status_bucket' => 200,
            'hit_count' => 500,
            'bytes_served' => 2000000,
            'bucket_hour' => $hour,
        ]);

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/bot-logs")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/BotLogs')
                ->has('kpis')
                ->where('kpis.totalHits', 500)
                ->where('kpis.searchEngineHits', 500)
                ->has('botFamilyBreakdown', 1)
                ->where('botFamilyBreakdown.0.botFamily', 'googlebot')
            );
    }

    public function test_remediate_404_creates_redirect_activity(): void
    {
        $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/bot-logs/remediate", [
                'source_path' => '/legacy-article',
                'target_path' => '/articles/modern-guide',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'redirect.created',
        ]);
    }

    public function test_multi_tenant_isolation_on_telemetry(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/bot-logs")
            ->assertForbidden();

        Sanctum::actingAs($outsider, ['projects:read']);
        $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs")
            ->assertForbidden();
    }
}
