<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Actions\IngestDesktopCrawlSnapshot;
use App\Domains\Crawls\Events\CrawlSynced;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrawlSyncRetrySafetyTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $crawlId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Sync Workspace',
            'slug' => 'sync-workspace',
            'owner_id' => $user->id,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Coqui SEO',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
        ]);

        Sanctum::actingAs($user, ['crawls:sync']);

        $this->crawlId = (string) Str::uuid();
        $this->postJson("/api/v1/projects/{$this->project->uuid}/crawls/sync", [
            'id' => $this->crawlId,
            'seed' => 'https://coquiseo.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 3],
            'createdAt' => 1795022400000,
        ])->assertCreated();
    }

    private function chunkUrl(): string
    {
        return "/api/v1/projects/{$this->project->uuid}/crawls/{$this->crawlId}/chunks";
    }

    private function finalizeUrl(): string
    {
        return "/api/v1/projects/{$this->project->uuid}/crawls/{$this->crawlId}/finalize";
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function chunk(): array
    {
        return [
            'urls' => [
                ['url' => 'https://coquiseo.com/', 'status' => 200, 'depth' => 0],
                ['url' => 'https://coquiseo.com/pricing', 'status' => 200, 'depth' => 1],
            ],
            'links' => [
                ['fromUrl' => 'https://coquiseo.com/', 'toUrl' => 'https://coquiseo.com/pricing', 'anchor' => 'Pricing', 'rel' => null, 'internal' => true],
                ['fromUrl' => 'https://coquiseo.com/', 'toUrl' => 'https://coquiseo.com/pricing', 'anchor' => 'See plans', 'rel' => null, 'internal' => true],
                ['fromUrl' => 'https://coquiseo.com/', 'toUrl' => 'https://github.com/', 'anchor' => 'GitHub', 'rel' => 'nofollow', 'internal' => false],
            ],
            'issues' => [
                ['url' => 'https://coquiseo.com/pricing', 'rule' => 'missing_meta_description', 'severity' => 'warning'],
            ],
        ];
    }

    /**
     * @return array{urls: int, links: int, issues: int}
     */
    private function rowCounts(): array
    {
        $runId = CrawlRun::query()->where('external_crawl_id', $this->crawlId)->value('id');

        return [
            'urls' => DB::table('crawl_urls')->where('crawl_run_id', $runId)->count(),
            'links' => DB::table('crawl_links')->where('crawl_run_id', $runId)->count(),
            'issues' => DB::table('crawl_issues')->where('crawl_run_id', $runId)->count(),
        ];
    }

    public function test_retrying_a_chunk_produces_identical_row_counts(): void
    {
        $this->postJson($this->chunkUrl(), $this->chunk())->assertOk();
        $first = $this->rowCounts();

        $this->postJson($this->chunkUrl(), $this->chunk())->assertOk();
        $this->postJson($this->chunkUrl(), $this->chunk())->assertOk();

        $this->assertSame(['urls' => 2, 'links' => 3, 'issues' => 1], $first);
        $this->assertSame($first, $this->rowCounts());
    }

    public function test_a_duplicate_link_inside_one_chunk_is_stored_once(): void
    {
        $chunk = $this->chunk();
        $chunk['links'][] = $chunk['links'][0];

        $this->postJson($this->chunkUrl(), $chunk)->assertOk();

        $this->assertSame(3, $this->rowCounts()['links']);
    }

    public function test_oversized_chunks_are_rejected(): void
    {
        config()->set('coqui.sync.max_urls_per_chunk', 3);
        config()->set('coqui.sync.max_links_per_chunk', 2);

        $urls = array_map(static fn (int $i): array => ['url' => "https://coquiseo.com/p/{$i}"], range(1, 4));
        $this->postJson($this->chunkUrl(), ['urls' => $urls])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('urls');

        $this->postJson($this->chunkUrl(), ['links' => $this->chunk()['links']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('links');

        $this->assertSame(['urls' => 0, 'links' => 0, 'issues' => 0], $this->rowCounts());
    }

    public function test_a_finalized_run_takes_no_more_chunks_unless_paused(): void
    {
        $this->postJson($this->chunkUrl(), $this->chunk())->assertOk();

        // Paused: the crawl can resume and keep uploading.
        $this->postJson($this->finalizeUrl(), ['status' => 'paused'])->assertOk();
        $this->postJson($this->chunkUrl(), [
            'urls' => [['url' => 'https://coquiseo.com/about', 'status' => 200]],
        ])->assertOk();

        $this->postJson($this->finalizeUrl(), ['status' => 'completed'])->assertOk();
        $this->postJson($this->chunkUrl(), [
            'urls' => [['url' => 'https://coquiseo.com/late', 'status' => 200]],
        ])->assertStatus(409)->assertJson(['status' => 'completed']);

        $this->assertSame(3, $this->rowCounts()['urls']);
    }

    public function test_resuming_a_paused_run_reopens_it_for_chunks(): void
    {
        $this->postJson($this->chunkUrl(), $this->chunk())->assertOk();
        $this->postJson($this->finalizeUrl(), ['status' => 'paused'])->assertOk();

        // The client resumes and re-syncs the run as running.
        $this->postJson("/api/v1/projects/{$this->project->uuid}/crawls/sync", [
            'id' => $this->crawlId,
            'seed' => 'https://coquiseo.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 3],
            'createdAt' => 1795022400000,
        ])->assertSuccessful();

        $run = CrawlRun::query()->where('external_crawl_id', $this->crawlId)->firstOrFail();
        $this->assertSame('running', $run->status);
        $this->assertNull($run->finalized_at);

        $this->postJson($this->chunkUrl(), [
            'urls' => [['url' => 'https://coquiseo.com/about', 'status' => 200]],
        ])->assertOk();

        $this->postJson($this->finalizeUrl(), ['status' => 'completed'])->assertOk();
        $this->postJson($this->chunkUrl(), [
            'urls' => [['url' => 'https://coquiseo.com/late', 'status' => 200]],
        ])->assertStatus(409);

        $this->assertSame(3, $this->rowCounts()['urls']);
    }

    public function test_finalize_honors_issue_counts_and_broadcasts_crawl_synced(): void
    {
        Event::fake([CrawlSynced::class]);
        $this->postJson($this->chunkUrl(), $this->chunk())->assertOk();

        // The client found more issues than it uploaded.
        $this->postJson($this->finalizeUrl(), [
            'status' => 'completed',
            'issueCounts' => [
                ['rule' => 'missing_meta_description', 'severity' => 'warning', 'count' => 40],
                ['rule' => 'broken_link', 'severity' => 'error', 'count' => 3],
                ['rule' => 'thin_content', 'severity' => 'opportunity', 'count' => 7],
                ['rule' => 'missing_h1', 'severity' => 'warning', 'count' => 2],
            ],
        ])->assertOk()->assertJson([
            'status' => 'completed',
            'totalUrls' => 2,
            'errors' => 3,
            'warnings' => 42,
            'opportunities' => 7,
        ]);

        $run = CrawlRun::query()->where('external_crawl_id', $this->crawlId)->firstOrFail();
        $this->assertCount(4, $run->config['issue_counts']);

        Event::assertDispatched(CrawlSynced::class, fn (CrawlSynced $event): bool => $event->externalCrawlId === $this->crawlId
            && $event->projectId === $this->project->id
            && $event->status === 'completed'
            && $event->errorCount === 3
            && $event->warningCount === 42
            && $event->broadcastAs() === 'CrawlSynced');
    }

    public function test_finalize_counts_uploaded_issues_without_issue_counts(): void
    {
        $this->postJson($this->chunkUrl(), $this->chunk())->assertOk();

        $this->postJson($this->finalizeUrl(), ['status' => 'completed'])
            ->assertOk()
            ->assertJson(['errors' => 0, 'warnings' => 1, 'opportunities' => 0]);
    }

    public function test_issue_counts_are_validated(): void
    {
        $this->postJson($this->finalizeUrl(), [
            'issueCounts' => [['rule' => 'x', 'severity' => 'critical', 'count' => -1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['issueCounts.0.severity', 'issueCounts.0.count']);
    }

    public function test_a_fifty_thousand_url_sync_stays_within_a_memory_bound(): void
    {
        $run = CrawlRun::query()->where('external_crawl_id', $this->crawlId)->firstOrFail();
        $ingest = app(IngestDesktopCrawlSnapshot::class);

        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        for ($chunk = 0; $chunk < 50; $chunk++) {
            $urls = [];
            $links = [];
            for ($i = 0; $i < 1000; $i++) {
                $n = $chunk * 1000 + $i;
                $urls[] = ['url' => "https://coquiseo.com/page/{$n}", 'status' => 200, 'depth' => 2, 'title' => "Page {$n}"];
                $links[] = ['fromUrl' => 'https://coquiseo.com/', 'toUrl' => "https://coquiseo.com/page/{$n}", 'anchor' => "Page {$n}"];
            }
            $ingest->ingestChunk($run, $urls, $links, []);
        }

        $peakGrowthMb = (memory_get_peak_usage() - $baseline) / 1024 / 1024;

        $this->assertSame(50000, DB::table('crawl_urls')->where('crawl_run_id', $run->id)->count());
        $this->assertSame(50000, DB::table('crawl_links')->where('crawl_run_id', $run->id)->count());
        // Memory is bounded by one chunk, not by the size of the crawl.
        $this->assertLessThan(64, $peakGrowthMb, sprintf('Peak memory grew by %.1f MB', $peakGrowthMb));
    }
}
