<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Events\CrawlProgressUpdated;
use App\Domains\Crawls\Exceptions\SsrfProtectionException;
use App\Domains\Crawls\Jobs\DispatchCloudCrawlJob;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Crawls\Services\SsrfProtectionGuard;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakeDnsSsrfProtectionGuard extends SsrfProtectionGuard
{
    /**
     * @param  array<string, list<string>>  $dnsMap
     */
    public function __construct(
        private readonly array $dnsMap = [],
    ) {}

    protected function resolveHost(string $host): array
    {
        if (array_key_exists($host, $this->dnsMap)) {
            return $this->dnsMap[$host];
        }

        return parent::resolveHost($host);
    }
}

class CoquiWorkerJobBridgeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'worker-token-xyz');

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Acme Labs',
            'slug' => 'acme-labs',
            'owner_id' => $this->user->id,
            'cloud_crawl_url_limit' => 750,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Acme SaaS',
            'domain' => 'acme.test',
            'canonical_url' => 'https://acme.test/',
            'crawl_cadence' => 'weekly',
        ]);
    }

    public function test_ssrf_protection_guard_blocks_private_loopback_and_metadata_ips(): void
    {
        $guard = new FakeDnsSsrfProtectionGuard([
            'metadata.google.internal' => ['169.254.169.254'],
            'safe-public-domain.com' => ['93.184.216.34'],
            'internal.corp' => ['10.0.0.10'],
        ]);

        // Allowed public URL
        $this->assertTrue($guard->isAllowed('https://safe-public-domain.com/'));

        // Blocked private / loopback IP literals
        $blockedUrls = [
            'http://127.0.0.1/admin',
            'http://127.0.1.1:8080/',
            'http://10.0.0.1/keys',
            'http://172.16.0.5/api',
            'http://192.168.1.100/',
            'http://169.254.169.254/latest/meta-data/',
            'http://0.0.0.0:8000/',
            'http://localhost/metrics',
            'http://service.localhost/status',
            'http://app.local/env',
            'http://internal.corp/admin',
            'http://metadata.google.internal/computeMetadata/v1/',
            'ftp://example.com/dump.sql',
            'file:///etc/passwd',
            'http://[::1]/',
            'http://[fe80::1]/',
        ];

        foreach ($blockedUrls as $url) {
            $this->assertFalse($guard->isAllowed($url), "Failed asserting that [{$url}] is blocked by SSRF guard.");

            try {
                $guard->assertAllowed($url);
                $this->fail("Expected SsrfProtectionException for [{$url}]");
            } catch (SsrfProtectionException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_coqui_worker_client_refuses_ssrf_seed_or_target_urls(): void
    {
        $guard = new FakeDnsSsrfProtectionGuard([
            'acme.test' => ['127.0.0.1'],
        ]);

        $client = new CoquiWorkerClient($guard);

        $this->expectException(SsrfProtectionException::class);
        $client->dispatchCrawl($this->project, (string) Str::uuid(), [
            'seed' => 'http://127.0.0.1:8080/secrets',
        ]);
    }

    public function test_coqui_worker_client_bounds_max_urls_by_workspace_tier(): void
    {
        $guard = new FakeDnsSsrfProtectionGuard([
            'acme.test' => ['93.184.216.34'],
        ]);

        Http::fake([
            'http://coqui-worker:8085/jobs/crawl' => Http::response([
                'jobId' => 'job_123',
                'status' => 'queued',
            ], 202),
        ]);

        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'test-worker-token');

        $client = new CoquiWorkerClient($guard);

        // Requested 10,000 URLs, but workspace limit is 750
        $client->dispatchCrawl($this->project, 'crawl_bounded_uuid', [
            'seed' => 'https://acme.test/',
            'maxUrls' => 10000,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://coqui-worker:8085/jobs/crawl'
                && $request->hasHeader('Authorization', 'Bearer test-worker-token')
                && $request['config']['maxUrls'] === 750
                && $request['seed'] === 'https://acme.test/';
        });
    }

    public function test_dispatch_cloud_crawl_job_dispatches_to_worker_and_updates_status(): void
    {
        Event::fake([CrawlProgressUpdated::class]);

        $guard = new FakeDnsSsrfProtectionGuard([
            'acme.test' => ['93.184.216.34'],
        ]);

        Http::fake([
            'http://coqui-worker:8085/jobs/crawl' => Http::response([
                'jobId' => 'job_789',
                'status' => 'dispatched',
            ], 200),
        ]);

        config()->set('coqui.worker.url', 'http://coqui-worker:8085');
        config()->set('coqui.worker.token', 'worker-token-xyz');

        $crawlId = (string) Str::uuid();
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'worker',
            'seed' => 'https://acme.test/',
            'seed_kind' => 'url',
            'status' => 'pending',
            'config' => ['maxDepth' => 3, 'maxUrls' => 750],
        ]);

        $job = new DispatchCloudCrawlJob($this->project, $crawlId, ['maxUrls' => 750]);
        $client = new CoquiWorkerClient($guard);
        $job->handle($client);

        $run->refresh();
        $this->assertSame('running', $run->status);

        Event::assertDispatched(CrawlProgressUpdated::class, function (CrawlProgressUpdated $event) use ($crawlId): bool {
            return $event->projectId === $this->project->id
                && $event->externalCrawlId === $crawlId
                && $event->status === 'running';
        });

        Http::assertSent(fn (Request $request) => $request['crawlId'] === $crawlId);
    }

    public function test_dispatch_cloud_crawl_job_handles_worker_failure_gracefully(): void
    {
        Event::fake([CrawlProgressUpdated::class]);

        $guard = new FakeDnsSsrfProtectionGuard([
            'acme.test' => ['93.184.216.34'],
        ]);

        Http::fake([
            'http://coqui-worker:8085/jobs/crawl' => Http::response('Worker connection refused', 503),
        ]);

        $crawlId = (string) Str::uuid();
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'worker',
            'seed' => 'https://acme.test/',
            'seed_kind' => 'url',
            'status' => 'pending',
            'config' => ['maxDepth' => 3, 'maxUrls' => 750],
        ]);

        $job = new DispatchCloudCrawlJob($this->project, $crawlId, ['maxUrls' => 750]);
        $client = new CoquiWorkerClient($guard);

        try {
            $job->handle($client);
            $this->fail('Expected exception from failed worker HTTP call.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        $run->refresh();
        $this->assertSame('failed', $run->status);

        Event::assertDispatched(CrawlProgressUpdated::class, fn (CrawlProgressUpdated $e) => $e->status === 'failed');

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'crawl.failed',
        ]);
    }

    public function test_scheduled_command_dispatches_due_crawls_and_respects_cadence(): void
    {
        Queue::fake();

        // Project 1: Weekly, last crawled 8 days ago -> Due!
        $this->project->update([
            'crawl_cadence' => 'weekly',
            'last_crawled_at' => Carbon::now()->subDays(8),
        ]);

        // Project 2: Weekly, last crawled 2 days ago -> Not due
        $project2 = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Recent Project',
            'domain' => 'recent.test',
            'canonical_url' => 'https://recent.test/',
            'crawl_cadence' => 'weekly',
            'last_crawled_at' => Carbon::now()->subDays(2),
        ]);

        // Project 3: Manual cadence -> Ignored
        $project3 = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Manual Project',
            'domain' => 'manual.test',
            'canonical_url' => 'https://manual.test/',
            'crawl_cadence' => 'manual',
            'last_crawled_at' => null,
        ]);

        // Project 4: Daily, never crawled -> Due!
        $project4 = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Daily Brand',
            'domain' => 'daily.test',
            'canonical_url' => 'https://daily.test/',
            'crawl_cadence' => 'daily',
            'last_crawled_at' => null,
        ]);

        $exit = Artisan::call('coqui:run-scheduled-crawls');
        $this->assertSame(0, $exit);

        Queue::assertPushed(DispatchCloudCrawlJob::class, 2);

        Queue::assertPushed(DispatchCloudCrawlJob::class, function (DispatchCloudCrawlJob $job): bool {
            return $job->project->id === $this->project->id
                && $job->queue === 'crawls'
                && $job->overrides['maxUrls'] === 750;
        });

        Queue::assertPushed(DispatchCloudCrawlJob::class, function (DispatchCloudCrawlJob $job) use ($project4): bool {
            return $job->project->id === $project4->id
                && $job->queue === 'crawls';
        });

        Queue::assertNotPushed(DispatchCloudCrawlJob::class, fn (DispatchCloudCrawlJob $job) => $job->project->id === $project2->id);
        Queue::assertNotPushed(DispatchCloudCrawlJob::class, fn (DispatchCloudCrawlJob $job) => $job->project->id === $project3->id);
    }

    public function test_web_controller_can_dispatch_cloud_crawl_and_prevents_duplicates(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->post(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/dispatch",
            ['max_urls' => 500]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Queue::assertPushed(DispatchCloudCrawlJob::class, 1);

        $this->assertDatabaseHas('crawl_runs', [
            'project_id' => $this->project->id,
            'source' => 'worker',
            'status' => 'pending',
        ]);

        // Second attempt while first is pending returns 409 JSON or redirect with error
        $secondResponse = $this->actingAs($this->user)->postJson(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/dispatch"
        );

        $secondResponse->assertStatus(409)
            ->assertJson(['message' => 'A crawl run is already pending or running for this project.']);
    }

    public function test_worker_callback_handles_progress_heartbeats(): void
    {
        Event::fake([CrawlProgressUpdated::class]);

        config()->set('coqui.worker.token', 'expected-worker-secret');

        $crawlId = (string) Str::uuid();
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'worker',
            'seed' => 'https://acme.test/',
            'seed_kind' => 'url',
            'status' => 'pending',
            'config' => ['maxDepth' => 3, 'maxUrls' => 750],
        ]);

        // Send a heartbeat with progress counters
        $response = $this->withToken('expected-worker-secret')
            ->postJson('/api/internal/crawls/callback', [
                'crawlId' => $crawlId,
                'status' => 'running',
                'crawledUrls' => 45,
                'totalUrls' => 120,
            ]);

        $response->assertOk()
            ->assertJson([
                'externalCrawlId' => $crawlId,
                'status' => 'running',
            ]);

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame(120, $run->total_urls);

        Event::assertDispatched(CrawlProgressUpdated::class, function (CrawlProgressUpdated $event) use ($crawlId): bool {
            return $event->externalCrawlId === $crawlId
                && $event->crawledUrls === 45
                && $event->totalUrls === 120
                && $event->status === 'running';
        });
    }

    public function test_dispatch_says_cloud_crawling_is_not_set_up_without_a_worker_token(): void
    {
        Queue::fake();
        config()->set('coqui.worker.token', '');
        $url = "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/dispatch";

        $this->actingAs($this->user)->from('/dashboard')->post($url)
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error', CoquiWorkerClient::NOT_CONFIGURED_MESSAGE);
        $this->actingAs($this->user)->postJson($url)
            ->assertStatus(503)
            ->assertJsonPath('message', CoquiWorkerClient::NOT_CONFIGURED_MESSAGE);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('crawl_runs', 0);
    }

    public function test_scheduled_crawls_skip_without_a_worker_token(): void
    {
        Queue::fake();
        config()->set('coqui.worker.token', '');

        $this->artisan('coqui:run-scheduled-crawls')
            ->expectsOutputToContain('crawl worker is not configured')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('crawl_runs', 0);
    }

    public function test_an_unreachable_worker_fails_the_run_once_with_a_clear_reason(): void
    {
        Event::fake([CrawlProgressUpdated::class]);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to coqui-worker port 8085'));

        $crawlId = (string) Str::uuid();
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'worker',
            'seed' => 'https://acme.test/',
            'seed_kind' => 'url',
            'status' => 'pending',
            'config' => ['maxDepth' => 3, 'maxUrls' => 750],
        ]);

        $job = new DispatchCloudCrawlJob($this->project, $crawlId, ['maxUrls' => 750]);
        $this->assertSame(1, $job->tries);

        try {
            $job->handle(new CoquiWorkerClient(new FakeDnsSsrfProtectionGuard(['acme.test' => ['93.184.216.34']])));
            $this->fail('Expected the connection error to be rethrown.');
        } catch (ConnectionException) {
            // Rethrown so the job also shows as failed in Horizon.
        }

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finalized_at);
        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'crawl.failed',
            'description' => "Failed to dispatch cloud crawl [{$crawlId}]: The crawl worker could not be reached.",
        ]);

        // A late callback for the closed run is refused, so the worker stops.
        $this->withToken('worker-token-xyz')
            ->postJson('/api/internal/crawls/callback', ['crawlId' => $crawlId, 'status' => 'running'])
            ->assertStatus(409);
    }

    public function test_crawls_the_worker_stopped_reporting_on_are_marked_failed(): void
    {
        Event::fake([CrawlProgressUpdated::class]);
        config()->set('coqui.worker.stall_minutes', 30);

        $make = function (string $source, string $status, int $minutesAgo): CrawlRun {
            $run = CrawlRun::query()->create([
                'project_id' => $this->project->id,
                'external_crawl_id' => (string) Str::uuid(),
                'source' => $source,
                'seed' => 'https://acme.test/',
                'seed_kind' => 'url',
                'status' => $status,
                'config' => [],
            ]);
            CrawlRun::query()->whereKey($run->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

            return $run;
        };

        $stalled = $make('worker', 'running', 45);
        $stalledPending = $make('worker', 'pending', 90);
        $alive = $make('worker', 'running', 10);
        $desktop = $make('desktop', 'running', 120);
        $done = $make('worker', 'completed', 120);

        $this->artisan('coqui:fail-stalled-crawls')
            ->expectsOutputToContain('Marked 2 stalled')
            ->assertSuccessful();

        $this->assertSame('failed', $stalled->refresh()->status);
        $this->assertNotNull($stalled->finalized_at);
        $this->assertSame('failed', $stalledPending->refresh()->status);
        $this->assertSame('running', $alive->refresh()->status);
        $this->assertSame('running', $desktop->refresh()->status);
        $this->assertSame('completed', $done->refresh()->status);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'crawl.failed',
            'title' => 'Cloud crawl stopped responding',
        ]);
        Event::assertDispatched(CrawlProgressUpdated::class, fn (CrawlProgressUpdated $e) => $e->status === 'failed'
            && $e->externalCrawlId === $stalled->external_crawl_id);
    }

    public function test_worker_heartbeats_keep_a_long_crawl_from_being_swept(): void
    {
        Event::fake([CrawlProgressUpdated::class]);

        $crawlId = (string) Str::uuid();
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => $crawlId,
            'source' => 'worker',
            'seed' => 'https://acme.test/',
            'seed_kind' => 'url',
            'status' => 'running',
            'total_urls' => 120,
            'config' => [],
        ]);
        CrawlRun::query()->whereKey($run->id)->update(['updated_at' => now()->subMinutes(45)]);

        // Nothing changed since the last post, but the worker is alive.
        $this->withToken('worker-token-xyz')
            ->postJson('/api/internal/crawls/callback', [
                'crawlId' => $crawlId,
                'status' => 'running',
                'crawledUrls' => 60,
                'totalUrls' => 120,
            ])
            ->assertOk();

        $this->artisan('coqui:fail-stalled-crawls')->assertSuccessful();
        $this->assertSame('running', $run->refresh()->status);
    }
}
