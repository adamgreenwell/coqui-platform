<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoquiWorkerCallbackTest extends TestCase
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
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'owner_id' => $this->user->id,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui SEO',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
        ]);
    }

    public function test_rejects_callback_when_worker_token_is_not_configured(): void
    {
        config()->set('coqui.worker.token', '');

        $this->postJson('/api/internal/crawls/callback', [
            'crawlId' => (string) Str::uuid(),
            'status' => 'running',
        ])->assertStatus(503)
            ->assertJson(['message' => 'Worker callbacks are not configured.']);
    }

    public function test_rejects_callback_with_missing_or_invalid_bearer_token(): void
    {
        config()->set('coqui.worker.token', 'expected-worker-secret');

        $this->postJson('/api/internal/crawls/callback', [
            'crawlId' => (string) Str::uuid(),
            'status' => 'running',
        ])->assertStatus(401)
            ->assertJson(['message' => 'Invalid worker token.']);

        $this->withToken('wrong-secret')
            ->postJson('/api/internal/crawls/callback', [
                'crawlId' => (string) Str::uuid(),
                'status' => 'running',
            ])->assertStatus(401)
            ->assertJson(['message' => 'Invalid worker token.']);
    }

    public function test_cannot_mutate_desktop_source_crawl_runs(): void
    {
        config()->set('coqui.worker.token', 'expected-worker-secret');

        $crawlId = (string) Str::uuid();
        CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'desktop',
            'seed' => 'https://coquiseo.com/',
            'seed_kind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 3],
        ]);

        $this->withToken('expected-worker-secret')
            ->postJson('/api/internal/crawls/callback', [
                'crawlId' => $crawlId,
                'status' => 'completed',
            ])->assertNotFound();
    }

    public function test_ingests_worker_chunks_and_finalizes_on_completed_status(): void
    {
        config()->set('coqui.worker.token', 'expected-worker-secret');

        $crawlId = (string) Str::uuid();
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'deploy_smoke',
            'seed' => 'https://coquiseo.com/',
            'seed_kind' => 'url',
            'status' => 'pending',
            'config' => ['maxDepth' => 2],
        ]);

        $response = $this->withToken('expected-worker-secret')
            ->postJson('/api/internal/crawls/callback', [
                'crawlId' => $crawlId,
                'status' => 'completed',
                'urls' => [
                    [
                        'url' => 'https://coquiseo.com/',
                        'status' => 200,
                        'contentType' => 'text/html',
                        'title' => 'Coqui SEO Home',
                    ],
                    [
                        'url' => 'https://coquiseo.com/missing',
                        'status' => 404,
                        'contentType' => 'text/html',
                    ],
                ],
                'links' => [
                    [
                        'fromUrl' => 'https://coquiseo.com/',
                        'toUrl' => 'https://coquiseo.com/missing',
                        'internal' => true,
                    ],
                ],
                'issues' => [
                    [
                        'url' => 'https://coquiseo.com/missing',
                        'rule' => 'broken_internal_link',
                        'severity' => 'error',
                        'detail' => 'Returned 404 Not Found',
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'externalCrawlId' => $crawlId,
                'status' => 'completed',
                'ingested' => [
                    'urls' => 2,
                    'links' => 1,
                    'issues' => 1,
                ],
                'totalUrls' => 2,
                'errors' => 1,
                'warnings' => 0,
                'opportunities' => 0,
            ]);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->total_urls);
        $this->assertSame(1, $run->error_count);
        $this->assertNotNull($run->finalized_at);

        $this->project->refresh();
        $this->assertNotNull($this->project->last_crawled_at);

        // Subsequent call to already finalized run returns 409
        $this->withToken('expected-worker-secret')
            ->postJson('/api/internal/crawls/callback', [
                'crawlId' => $crawlId,
                'status' => 'completed',
            ])->assertStatus(409)
            ->assertJson(['message' => 'Crawl run is already finalized.']);
    }
}
