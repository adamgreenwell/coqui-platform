<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlDiffReport;
use App\Domains\Crawls\Models\CrawlIssue;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Models\CrawlUrl;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\SearchPerformanceSyncService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use App\Support\PageUrl;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrawlCompareTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    private CrawlRun $baselineRun;

    private CrawlRun $targetRun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'agency',
            'name' => 'Acme SEO Team',
            'slug' => 'acme-seo-team',
            'owner_id' => $this->user->id,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Acme Store',
            'domain' => 'acme.test',
            'canonical_url' => 'https://acme.test/',
        ]);

        $this->baselineRun = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => (string) Str::uuid(),
            'seed' => 'https://acme.test/',
            'seed_kind' => 'url',
            'source' => 'desktop',
            'status' => 'completed',
            'config' => ['maxDepth' => 3, 'maxUrls' => 500],
            'total_urls' => 5,
            'error_count' => 0,
            'warning_count' => 1,
            'opportunity_count' => 0,
            'created_at' => Carbon::now()->subDays(7),
            'finalized_at' => Carbon::now()->subDays(7)->addMinutes(10),
        ]);

        $this->targetRun = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => (string) Str::uuid(),
            'seed' => 'https://acme.test/',
            'seed_kind' => 'url',
            'source' => 'desktop',
            'status' => 'completed',
            'config' => ['maxDepth' => 3, 'maxUrls' => 500],
            'total_urls' => 5,
            'error_count' => 1,
            'warning_count' => 1,
            'opportunity_count' => 0,
            'created_at' => Carbon::now(),
            'finalized_at' => Carbon::now()->addMinutes(12),
        ]);
    }

    public function test_user_can_view_crawl_comparison_screen_with_status_transitions_and_metadata_diffs(): void
    {
        // URL 1: Newly broken (200 in baseline -> 404 in target)
        $url1 = 'https://acme.test/blog/legacy-post';
        $hash1 = hash('sha256', $url1);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->baselineRun->id,
            'url' => $url1,
            'url_hash' => $hash1,
            'status' => 200,
            'title' => 'Legacy Blog Post',
            'canonical' => $url1,
            'meta_robots' => 'index, follow',
        ]);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->targetRun->id,
            'url' => $url1,
            'url_hash' => $hash1,
            'status' => 404,
            'title' => null,
            'canonical' => null,
            'meta_robots' => null,
        ]);

        // URL 2: Redirected (200 -> 301)
        $url2 = 'https://acme.test/pricing';
        $hash2 = hash('sha256', $url2);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->baselineRun->id,
            'url' => $url2,
            'url_hash' => $hash2,
            'status' => 200,
            'title' => 'Pricing Plans',
        ]);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->targetRun->id,
            'url' => $url2,
            'url_hash' => $hash2,
            'status' => 301,
            'redirect_target' => 'https://acme.test/plans',
            'title' => 'Pricing Plans',
        ]);

        // URL 3: Metadata changed (title and meta_robots)
        $url3 = 'https://acme.test/about';
        $hash3 = hash('sha256', $url3);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->baselineRun->id,
            'url' => $url3,
            'url_hash' => $hash3,
            'status' => 200,
            'title' => 'About Acme Inc',
            'canonical' => 'https://acme.test/about',
            'meta_robots' => 'index, follow',
        ]);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->targetRun->id,
            'url' => $url3,
            'url_hash' => $hash3,
            'status' => 200,
            'title' => 'About Acme Corporation (Updated)',
            'canonical' => 'https://acme.test/about-us',
            'meta_robots' => 'noindex, follow',
        ]);

        // URL 4: New URL added in target
        $url4 = 'https://acme.test/new-product';
        $hash4 = hash('sha256', $url4);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->targetRun->id,
            'url' => $url4,
            'url_hash' => $hash4,
            'status' => 200,
            'title' => 'Brand New Product',
        ]);

        // URL 5: Removed URL (present in baseline, missing in target)
        $url5 = 'https://acme.test/deprecated-feature';
        $hash5 = hash('sha256', $url5);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->baselineRun->id,
            'url' => $url5,
            'url_hash' => $hash5,
            'status' => 200,
            'title' => 'Old Deprecated Feature',
        ]);

        $response = $this->actingAs($this->user)->get(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/compare?baseline={$this->baselineRun->external_crawl_id}&target={$this->targetRun->external_crawl_id}"
        );

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Crawls/Compare')
            ->has('workspace')
            ->has('project')
            ->has('availableRuns')
            ->where('isReadOnly', false)
            ->where('diff.summary.delta.newUrlsCount', 1)
            ->where('diff.summary.delta.removedUrlsCount', 1)
            ->where('diff.summary.delta.newlyBrokenCount', 1)
            ->where('diff.newlyBroken.0.url', $url1)
            ->where('diff.newlyBroken.0.baselineStatus', 200)
            ->where('diff.newlyBroken.0.targetStatus', 404)
            ->where('diff.urlSamples.newUrls.0.url', $url4)
            ->where('diff.urlSamples.removedUrls.0.url', $url5)
        );
    }

    public function test_gsc_clicks_are_overlaid_and_prioritized_on_newly_broken_urls(): void
    {
        // High traffic URL: 200 -> 500
        $highTrafficUrl = 'https://acme.test/shop/top-seller';
        $highHash = hash('sha256', $highTrafficUrl);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->baselineRun->id,
            'url' => $highTrafficUrl,
            'url_hash' => $highHash,
            'status' => 200,
        ]);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->targetRun->id,
            'url' => $highTrafficUrl,
            'url_hash' => $highHash,
            'status' => 500,
        ]);

        // Low traffic URL: 200 -> 404
        $lowTrafficUrl = 'https://acme.test/shop/rare-item';
        $lowHash = hash('sha256', $lowTrafficUrl);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->baselineRun->id,
            'url' => $lowTrafficUrl,
            'url_hash' => $lowHash,
            'status' => 200,
        ]);
        CrawlUrl::query()->create([
            'crawl_run_id' => $this->targetRun->id,
            'url' => $lowTrafficUrl,
            'url_hash' => $lowHash,
            'status' => 404,
        ]);

        // A real Search Console sync writes the page rows. GSC reports URL
        // variants of one page (trailing slash, http) that crawls don't use.
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'site_url' => 'https://acme.test/',
            'external_identifier' => 'https://acme.test/',
            'status' => 'connected',
            'credentials_encrypted' => [
                'access_token' => 'active-access-token',
                'refresh_token' => 'active-refresh-token',
                'expires_at' => Carbon::now()->addHour()->toIso8601String(),
            ],
        ]);
        $day = Carbon::now()->subDays(5)->toDateString();

        Http::fake([
            'https://searchconsole.googleapis.com/*' => fn (Request $request) => Http::response([
                'rows' => $request['dimensions'] === ['page', 'date'] ? [
                    ['keys' => ['https://acme.test/shop/top-seller/', $day], 'clicks' => 600, 'impressions' => 9000, 'ctr' => 0.0667, 'position' => 2.0],
                    ['keys' => ['http://acme.test/shop/top-seller', $day], 'clicks' => 250, 'impressions' => 3500, 'ctr' => 0.0714, 'position' => 3.0],
                    ['keys' => ['https://acme.test/shop/rare-item', $day], 'clicks' => 45, 'impressions' => 600, 'ctr' => 0.075, 'position' => 4.1],
                ] : [],
            ]),
        ]);

        $result = app(SearchPerformanceSyncService::class)->syncGoogleSearchConsole($integration);
        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['pages_synced'] ?? null);

        $response = $this->actingAs($this->user)->get(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/compare?baseline={$this->baselineRun->external_crawl_id}&target={$this->targetRun->external_crawl_id}"
        );

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Crawls/Compare')
            ->where('diff.summary.delta.newlyBrokenCount', 2)
            ->where('diff.summary.delta.trafficImpactedBrokenUrls', 2)
            ->where('diff.summary.delta.totalLostClicks', 895)
            // Highest GSC clicks should be prioritized first
            ->where('diff.newlyBroken.0.url', $highTrafficUrl)
            ->where('diff.newlyBroken.0.gscClicks', 850)
            ->where('diff.newlyBroken.1.url', $lowTrafficUrl)
            ->where('diff.newlyBroken.1.gscClicks', 45)
        );
    }

    public function test_url_variants_of_one_page_count_its_clicks_once_in_the_lost_total(): void
    {
        foreach (['https://acme.test/shop/item', 'https://acme.test/shop/item/'] as $url) {
            foreach ([[$this->baselineRun, 200], [$this->targetRun, 404]] as [$run, $status]) {
                CrawlUrl::query()->create([
                    'crawl_run_id' => $run->id,
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                    'status' => $status,
                ]);
            }
        }

        DB::table('search_performance_pages')->insert([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'url' => 'https://acme.test/shop/item',
            'url_hash' => PageUrl::hash('/shop/item'),
            'date' => Carbon::now()->subDays(5)->toDateString(),
            'clicks' => 100,
            'impressions' => 1000,
            'ctr' => 0.1,
            'position' => 3.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->get(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/compare?baseline={$this->baselineRun->external_crawl_id}&target={$this->targetRun->external_crawl_id}"
        )->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('diff.summary.delta.newlyBrokenCount', 2)
            // Each variant shows the page's traffic, but the total counts it once.
            ->where('diff.newlyBroken.0.gscClicks', 100)
            ->where('diff.newlyBroken.1.gscClicks', 100)
            ->where('diff.summary.delta.totalLostClicks', 100)
        );
    }

    public function test_issue_deltas_compute_new_resolved_and_persistent_issues(): void
    {
        $urlA = 'https://acme.test/page-a';
        $hashA = hash('sha256', $urlA);
        $urlB = 'https://acme.test/page-b';
        $hashB = hash('sha256', $urlB);
        $urlC = 'https://acme.test/page-c';
        $hashC = hash('sha256', $urlC);

        // Resolved issue (in baseline, absent in target)
        CrawlIssue::query()->create([
            'crawl_run_id' => $this->baselineRun->id,
            'url' => $urlA,
            'url_hash' => $hashA,
            'rule' => 'missing-h1',
            'severity' => 'warning',
            'detail' => 'Page is missing primary H1 tag',
        ]);

        // New issue (in target, absent in baseline)
        CrawlIssue::query()->create([
            'crawl_run_id' => $this->targetRun->id,
            'url' => $urlB,
            'url_hash' => $hashB,
            'rule' => 'broken-link',
            'severity' => 'error',
            'detail' => 'Internal link returns 404',
        ]);

        // Persistent issue (present in both)
        CrawlIssue::query()->create([
            'crawl_run_id' => $this->baselineRun->id,
            'url' => $urlC,
            'url_hash' => $hashC,
            'rule' => 'slow-ttfb',
            'severity' => 'opportunity',
            'detail' => 'Server responded in 1420ms',
        ]);
        CrawlIssue::query()->create([
            'crawl_run_id' => $this->targetRun->id,
            'url' => $urlC,
            'url_hash' => $hashC,
            'rule' => 'slow-ttfb',
            'severity' => 'opportunity',
            'detail' => 'Server responded in 1380ms',
        ]);

        $response = $this->actingAs($this->user)->get(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/compare?baseline={$this->baselineRun->external_crawl_id}&target={$this->targetRun->external_crawl_id}"
        );

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Crawls/Compare')
            ->where('diff.summary.delta.newIssuesCount', 1)
            ->where('diff.summary.delta.resolvedIssuesCount', 1)
            ->where('diff.summary.delta.persistentIssuesCount', 1)
            ->where('diff.issueDeltas.newIssues.0.rule', 'broken-link')
            ->where('diff.issueDeltas.resolvedIssues.0.rule', 'missing-h1')
        );
    }

    public function test_user_can_create_shareable_diff_report(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/compare/share",
            [
                'baseline_id' => $this->baselineRun->external_crawl_id,
                'target_id' => $this->targetRun->external_crawl_id,
                'title' => 'Client Q3 Technical SEO Audit',
                'expires_in_days' => 30,
            ]
        );

        $response->assertCreated();
        $response->assertJsonStructure([
            'shareToken',
            'shareUrl',
            'expiresAt',
        ]);

        $token = $response->json('shareToken');
        $this->assertNotEmpty($token);

        $this->assertDatabaseHas('crawl_diff_reports', [
            'project_id' => $this->project->id,
            'baseline_run_id' => $this->baselineRun->id,
            'target_run_id' => $this->targetRun->id,
            'share_token' => $token,
            'title' => 'Client Q3 Technical SEO Audit',
            'created_by_user_id' => $this->user->id,
        ]);
    }

    public function test_guest_can_view_public_diff_report(): void
    {
        $report = CrawlDiffReport::query()->create([
            'project_id' => $this->project->id,
            'baseline_run_id' => $this->baselineRun->id,
            'target_run_id' => $this->targetRun->id,
            'share_token' => 'safe_diff_report_token_123456789012345678901234',
            'title' => 'Client Public Report',
            'expires_at' => Carbon::now()->addDays(7),
            'view_count' => 0,
        ]);

        $response = $this->get("/reports/crawls/diff/{$report->share_token}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Crawls/Compare')
            ->where('isReadOnly', true)
            ->where('reportTitle', 'Client Public Report')
            ->has('diff')
        );

        $this->assertEquals(1, $report->fresh()->view_count);
    }

    public function test_guest_receives_404_for_expired_diff_report(): void
    {
        $expiredReport = CrawlDiffReport::query()->create([
            'project_id' => $this->project->id,
            'baseline_run_id' => $this->baselineRun->id,
            'target_run_id' => $this->targetRun->id,
            'share_token' => 'expired_diff_report_token_12345678901234567890',
            'title' => 'Expired Client Public Report',
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->get("/reports/crawls/diff/{$expiredReport->share_token}");
        $response->assertNotFound();
    }

    public function test_standalone_api_can_fetch_crawl_diff_with_sanctum_token(): void
    {
        Sanctum::actingAs($this->user, ['crawls:sync']);

        $response = $this->getJson(
            "/api/v1/projects/{$this->project->uuid}/crawls/compare?baseline={$this->baselineRun->external_crawl_id}&target={$this->targetRun->external_crawl_id}"
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'summary' => [
                'baseline' => ['id', 'externalCrawlId', 'status', 'totalUrls'],
                'target' => ['id', 'externalCrawlId', 'status', 'totalUrls'],
                'delta' => [
                    'totalUrls',
                    'newUrlsCount',
                    'removedUrlsCount',
                    'newlyBrokenCount',
                    'statusChangesCount',
                    'newIssuesCount',
                    'resolvedIssuesCount',
                    'persistentIssuesCount',
                    'trafficImpactedBrokenUrls',
                    'totalLostClicks',
                ],
            ],
            'newlyBroken',
            'transitions',
            'issueDeltas',
            'urlSamples',
        ]);
    }

    public function test_crawl_compare_requires_view_permission(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->get(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/compare?baseline={$this->baselineRun->external_crawl_id}&target={$this->targetRun->external_crawl_id}"
        );

        $response->assertForbidden();
    }
}
