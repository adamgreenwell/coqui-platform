<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Jobs\DispatchCloudCrawlJob;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Crawls\Services\SafeHttpClient;
use App\Domains\Crawls\Services\SsrfProtectionGuard;
use App\Domains\Integrations\Services\DeploySiteFilesInspector;
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

    /**
     * robots.txt / sitemap bodies served to the smoke check, by path.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private array $siteFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // These tests exercise the Runnr surface, which is hidden unless the flag is on.
        RunnrIntegration::activate();
        // Signatures cover the delivery timestamp; keep "now" fixed.
        $this->freezeTime();

        // The smoke check fetches robots.txt and the sitemap itself: resolve
        // test hosts to a public address and serve $siteFiles (404 otherwise).
        $guard = new class extends SsrfProtectionGuard
        {
            public function resolveSafeIps(string $url): array
            {
                return ['93.184.216.34'];
            }
        };
        $this->app->instance(DeploySiteFilesInspector::class, new DeploySiteFilesInspector(new SafeHttpClient($guard)));
        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! in_array($path, ['/robots.txt', '/sitemap.xml'], true)) {
                return null;
            }
            [$body, $status] = $this->siteFiles[$path] ?? ['', 404];

            return Http::response($body, $status);
        });

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
            ->withHeader('X-Runnr-Timestamp', (string) now()->getTimestamp())
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
        $signature = hash_hmac('sha256', now()->getTimestamp().'.'.$payload, $secret);

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
                'HTTP_X_RUNNR_TIMESTAMP' => (string) now()->getTimestamp(),
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
        $signature = hash_hmac('sha256', now()->getTimestamp().'.'.$payload, $secret);

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
                'HTTP_X_RUNNR_TIMESTAMP' => (string) now()->getTimestamp(),
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

        $this->siteFiles['/robots.txt'] = ['User-agent: *
Disallow: /
', 200];

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

        return [$payload, hash_hmac('sha256', now()->getTimestamp().'.'.$payload, $secret)];
    }

    private function postDeploy(string $payload, string $signature): TestResponse
    {
        return $this->call('POST', '/api/webhooks/runnr/deploy', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RUNNR_SIGNATURE' => $signature,
            'HTTP_X_RUNNR_TIMESTAMP' => (string) now()->getTimestamp(),
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

    private function smokeRun(string $suffix, string $environment = 'production', ?string $callbackUrl = null): CrawlRun
    {
        return CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-4999-8999-9999999998'.$suffix,
            'source' => 'deploy_smoke',
            'seed' => 'https://my-runnr-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => ['deploy' => array_filter([
                'deploy_id' => 'dep_'.$suffix,
                'environment' => $environment,
                'callback_url' => $callbackUrl,
            ])],
        ]);
    }

    private function addCrawledPage(CrawlRun $run, string $url): void
    {
        DB::table('crawl_urls')->insert([
            'crawl_run_id' => $run->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'status' => 200,
            'canonical' => $url,
            'meta_robots' => 'index, follow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sitemap(int $entries): string
    {
        $urls = '';
        for ($i = 1; $i <= $entries; $i++) {
            $urls .= "<url><loc>https://my-runnr-site.com/p/{$i}</loc></url>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>';
    }

    public function test_a_smoke_run_that_crawled_nothing_fails(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');
        Http::fake(['https://runnr.io/*' => Http::response(['acknowledged' => true], 200)]);
        $run = $this->smokeRun('01', 'preview', 'https://runnr.io/api/deploys/zero/callback');

        $result = app(RunnrDeploySmokeService::class)->evaluate($run);

        $this->assertFalse($result['passed']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(['no_urls_crawled'], array_column($result['regressions'], 'rule'));
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://runnr.io/api/deploys/zero/callback'
            && $request['passed'] === false
            && $request['totalUrlsChecked'] === 0);
    }

    public function test_a_deploy_that_blocks_all_crawling_is_a_failure(): void
    {
        // Disallow: / for everyone, with a Googlebot-specific group too.
        $this->siteFiles['/robots.txt'] = ["# deploy\nUser-agent: Googlebot\nDisallow:\n\nUser-agent: *\nDisallow: /\n", 200];
        $run = $this->smokeRun('02');

        $result = app(RunnrDeploySmokeService::class)->evaluate($run);

        $this->assertFalse($result['passed']);
        $rules = array_column($result['regressions'], 'rule');
        $this->assertContains('no_urls_crawled', $rules);
        $this->assertContains('robots_txt_disallow_all', $rules);
        $this->assertStringContainsString('robots.txt blocks all crawling', $result['regressions'][0]['message']);
        $this->assertTrue($run->refresh()->config['smoke_results']['checks']['robots']['disallow_all']);
    }

    public function test_robots_rules_for_one_bot_or_a_subfolder_are_not_disallow_all(): void
    {
        $this->assertFalse(DeploySiteFilesInspector::parseRobots("User-agent: BadBot\nDisallow: /\n")['disallow_all']);
        $this->assertFalse(DeploySiteFilesInspector::parseRobots("User-agent: *\nDisallow: /admin\n")['disallow_all']);
        $this->assertFalse(DeploySiteFilesInspector::parseRobots("User-agent: *\nDisallow: /\nAllow: /\n")['disallow_all']);
        // The longer, more specific rule wins: Allow: /* reopens everything.
        $this->assertFalse(DeploySiteFilesInspector::parseRobots("User-agent: *\nDisallow: /\nAllow: /*\n")['disallow_all']);
        // Only the homepage is blocked, or only the homepage is open: not a site-wide block.
        $this->assertFalse(DeploySiteFilesInspector::parseRobots("User-agent: *\nDisallow: /$\n")['disallow_all']);
        $this->assertFalse(DeploySiteFilesInspector::parseRobots("User-agent: *\nAllow: /$\nDisallow: /\n")['disallow_all']);
        // Googlebot obeys its own group, but every other crawler is blocked.
        $this->assertTrue(DeploySiteFilesInspector::parseRobots("User-agent: Googlebot\nAllow: /\n\nUser-agent: *\nDisallow: /\n")['disallow_all']);
        $this->assertTrue(DeploySiteFilesInspector::parseRobots("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow:\n")['disallow_all']);
        $this->assertTrue(DeploySiteFilesInspector::parseRobots("User-agent: bingbot\nUser-agent: *\nDisallow: /*\n")['disallow_all']);
        $this->assertSame(
            ['https://my-runnr-site.com/sitemap_index.xml'],
            DeploySiteFilesInspector::parseRobots("Sitemap: https://my-runnr-site.com/sitemap_index.xml\nUser-agent: *\nDisallow:\n")['sitemaps'],
        );
    }

    public function test_a_sitemap_that_disappears_since_the_last_deploy_is_flagged(): void
    {
        $this->siteFiles['/robots.txt'] = ["User-agent: *\nDisallow:\n", 200];
        $this->siteFiles['/sitemap.xml'] = [$this->sitemap(20), 200];
        $first = $this->smokeRun('03');
        $this->addCrawledPage($first, 'https://my-runnr-site.com/');
        $this->assertTrue(app(RunnrDeploySmokeService::class)->evaluate($first)['passed']);

        // Next deploy: sitemap gone, robots.txt edited.
        $this->siteFiles['/robots.txt'] = ["User-agent: *\nDisallow: /tmp\n", 200];
        unset($this->siteFiles['/sitemap.xml']);
        $second = $this->smokeRun('04');
        $this->addCrawledPage($second, 'https://my-runnr-site.com/');

        $result = app(RunnrDeploySmokeService::class)->evaluate($second);

        $this->assertFalse($result['passed']);
        $rules = array_column($result['regressions'], 'rule');
        $this->assertContains('sitemap_missing', $rules);
        $this->assertContains('robots_txt_changed', $rules);
    }

    public function test_sitemap_entries_are_counted_with_a_namespace_prefix(): void
    {
        $ns = 'http://www.sitemaps.org/schemas/sitemap/0.9';
        $this->assertSame(2, DeploySiteFilesInspector::countSitemapEntries(
            "<s:urlset xmlns:s=\"{$ns}\"><s:url><s:loc>https://a.test/1</s:loc></s:url><s:url><s:loc>https://a.test/2</s:loc></s:url></s:urlset>"
        ));
        $this->assertSame(1, DeploySiteFilesInspector::countSitemapEntries(
            "<sm:sitemapindex xmlns:sm=\"{$ns}\"><sm:sitemap><sm:loc>https://a.test/s.xml</sm:loc></sm:sitemap></sm:sitemapindex>"
        ));
        $this->assertSame(3, DeploySiteFilesInspector::countSitemapEntries($this->sitemap(3)));
        $this->assertSame(1, DeploySiteFilesInspector::countSitemapEntries('<urlset><url><loc>https://a.test/</loc></url></urlset>'));
        $this->assertNull(DeploySiteFilesInspector::countSitemapEntries('<html><body>Not found</body></html>'));
    }

    public function test_a_preview_deploy_is_not_the_production_baseline(): void
    {
        $this->siteFiles['/sitemap.xml'] = [$this->sitemap(20), 200];
        $production = $this->smokeRun('08');
        $this->addCrawledPage($production, 'https://my-runnr-site.com/');
        app(RunnrDeploySmokeService::class)->evaluate($production);

        // A preview deploy with no sitemap in between...
        unset($this->siteFiles['/sitemap.xml']);
        $preview = $this->smokeRun('09', 'preview');
        $this->addCrawledPage($preview, 'https://my-runnr-site.com/');
        app(RunnrDeploySmokeService::class)->evaluate($preview);

        // ...doesn't hide the production sitemap disappearing.
        $next = $this->smokeRun('10');
        $this->addCrawledPage($next, 'https://my-runnr-site.com/');
        $result = app(RunnrDeploySmokeService::class)->evaluate($next);

        $this->assertContains('sitemap_missing', array_column($result['regressions'], 'rule'));
    }

    public function test_many_preview_deploys_do_not_push_out_the_production_baseline(): void
    {
        $this->siteFiles['/sitemap.xml'] = [$this->sitemap(20), 200];
        $production = $this->smokeRun('11');
        $this->addCrawledPage($production, 'https://my-runnr-site.com/');
        app(RunnrDeploySmokeService::class)->evaluate($production);

        for ($i = 12; $i <= 41; $i++) {
            $this->smokeRun((string) $i, 'preview');
        }

        unset($this->siteFiles['/sitemap.xml']);
        $next = $this->smokeRun('42');
        $this->addCrawledPage($next, 'https://my-runnr-site.com/');
        $result = app(RunnrDeploySmokeService::class)->evaluate($next);

        $this->assertContains('sitemap_missing', array_column($result['regressions'], 'rule'));
    }

    public function test_a_robots_txt_with_a_byte_order_mark_is_parsed(): void
    {
        $this->assertTrue(DeploySiteFilesInspector::parseRobots("\u{FEFF}User-agent: *\nDisallow: /\n")['disallow_all']);
    }

    public function test_a_sitemap_that_shrinks_by_half_is_a_warning(): void
    {
        $this->siteFiles['/sitemap.xml'] = [$this->sitemap(40), 200];
        $first = $this->smokeRun('05');
        $this->addCrawledPage($first, 'https://my-runnr-site.com/');
        app(RunnrDeploySmokeService::class)->evaluate($first);

        $this->siteFiles['/sitemap.xml'] = [$this->sitemap(12), 200];
        $second = $this->smokeRun('06');
        $this->addCrawledPage($second, 'https://my-runnr-site.com/');

        $result = app(RunnrDeploySmokeService::class)->evaluate($second);

        $this->assertTrue($result['passed']);
        $this->assertSame('warning', $result['status']);
        $this->assertSame(['sitemap_shrunk'], array_column($result['regressions'], 'rule'));
    }

    public function test_stale_or_unstamped_deliveries_are_refused(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');
        $payload = json_encode(['site_id' => 'site_runnr_12345'], JSON_THROW_ON_ERROR);
        $old = (string) now()->subMinutes(10)->getTimestamp();

        $send = fn (array $headers): TestResponse => $this->call('POST', '/api/webhooks/runnr/deploy', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            ...$headers,
        ], $payload);

        // Correctly signed, but ten minutes old: a replay.
        $send([
            'HTTP_X_RUNNR_SIGNATURE' => hash_hmac('sha256', $old.'.'.$payload, 'runnr-secret-key'),
            'HTTP_X_RUNNR_TIMESTAMP' => $old,
        ])->assertStatus(401)->assertJson(['message' => 'Stale webhook delivery.']);

        // The old body-only signature, with no timestamp.
        $send(['HTTP_X_RUNNR_SIGNATURE' => hash_hmac('sha256', $payload, 'runnr-secret-key')])
            ->assertStatus(401)->assertJson(['message' => 'Missing webhook timestamp.']);

        // A fresh timestamp can't be swapped onto an old signature.
        $send([
            'HTTP_X_RUNNR_SIGNATURE' => hash_hmac('sha256', $old.'.'.$payload, 'runnr-secret-key'),
            'HTTP_X_RUNNR_TIMESTAMP' => (string) now()->getTimestamp(),
        ])->assertStatus(403);
    }

    public function test_a_redelivered_deploy_does_not_start_a_second_crawl(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');
        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'worker-secret');
        Http::fake(['http://coqui-worker:8085/jobs/crawl' => Http::response(['jobId' => 'job_1'], 202)]);

        [$payload, $signature] = $this->signedDeploy('runnr-secret-key');
        $first = $this->postDeploy($payload, $signature)->assertStatus(202);
        $second = $this->postDeploy($payload, $signature)->assertStatus(202);

        $this->assertSame($first->json('crawlId'), $second->json('crawlId'));
        $this->assertSame(1, CrawlRun::query()->where('source', 'deploy_smoke')->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'http://coqui-worker:8085/jobs/crawl'
            && $request['config']['respectRobots'] === false);
    }

    public function test_callbacks_to_runnr_carry_a_timestamped_signature(): void
    {
        config()->set('services.runnr.webhook_secret', 'runnr-secret-key');
        Http::fake(['https://runnr.io/*' => Http::response(['acknowledged' => true], 200)]);
        $run = $this->smokeRun('07', callbackUrl: 'https://runnr.io/api/deploys/ts/callback');
        $this->addCrawledPage($run, 'https://my-runnr-site.com/');

        app(RunnrDeploySmokeService::class)->evaluate($run);

        Http::assertSent(function (HttpRequest $request): bool {
            if ($request->url() !== 'https://runnr.io/api/deploys/ts/callback') {
                return false;
            }
            $timestamp = $request->header('X-Runnr-Timestamp')[0] ?? '';

            return $timestamp === (string) now()->getTimestamp()
                && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), 'runnr-secret-key'), $request->header('X-Runnr-Signature')[0] ?? '');
        });
    }
}
