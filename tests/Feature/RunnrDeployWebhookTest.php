<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Runnr Workspace',
            'slug' => 'runnr-workspace',
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
        $this->assertSame('pending', $run->status);

        Http::assertSent(function ($request) use ($crawlId): bool {
            return $request->url() === 'http://coqui-worker:8085/jobs/crawl'
                && $request['crawlId'] === $crawlId
                && $request['seed'] === 'https://preview-123.my-runnr-site.com/';
        });
    }
}
