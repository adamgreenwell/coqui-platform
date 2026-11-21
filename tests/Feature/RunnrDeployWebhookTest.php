<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Jobs\DispatchCloudCrawlJob;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Integrations\Services\RunnrDeploySmokeService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RunnrDeployWebhookTest extends TestCase
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
            'name' => 'Runnr Workspace',
            'slug' => 'runnr-workspace',
            'has_runnr_addon' => true,
            'owner_id' => $this->user->id,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Runnr Hosted Site',
            'domain' => 'my-runnr-site.com',
            'canonical_url' => 'https://my-runnr-site.com/',
            'runnr_site_id' => 'site_runnr_12345',
        ]);
    }

    public function test_rejects_with_503_when_secret_is_unconfigured(): void
    {
        config()->set('services.runnr.webhook_secret', '');

        $this->postJson('/api/webhooks/runnr/deploy', [
            'site_id' => 'site_runnr_12345',
        ])->assertStatus(503)
            ->assertJson(['message' => 'Runnr webhook secret is not configured.']);
    }

    public function test_rejects_with_401_when_signature_is_missing(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');

        $this->postJson('/api/webhooks/runnr/deploy', [
            'site_id' => 'site_runnr_12345',
        ])->assertStatus(401)
            ->assertJson(['message' => 'Missing webhook signature.']);
    }

    public function test_rejects_with_403_when_signature_is_invalid(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');

        $this->withHeader('X-Runnr-Signature', 'invalid-hex-hmac')
            ->postJson('/api/webhooks/runnr/deploy', [
                'site_id' => 'site_runnr_12345',
            ])->assertStatus(403)
            ->assertJson(['message' => 'Invalid signature.']);
    }

    public function test_ignores_with_202_when_site_id_does_not_match_any_project(): void
    {
        $secret = 'runnr-secret-key';
        config()->set('services.runnr.webhook_secret', $secret);

        $payload = json_encode(['site_id' => 'unknown_site'], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, $secret);

        $response = $this->call(
            'POST',
            '/api/webhooks/runnr/deploy',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_RUNNR_SIGNATURE' => $signature,
            ],
            $payload,
        );

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'ignored',
                'reason' => 'no_matching_project',
            ]);
    }

    public function test_queues_deploy_smoke_crawl_when_signature_and_site_id_are_valid(): void
    {
        $secret = 'runnr-secret-key';
        config()->set('services.runnr.webhook_secret', $secret);
        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'worker-secret');

        Http::fake([
            'http://coqui-worker:8085/jobs/crawl' => Http::response(['jobId' => 'job_worker_1'], 202),
        ]);

        $payload = json_encode([
            'site_id' => 'site_runnr_12345',
            'deploy_url' => 'https://preview-123.my-runnr-site.com/',
            'deploy_id' => 'dep_runnr_999',
            'environment' => 'production',
            'commit_sha' => 'c0ffee1234',
            'callback_url' => 'https://runnr.io/api/deploys/999/callback',
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, $secret);

        $response = $this->call(
            'POST',
            '/api/webhooks/runnr/deploy',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_RUNNR_SIGNATURE' => $signature,
            ],
            $payload,
        );

        $response->assertStatus(202)
            ->assertJson(['status' => 'queued']);

        $crawlId = (string) $response->json('crawlId');
        $this->assertNotEmpty($crawlId);

        $run = CrawlRun::query()->where('external_crawl_id', $crawlId)->firstOrFail();
        $this->assertSame('deploy_smoke', $run->source);
        $this->assertSame('https://preview-123.my-runnr-site.com/', $run->seed);
        // Dispatched through the crawl queue (sync in tests), which marks it running.
        $this->assertSame('running', $run->status);

        $this->assertIsArray($run->config);
        $this->assertSame('dep_runnr_999', $run->config['deploy']['deploy_id']);
        $this->assertSame('production', $run->config['deploy']['environment']);
        $this->assertSame('https://runnr.io/api/deploys/999/callback', $run->config['deploy']['callback_url']);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'deploy.smoke_triggered',
        ]);

        Http::assertSent(function ($request) use ($crawlId): bool {
            return $request->url() === 'http://coqui-worker:8085/jobs/crawl'
                && $request['crawlId'] === $crawlId
                && $request['seed'] === 'https://preview-123.my-runnr-site.com/';
        });
    }

    public function test_smoke_evaluation_flags_critical_robots_txt_disallow_all(): void
    {
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-9999-9999-999999999901',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => [
                'deploy' => [
                    'deploy_id' => 'dep_disallow_test',
                    'environment' => 'production',
                ],
            ],
        ]);

        DB::table('crawl_urls')->insert([
            'crawl_run_id' => $run->id,
            'url' => 'https://my-runnr-site.com/',
            'url_hash' => hash('sha256', 'https://my-runnr-site.com/'),
            'status' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('crawl_issues')->insert([
            'crawl_run_id' => $run->id,
            'url' => 'https://my-runnr-site.com/',
            'url_hash' => hash('sha256', 'https://my-runnr-site.com/'),
            'rule' => 'robots_disallowed',
            'severity' => 'error',
            'detail' => 'Disallow: / detected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(RunnrDeploySmokeService::class);
        $result = $service->evaluate($run);

        $this->assertFalse($result['passed']);
        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($result['regressions']);
        $this->assertSame('robots_txt_disallow_all', $result['regressions'][0]['rule']);
        $this->assertSame('critical', $result['regressions'][0]['severity']);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'deploy.smoke_failed',
        ]);
    }

    public function test_smoke_evaluation_flags_accidental_global_noindex(): void
    {
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-9999-9999-999999999902',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => [
                'deploy' => [
                    'deploy_id' => 'dep_noindex_test',
                    'environment' => 'production',
                ],
            ],
        ]);

        DB::table('crawl_urls')->insert([
            [
                'crawl_run_id' => $run->id,
                'url' => 'https://my-runnr-site.com/',
                'url_hash' => hash('sha256', 'https://my-runnr-site.com/'),
                'status' => 200,
                'meta_robots' => 'noindex, nofollow',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'crawl_run_id' => $run->id,
                'url' => 'https://my-runnr-site.com/about',
                'url_hash' => hash('sha256', 'https://my-runnr-site.com/about'),
                'status' => 200,
                'meta_robots' => 'noindex, nofollow',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $service = app(RunnrDeploySmokeService::class);
        $result = $service->evaluate($run);

        $this->assertFalse($result['passed']);
        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($result['regressions']);
        $this->assertSame('accidental_global_noindex', $result['regressions'][0]['rule']);
        $this->assertSame('critical', $result['regressions'][0]['severity']);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'deploy.smoke_failed',
        ]);
    }

    public function test_smoke_evaluation_flags_server_5xx_errors(): void
    {
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-9999-9999-999999999903',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => [
                'deploy' => ['environment' => 'production'],
            ],
        ]);

        DB::table('crawl_urls')->insert([
            'crawl_run_id' => $run->id,
            'url' => 'https://my-runnr-site.com/crash',
            'url_hash' => hash('sha256', 'https://my-runnr-site.com/crash'),
            'status' => 500,
            'error' => 'Internal Server Error',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(RunnrDeploySmokeService::class);
        $result = $service->evaluate($run);

        $this->assertFalse($result['passed']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('server_5xx_errors', $result['regressions'][0]['rule']);
        $this->assertSame('high', $result['regressions'][0]['severity']);
    }

    public function test_smoke_evaluation_flags_canonical_host_flips(): void
    {
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-9999-9999-999999999904',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => [
                'deploy' => ['environment' => 'production'],
            ],
        ]);

        DB::table('crawl_urls')->insert([
            'crawl_run_id' => $run->id,
            'url' => 'https://my-runnr-site.com/pricing',
            'url_hash' => hash('sha256', 'https://my-runnr-site.com/pricing'),
            'status' => 200,
            'canonical' => 'https://staging.my-runnr-site.com/pricing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(RunnrDeploySmokeService::class);
        $result = $service->evaluate($run);

        $this->assertFalse($result['passed']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('canonical_host_flip', $result['regressions'][0]['rule']);
        $this->assertSame('high', $result['regressions'][0]['severity']);
    }

    public function test_clean_deploy_passes_smoke_test_and_posts_signed_callback_to_runnr(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');

        Http::fake([
            'https://runnr.io/api/deploys/101/seo-callback' => Http::response(['acknowledged' => true], 200),
        ]);

        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-9999-9999-999999999905',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => [
                'deploy' => [
                    'deploy_id' => 'dep_101',
                    'environment' => 'production',
                    'callback_url' => 'https://runnr.io/api/deploys/101/seo-callback',
                ],
            ],
        ]);

        DB::table('crawl_urls')->insert([
            [
                'crawl_run_id' => $run->id,
                'url' => 'https://my-runnr-site.com/',
                'url_hash' => hash('sha256', 'https://my-runnr-site.com/'),
                'status' => 200,
                'canonical' => 'https://my-runnr-site.com/',
                'meta_robots' => 'index, follow',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'crawl_run_id' => $run->id,
                'url' => 'https://my-runnr-site.com/features',
                'url_hash' => hash('sha256', 'https://my-runnr-site.com/features'),
                'status' => 200,
                'canonical' => 'https://my-runnr-site.com/features',
                'meta_robots' => 'index, follow',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $service = app(RunnrDeploySmokeService::class);
        $result = $service->evaluate($run);

        $this->assertTrue($result['passed']);
        $this->assertSame('passed', $result['status']);
        $this->assertEmpty($result['regressions']);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'deploy.smoke_passed',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://runnr.io/api/deploys/101/seo-callback'
                && $request['passed'] === true
                && $request['status'] === 'passed'
                && $request['deployId'] === 'dep_101'
                && $request->hasHeader('X-Runnr-Signature');
        });
    }

    public function test_deploy_smoke_badge_renders_svg(): void
    {
        // 1. When no smoke crawl exists
        $response = $this->get('/api/v1/deploy-smoke/badge/'.$this->project->deployBadgeToken());
        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');
        $this->assertStringContainsString('runnr deploy: none', $response->getContent() ?: '');

        // 2. When a passing smoke crawl exists
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-9999-9999-999999999906',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => [
                'smoke_results' => [
                    'status' => 'passed',
                    'passed' => true,
                    'summary' => 'Passed 48 URLs',
                    'regressions' => [],
                    'total_urls_checked' => 48,
                ],
            ],
        ]);

        $responsePass = $this->get('/api/v1/deploy-smoke/badge/'.$this->project->deployBadgeToken());
        $responsePass->assertOk();
        $this->assertStringContainsString('runnr deploy: passing', $responsePass->getContent() ?: '');
        $this->assertStringContainsString('#4c1', $responsePass->getContent() ?: '');

        // 3. When a failing smoke crawl exists
        $run->update([
            'config' => [
                'smoke_results' => [
                    'status' => 'failed',
                    'passed' => false,
                    'summary' => 'Accidental noindex detected',
                    'regressions' => [['rule' => 'accidental_global_noindex']],
                    'total_urls_checked' => 12,
                ],
            ],
        ]);

        $responseFail = $this->get('/api/v1/deploy-smoke/badge/'.$this->project->deployBadgeToken());
        $responseFail->assertOk();
        $this->assertStringContainsString('runnr deploy: failing', $responseFail->getContent() ?: '');
        $this->assertStringContainsString('#e05d44', $responseFail->getContent() ?: '');
    }

    public function test_deploy_smoke_latest_endpoint_returns_json_details(): void
    {
        Sanctum::actingAs($this->user, ['projects:read', 'crawls:read']);

        CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-9999-9999-999999999907',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => [
                'deploy' => [
                    'deploy_id' => 'dep_json_1',
                    'environment' => 'production',
                ],
                'smoke_results' => [
                    'status' => 'passed',
                    'passed' => true,
                    'summary' => 'Deploy SEO Smoke Test PASSED',
                    'regressions' => [],
                    'total_urls_checked' => 30,
                ],
            ],
        ]);

        $response = $this->getJson("/api/v1/projects/{$this->project->uuid}/deploy-smoke/latest");
        $response->assertOk()
            ->assertJson([
                'projectId' => $this->project->id,
                'hasSmokeRun' => true,
                'latest' => [
                    'passed' => true,
                    'smokeStatus' => 'passed',
                    'summary' => 'Deploy SEO Smoke Test PASSED',
                ],
            ]);
    }

    public function test_can_connect_and_disconnect_runnr_integration_via_web(): void
    {
        $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/runnr/connect", [
                'runnr_site_id' => 'site_runnr_new_999',
            ])
            ->assertRedirect();

        $this->project->refresh();
        $this->assertSame('site_runnr_new_999', $this->project->runnr_site_id);

        $this->assertDatabaseHas('project_integrations', [
            'project_id' => $this->project->id,
            'provider' => 'runnr',
            'external_identifier' => 'site_runnr_new_999',
        ]);

        $this->actingAs($this->user)
            ->delete("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/integrations/runnr/disconnect")
            ->assertRedirect();

        $this->project->refresh();
        $this->assertNull($this->project->runnr_site_id);
        $this->assertDatabaseMissing('project_integrations', [
            'project_id' => $this->project->id,
            'provider' => 'runnr',
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function signedDeploy(string $secret): array
    {
        $payload = json_encode([
            'site_id' => 'site_runnr_12345',
            'deploy_url' => 'https://my-runnr-site.com/',
            'deploy_id' => 'dep_down_1',
            'environment' => 'production',
            'callback_url' => 'https://runnr.io/api/deploys/down/callback',
        ], JSON_THROW_ON_ERROR);

        return [$payload, hash_hmac('sha256', $payload, $secret)];
    }

    private function postDeploy(string $payload, string $signature): TestResponse
    {
        return $this->call('POST', '/api/webhooks/runnr/deploy', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RUNNR_SIGNATURE' => $signature,
        ], $payload);
    }

    public function test_runnr_gets_a_2xx_and_a_failed_callback_when_the_worker_is_down(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');
        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'worker-secret');

        Http::fake([
            'http://coqui-worker:8085/*' => fn () => throw new ConnectionException('Connection refused'),
            'https://runnr.io/*' => Http::response(['acknowledged' => true], 200),
        ]);

        [$payload, $signature] = $this->signedDeploy('runnr-secret-key');

        // The webhook only queues the dispatch, so Runnr gets its 202 whatever
        // state the worker is in.
        Queue::fake();
        $response = $this->postDeploy($payload, $signature);
        $response->assertStatus(202)->assertJson(['status' => 'queued']);

        $run = CrawlRun::query()->where('external_crawl_id', (string) $response->json('crawlId'))->firstOrFail();
        Queue::assertPushed(DispatchCloudCrawlJob::class, 1);

        // Then Horizon runs the dispatch against the unreachable worker.
        $job = Queue::pushed(DispatchCloudCrawlJob::class)->first();
        try {
            $job->handle(app(CoquiWorkerClient::class));
            $this->fail('Expected the connection error to be rethrown for Horizon.');
        } catch (ConnectionException) {
            // Expected.
        }

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertFalse($run->config['smoke_results']['passed']);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://runnr.io/api/deploys/down/callback'
            && $request['status'] === 'failed'
            && $request['passed'] === false
            && $request['totalUrlsChecked'] === 0
            && str_contains((string) $request['summary'], 'could not be reached')
            && $request->hasHeader('X-Runnr-Signature'));
    }

    public function test_runnr_gets_a_failed_callback_when_no_worker_is_set_up(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');
        config()->set('coqui.worker.token', '');

        Http::fake(['https://runnr.io/*' => Http::response(['acknowledged' => true], 200)]);

        [$payload, $signature] = $this->signedDeploy('runnr-secret-key');
        $response = $this->postDeploy($payload, $signature)->assertStatus(202);

        $run = CrawlRun::query()->where('external_crawl_id', (string) $response->json('crawlId'))->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finalized_at);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://runnr.io/api/deploys/down/callback'
            && $request['status'] === 'failed');
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'coqui-worker'));
    }

    public function test_a_stalled_smoke_crawl_reports_failure_to_runnr(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');
        Http::fake(['https://runnr.io/*' => Http::response(['acknowledged' => true], 200)]);

        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-4999-8999-999999999950',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'running',
            'config' => ['deploy' => ['deploy_id' => 'dep_stall', 'callback_url' => 'https://runnr.io/api/deploys/stall/callback']],
        ]);
        CrawlRun::query()->whereKey($run->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('coqui:fail-stalled-crawls')->assertSuccessful();

        $this->assertSame('failed', $run->refresh()->status);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://runnr.io/api/deploys/stall/callback'
            && $request['status'] === 'failed'
            && $request['deployId'] === 'dep_stall');
        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'deploy.smoke_failed',
        ]);
    }

    public function test_a_smoke_crawl_the_worker_reports_failed_is_not_a_pass(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');
        config()->set('coqui.worker.token', 'worker-secret');
        Http::fake(['https://runnr.io/*' => Http::response(['acknowledged' => true], 200)]);

        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-4999-8999-999999999951',
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'running',
            'config' => ['deploy' => ['deploy_id' => 'dep_worker_failed', 'callback_url' => 'https://runnr.io/api/deploys/wf/callback']],
        ]);

        $this->withToken('worker-secret')
            ->postJson('/api/internal/crawls/callback', ['crawlId' => $run->external_crawl_id, 'status' => 'failed'])
            ->assertOk();

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertFalse($run->config['smoke_results']['passed']);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://runnr.io/api/deploys/wf/callback'
            && $request['status'] === 'failed'
            && $request['passed'] === false
            && str_contains((string) $request['summary'], 'reported that the crawl failed'));
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === 'https://runnr.io/api/deploys/wf/callback'
            && $request['passed'] === true);
    }
}
