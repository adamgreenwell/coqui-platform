<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\BotCrawlLog;
use App\Domains\Integrations\Services\BotCrawlLogPartitions;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function sendBatch(array $entries, ?string $batchId = null): TestResponse
    {
        config()->set('services.runnr.telemetry_token', 'secret-telemetry-token');

        return $this->withToken('secret-telemetry-token')
            ->postJson('/api/v1/telemetry/bot-logs', array_filter([
                'siteId' => 'site_telemetry_123',
                'batchId' => $batchId,
                'entries' => $entries,
            ], fn ($v): bool => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function googlebot404s(int $hits, string $hour = '2026-11-19T05:00:00Z'): array
    {
        return [
            'botFamily' => 'googlebot',
            'statusBucket' => 404,
            'hitCount' => $hits,
            'bytesServed' => $hits * 500,
            'bucketHour' => $hour,
            'topErrorPaths' => [['path' => '/dead-link', 'hits' => $hits, 'status' => 404]],
        ];
    }

    private function googlebot404Total(): int
    {
        return (int) BotCrawlLog::query()
            ->where('project_id', $this->project->id)
            ->where('bot_family', 'googlebot')
            ->where('status_bucket', 400)
            ->sum('hit_count');
    }

    public function test_resending_the_same_batch_leaves_counts_unchanged(): void
    {
        $this->sendBatch([$this->googlebot404s(10)], 'batch-0500-a')->assertStatus(202)->assertJsonPath('duplicate', false);
        $this->sendBatch([$this->googlebot404s(10)], 'batch-0500-a')->assertStatus(202)->assertJsonPath('duplicate', true);
        $this->sendBatch([$this->googlebot404s(10)], 'batch-0500-a')->assertStatus(202);

        $this->assertSame(10, $this->googlebot404Total());
        $this->assertSame(5000, (int) BotCrawlLog::query()->where('project_id', $this->project->id)->sum('bytes_served'));
        // The 404 alert fires for the first copy only.
        $this->assertSame(1, ProjectActivity::query()->where('event_type', 'telemetry.bot_404_detected')->count());
    }

    public function test_a_resent_batch_replaces_what_it_sent_before(): void
    {
        $this->sendBatch([
            $this->googlebot404s(10),
            ['botFamily' => 'bingbot', 'statusBucket' => 200, 'hitCount' => 40, 'bucketHour' => '2026-11-19T05:00:00Z'],
        ], 'batch-0500-a')->assertStatus(202);

        // Corrected copy without the bingbot entry.
        $this->sendBatch([$this->googlebot404s(12)], 'batch-0500-a')->assertStatus(202);

        $this->assertSame(12, $this->googlebot404Total());
        $this->assertSame(0, BotCrawlLog::query()->where('bot_family', 'bingbot')->count());
    }

    public function test_replacing_a_batch_locks_that_batch_first_on_postgres(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->sendBatch([$this->googlebot404s(10)], 'batch-0500-a')->assertStatus(202);

        $lock = array_keys(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock')));
        $delete = array_keys(array_filter($queries, fn (string $sql): bool => str_starts_with(strtolower($sql), 'delete from "bot_crawl_logs"')));
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertSame([], $lock);

            return;
        }

        $this->assertCount(1, $lock);
        $this->assertNotEmpty($delete);
        $this->assertLessThan($delete[0], $lock[0]);
    }

    public function test_path_summaries_are_not_double_counted_and_keep_redirect_details(): void
    {
        $hour = '2026-11-19T08:00:00Z';
        $this->sendBatch([
            [
                // Both fields, as a producer mid-migration would send them.
                'botFamily' => 'googlebot',
                'statusBucket' => 404,
                'hitCount' => 10,
                'bucketHour' => $hour,
                'topPaths' => [['path' => '/gone', 'hits' => 10, 'status' => 404]],
                'topErrorPaths' => [['path' => '/gone', 'hits' => 10, 'status' => 404]],
            ],
            // The same redirecting path in two entries; only the second
            // reports the chain.
            ['botFamily' => 'googlebot', 'statusBucket' => 301, 'hitCount' => 4, 'bucketHour' => $hour, 'topPaths' => [['path' => '/old', 'hits' => 4, 'status' => 301]]],
            ['botFamily' => 'googlebot', 'statusBucket' => 301, 'hitCount' => 6, 'bucketHour' => $hour, 'topPaths' => [['path' => '/old', 'hits' => 6, 'status' => 301, 'redirectTarget' => 'https://example.com/new', 'hops' => 3]]],
        ])->assertStatus(202);

        $rows = BotCrawlLog::query()->where('project_id', $this->project->id)->get()->keyBy('status_bucket');
        $this->assertEquals([['path' => '/gone', 'hits' => 10, 'status' => 404]], $rows[400]->top_paths);
        $this->assertEquals([['path' => '/old', 'hits' => 10, 'status' => 301, 'redirectTarget' => 'https://example.com/new', 'hops' => 3]], $rows[300]->top_paths);
    }

    public function test_a_redirect_chain_shows_even_when_the_path_mostly_returned_200(): void
    {
        $this->sendBatch([
            ['botFamily' => 'googlebot', 'statusBucket' => 200, 'hitCount' => 100, 'bucketHour' => '2026-11-18T05:00:00Z', 'topPaths' => [['path' => '/pricing', 'hits' => 100, 'status' => 200]]],
            ['botFamily' => 'googlebot', 'statusBucket' => 301, 'hitCount' => 20, 'bucketHour' => '2026-11-19T05:00:00Z', 'topPaths' => [['path' => '/pricing', 'hits' => 20, 'status' => 301, 'redirectTarget' => 'https://example.com/plans', 'hops' => 3]]],
        ])->assertStatus(202);

        Sanctum::actingAs($this->user, ['projects:read']);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $chains = $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7")->assertOk()->json('redirectChains');
        Carbon::setTestNow();

        $this->assertCount(1, $chains);
        $this->assertSame('/pricing', $chains[0]['path']);
        $this->assertSame(20, $chains[0]['hits']);
        $this->assertSame(3, $chains[0]['hops']);
    }

    public function test_a_redirect_to_another_site_is_not_chained_through_local_paths(): void
    {
        $hour = '2026-11-19T05:00:00Z';
        $this->sendBatch([
            ['botFamily' => 'googlebot', 'statusBucket' => 301, 'hitCount' => 30, 'bucketHour' => $hour, 'topPaths' => [
                // Same path on another site: not a second hop.
                ['path' => '/old', 'hits' => 10, 'status' => 301, 'redirectTarget' => 'https://external.example/new'],
                // On this site (www. and case ignored): a second hop.
                ['path' => '/legacy', 'hits' => 10, 'status' => 301, 'redirectTarget' => 'https://WWW.telemetry-site.com/new'],
                ['path' => '/new', 'hits' => 10, 'status' => 301, 'redirectTarget' => '/final'],
            ]],
        ])->assertStatus(202);

        Sanctum::actingAs($this->user, ['projects:read']);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $chains = $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7")->assertOk()->json('redirectChains');
        Carbon::setTestNow();

        $this->assertSame(['/legacy'], array_column($chains, 'path'));
    }

    public function test_relative_redirect_targets_resolve_against_the_source_path(): void
    {
        $this->sendBatch([
            ['botFamily' => 'googlebot', 'statusBucket' => 301, 'hitCount' => 40, 'bucketHour' => '2026-11-19T05:00:00Z', 'topPaths' => [
                ['path' => '/catalog/old', 'hits' => 10, 'status' => 301, 'redirectTarget' => 'new'],
                ['path' => '/catalog/new', 'hits' => 10, 'status' => 301, 'redirectTarget' => '/shop'],
                ['path' => '/docs/a/page', 'hits' => 10, 'status' => 301, 'redirectTarget' => '../b/./page'],
                ['path' => '/docs/b/page', 'hits' => 10, 'status' => 301, 'redirectTarget' => 'https://telemetry-site.com/docs'],
            ]],
        ])->assertStatus(202);

        Sanctum::actingAs($this->user, ['projects:read']);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $chains = $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7")->assertOk()->json('redirectChains');
        Carbon::setTestNow();

        $paths = array_column($chains, 'path');
        sort($paths);
        $this->assertSame(['/catalog/old', '/docs/a/page'], $paths);
    }

    public function test_missing_page_details_ignore_other_responses_for_the_path(): void
    {
        $this->sendBatch([
            ['botFamily' => 'googlebot', 'statusBucket' => 404, 'hitCount' => 5, 'bucketHour' => '2026-11-18T05:00:00Z', 'topPaths' => [['path' => '/moved', 'hits' => 5, 'status' => 404]]],
            // Fixed later: Bingbot now gets a 200 there.
            ['botFamily' => 'bingbot', 'statusBucket' => 200, 'hitCount' => 9, 'bucketHour' => '2026-11-19T09:00:00Z', 'topPaths' => [['path' => '/moved', 'hits' => 9, 'status' => 200]]],
        ])->assertStatus(202);

        Sanctum::actingAs($this->user, ['projects:read']);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $flagged = $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7")->assertOk()->json('flagged404s');
        Carbon::setTestNow();

        $this->assertCount(1, $flagged);
        $this->assertSame(['googlebot'], $flagged[0]['bots']);
        $this->assertStringStartsWith('2026-11-18T05:00:00', $flagged[0]['lastHitAt']);
    }

    public function test_different_batches_for_the_same_hour_add_up(): void
    {
        $this->sendBatch([$this->googlebot404s(10)], 'batch-0500-a')->assertStatus(202);
        $this->sendBatch([$this->googlebot404s(15)], 'batch-0500-b')->assertStatus(202);

        $this->assertSame(25, $this->googlebot404Total());

        Sanctum::actingAs($this->user, ['projects:read']);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7")
            ->assertOk()
            ->assertJsonPath('flagged404s.0.path', '/dead-link')
            ->assertJsonPath('flagged404s.0.hits', 25);
        Carbon::setTestNow();
    }

    public function test_identical_batches_without_an_id_are_counted_once(): void
    {
        $this->sendBatch([$this->googlebot404s(10)])->assertStatus(202)->assertJsonPath('duplicate', false);
        $this->sendBatch([$this->googlebot404s(10)])->assertStatus(202)->assertJsonPath('duplicate', true);
        $this->assertSame(10, $this->googlebot404Total());

        // A different payload is a different batch.
        $this->sendBatch([$this->googlebot404s(4)])->assertStatus(202)->assertJsonPath('duplicate', false);
        $this->assertSame(14, $this->googlebot404Total());
    }

    public function test_the_404_flag_ignores_other_4xx_statuses(): void
    {
        $hour = '2026-11-19T06:00:00Z';
        $this->sendBatch([
            [
                'botFamily' => 'googlebot',
                'statusBucket' => 403,
                'hitCount' => 30,
                'bucketHour' => $hour,
                'topPaths' => [['path' => '/members-only', 'hits' => 30]],
            ],
            [
                'botFamily' => 'googlebot',
                'statusBucket' => 400,
                'hitCount' => 9,
                'bucketHour' => $hour,
                'topPaths' => [
                    ['path' => '/old-page', 'hits' => 6, 'status' => 410],
                    ['path' => '/bad-request', 'hits' => 3, 'status' => 400],
                ],
            ],
        ])->assertStatus(202);

        $activity = ProjectActivity::query()->where('event_type', 'telemetry.bot_404_detected')->sole();
        $this->assertSame('/old-page', $activity->properties['path'] ?? null);

        Sanctum::actingAs($this->user, ['projects:read']);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $response = $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7")->assertOk();
        Carbon::setTestNow();

        $this->assertSame(['/old-page'], array_column($response->json('flagged404s'), 'path'));
        $this->assertSame(39, $response->json('kpis.errorHits'));
    }

    public function test_missing_pages_are_ranked_by_404_hits_before_the_list_is_limited(): void
    {
        $hour = '2026-11-19T07:00:00Z';
        $popular = array_map(fn (int $i): string => "/popular-{$i}", range(1, 55));
        $this->sendBatch([
            [
                'botFamily' => 'googlebot',
                'statusBucket' => 200,
                'hitCount' => 5500,
                'bucketHour' => $hour,
                'topPaths' => array_map(fn (string $p): array => ['path' => $p, 'hits' => 100, 'status' => 200], $popular),
            ],
            [
                // 55 busy paths with one 404 each, and one quiet path with 30.
                'botFamily' => 'googlebot',
                'statusBucket' => 404,
                'hitCount' => 85,
                'bucketHour' => $hour,
                'topPaths' => [
                    ...array_map(fn (string $p): array => ['path' => $p, 'hits' => 1, 'status' => 404], $popular),
                    ['path' => '/broken', 'hits' => 30, 'status' => 404],
                ],
            ],
        ])->assertStatus(202);

        Sanctum::actingAs($this->user, ['projects:read']);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $flagged = $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7")->assertOk()->json('flagged404s');
        Carbon::setTestNow();

        $this->assertCount(50, $flagged);
        $this->assertSame('/broken', $flagged[0]['path']);
        $this->assertSame(30, $flagged[0]['hits']);
    }

    public function test_crawl_budget_waste_includes_redirects_and_parameter_urls(): void
    {
        $hour = '2026-11-19T07:00:00Z';
        $this->sendBatch([
            [
                'botFamily' => 'googlebot',
                'statusBucket' => 200,
                'hitCount' => 1000,
                'bucketHour' => $hour,
                'topPaths' => [
                    ['path' => '/', 'hits' => 300],
                    ['path' => '/shop?sort=price&page=2', 'hits' => 120],
                    ['path' => '/shop?color=red', 'hits' => 80],
                ],
            ],
            [
                // Runnr's own count of every parameterized 2xx hit.
                'botFamily' => 'bingbot',
                'statusBucket' => 200,
                'hitCount' => 100,
                'parameterHitCount' => 30,
                'bucketHour' => $hour,
            ],
            [
                'botFamily' => 'googlebot',
                'statusBucket' => 301,
                'hitCount' => 150,
                'bucketHour' => $hour,
                'topPaths' => [
                    ['path' => '/old-shop', 'hits' => 90, 'redirectTarget' => 'https://telemetry-site.com/shop-v2'],
                    ['path' => '/shop-v2', 'hits' => 40, 'redirectTarget' => '/shop'],
                    ['path' => '/legacy', 'hits' => 20, 'redirectTarget' => '/new', 'hops' => 3],
                ],
            ],
            [
                'botFamily' => 'googlebot',
                'statusBucket' => 500,
                'hitCount' => 20,
                'bucketHour' => $hour,
            ],
        ])->assertStatus(202);

        Sanctum::actingAs($this->user, ['projects:read']);
        Carbon::setTestNow('2026-11-20 12:00:00');
        $response = $this->getJson("/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs?days=7")->assertOk();
        Carbon::setTestNow();

        // 150 redirects + 20 errors + 200 + 30 parameterized 2xx hits.
        $response->assertJsonPath('kpis.totalHits', 1270)
            ->assertJsonPath('kpis.redirectHits', 150)
            ->assertJsonPath('kpis.parameterHits', 230)
            ->assertJsonPath('kpis.errorHits', 20)
            ->assertJsonPath('kpis.wastedHits', 400)
            ->assertJsonPath('topPaths.0.path', '/')
            ->assertJsonPath('topPaths.0.status', 200);

        $this->assertSame(
            ['/shop?sort=price&page=2', '/shop?color=red'],
            array_column($response->json('parameterPaths'), 'path'),
        );

        // /old-shop → /shop-v2 → /shop, and /legacy reported with 3 hops.
        $chains = collect($response->json('redirectChains'))->keyBy('path');
        $this->assertSame(['/old-shop', '/legacy'], $chains->keys()->all());
        $this->assertSame(2, $chains['/old-shop']['hops']);
        $this->assertSame(3, $chains['/legacy']['hops']);

        $daily = collect($response->json('dailyTimeseries'))->firstWhere('date', '2026-11-19');
        $this->assertSame(400, $daily['wastedHits'] ?? null);
    }

    public function test_partitions_command_is_a_no_op_off_postgres(): void
    {
        if (BotCrawlLogPartitions::supported()) {
            $this->artisan('telemetry:ensure-partitions')->assertSuccessful()->expectsOutputToContain('bot telemetry partition(s)');

            return;
        }

        $this->artisan('telemetry:ensure-partitions')
            ->assertSuccessful()
            ->expectsOutputToContain('need Postgres');
    }

    public function test_creating_a_month_moves_its_rows_out_of_the_default_partition(): void
    {
        $partitions = app(BotCrawlLogPartitions::class);
        if (! BotCrawlLogPartitions::supported()) {
            $this->assertSame([], $partitions->ensure());

            return;
        }

        // Beyond the months the migration created, so it lands in default.
        $month = now()->startOfMonth()->addMonths(8);
        BotCrawlLog::query()->create([
            'project_id' => $this->project->id,
            'bot_family' => 'googlebot',
            'status_bucket' => 200,
            'hit_count' => 7,
            'bucket_hour' => $month->copy()->addDays(2)->setHour(5),
        ]);
        $count = fn (string $table): int => (int) DB::selectOne("SELECT count(*) AS n FROM {$table}")->n;
        $this->assertSame(1, $count('bot_crawl_logs_default'));

        $created = $partitions->ensure(8);

        $name = BotCrawlLogPartitions::partitionName($month);
        $this->assertContains($name, $created);
        $this->assertSame(0, $count('bot_crawl_logs_default'));
        $this->assertSame(1, $count($name));
        $this->assertSame(7, (int) BotCrawlLog::query()->where('project_id', $this->project->id)->sum('hit_count'));
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
            'top_paths' => [
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
