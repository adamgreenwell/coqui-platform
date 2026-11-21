<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlDiffReport;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Services\CrawlDiffService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Http\Controllers\CrawlCompareController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CrawlDiffDrillDownTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    private CrawlRun $baseline;

    private CrawlRun $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'agency',
            'name' => 'Acme SEO Team',
            'slug' => 'acme-seo-team',
            'owner_id' => $this->owner->id,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Acme Store',
            'domain' => 'acme.test',
            'canonical_url' => 'https://acme.test/',
        ]);
        $this->baseline = $this->crawlRun(Carbon::now()->subDays(7));
        $this->target = $this->crawlRun(Carbon::now());
    }

    private function crawlRun(Carbon $at): CrawlRun
    {
        /** @var CrawlRun */
        return CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => (string) Str::uuid(),
            'seed' => 'https://acme.test/',
            'seed_kind' => 'url',
            'source' => 'desktop',
            'status' => 'completed',
            'config' => [],
            'total_urls' => 0,
            'created_at' => $at,
            'finalized_at' => $at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function page(CrawlRun $run, string $url, array $fields = []): void
    {
        $now = now();
        DB::table('crawl_urls')->insert(array_merge([
            'crawl_run_id' => $run->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'status' => 200,
            'depth' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $fields));
    }

    private function issue(CrawlRun $run, string $url, string $rule, string $severity = 'warning', ?string $detail = null): void
    {
        $now = now();
        DB::table('crawl_issues')->insert([
            'crawl_run_id' => $run->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'rule' => $rule,
            'severity' => $severity,
            'detail' => $detail,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function base(): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/compare";
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function report(array $attributes = []): CrawlDiffReport
    {
        /** @var CrawlDiffReport */
        return CrawlDiffReport::query()->create(array_merge([
            'project_id' => $this->project->id,
            'baseline_run_id' => $this->baseline->id,
            'target_run_id' => $this->target->id,
            'share_token' => CrawlDiffReport::generateShareToken(),
            'title' => 'Client report',
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }

    public function test_issue_samples_show_what_changed_on_each_page(): void
    {
        $url = 'https://acme.test/pricing';
        $this->page($this->baseline, $url, ['title' => 'Pricing', 'meta_robots' => 'index, follow', 'word_count' => 900]);
        $this->page($this->target, $url, ['title' => 'Pricing', 'meta_robots' => 'noindex', 'word_count' => 120]);
        $this->issue($this->target, $url, 'noindex-page', 'error', 'Page is noindex');

        $this->page($this->target, 'https://acme.test/new', ['title' => 'New']);
        $this->issue($this->target, 'https://acme.test/new', 'thin-content');

        $diff = app(CrawlDiffService::class)->compare($this->baseline, $this->target);
        $byRule = collect($diff['issueDeltas']['newIssues'])->keyBy('rule');

        $noindex = $byRule['noindex-page'];
        $this->assertSame(['metaRobots', 'wordCount'], $noindex['changedFields']);
        $this->assertSame('index, follow', $noindex['before']['metaRobots']);
        $this->assertSame('noindex', $noindex['after']['metaRobots']);
        $this->assertSame(120, $noindex['after']['wordCount']);

        $this->assertNull($byRule['thin-content']['before']);
        $this->assertSame('New', $byRule['thin-content']['after']['title']);
        $this->assertSame(2, $diff['summary']['delta']['newIssuesCount']);
    }

    public function test_a_rules_affected_urls_are_paginated(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $url = "https://acme.test/p/{$i}";
            $this->page($this->baseline, $url, ['title' => "Page {$i}"]);
            $this->page($this->target, $url, ['title' => '']);
            $this->issue($this->target, $url, 'missing-title');
        }
        $this->issue($this->target, 'https://acme.test/other', 'missing-h1');
        $this->issue($this->baseline, 'https://acme.test/fixed', 'missing-title');

        $query = fn (array $extra) => $this->base().'/issues?'.http_build_query(array_merge([
            'baseline' => $this->baseline->external_crawl_id,
            'target' => $this->target->external_crawl_id,
            'rule' => 'missing-title',
            'type' => 'new',
        ], $extra));

        $this->actingAs($this->owner)->getJson($query(['page' => 2]))
            ->assertOk()
            ->assertJsonPath('total', 60)
            ->assertJsonPath('page', 2)
            ->assertJsonPath('lastPage', 2)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.changedFields', ['title'])
            ->assertJsonPath('data.0.before.title', 'Page 51');

        $this->actingAs($this->owner)->getJson($query(['type' => 'resolved']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.url', 'https://acme.test/fixed')
            ->assertJsonPath('data.0.after', null);

        $this->actingAs($this->owner)->getJson($query(['type' => 'sideways']))->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson($query([]))->assertForbidden();
    }

    public function test_a_shared_report_can_be_drilled_into_until_it_is_revoked(): void
    {
        $this->issue($this->target, 'https://acme.test/a', 'missing-h1');
        $report = $this->report();

        $url = "/reports/crawls/diff/{$report->share_token}/issues?type=new&rule=missing-h1";
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1);

        $this->actingAs($this->owner)
            ->deleteJson($this->base()."/shares/{$report->uuid}")
            ->assertOk();

        auth()->logout();
        $this->getJson($url)->assertNotFound();
        $this->get("/reports/crawls/diff/{$report->share_token}")->assertNotFound();
        $this->assertNotNull($report->fresh()?->revoked_at);
        $this->assertSame($this->owner->id, $report->fresh()?->revoked_by_user_id);
    }

    public function test_only_editors_create_or_revoke_share_links(): void
    {
        $viewer = $this->member('viewer');
        $report = $this->report();
        $payload = [
            'baseline_id' => $this->baseline->external_crawl_id,
            'target_id' => $this->target->external_crawl_id,
        ];

        $this->actingAs($viewer)->postJson($this->base().'/share', $payload)->assertForbidden();
        $this->actingAs($viewer)->deleteJson($this->base()."/shares/{$report->uuid}")->assertForbidden();
        $this->actingAs($viewer)->get($this->base())
            ->assertInertia(fn (Assert $page) => $page->where('canManageShares', false)->where('shareLinks', []));

        $editor = $this->member('editor');
        $this->actingAs($editor)->postJson($this->base().'/share', $payload)->assertCreated();
        $this->actingAs($editor)->get($this->base())
            ->assertInertia(fn (Assert $page) => $page->where('canManageShares', true)->has('shareLinks', 2));
    }

    public function test_every_active_share_link_can_be_reached_for_revoking(): void
    {
        $per = CrawlCompareController::SHARE_LINKS_PER_PAGE;
        $reports = collect(range(1, $per + 5))->map(fn (int $i): CrawlDiffReport => $this->report(['title' => "Link {$i}"]));
        $this->report(['title' => 'Revoked', 'revoked_at' => now()]);
        $oldest = $reports->first();

        $page = $this->actingAs($this->owner)->get($this->base())
            ->assertInertia(fn (Assert $p) => $p->has('shareLinks', $per)->whereNot('shareLinksNextCursor', null));
        $cursor = $page->viewData('page')['props']['shareLinksNextCursor'];

        // Revoking a link on the first page doesn't shift the next one.
        $this->actingAs($this->owner)->deleteJson($this->base().'/shares/'.$reports->last()->uuid)->assertOk();

        $next = $this->actingAs($this->owner)->getJson($this->base().'/shares?after='.$cursor)
            ->assertOk()
            ->assertJsonCount(5, 'shareLinks')
            ->assertJsonPath('shareLinksNextCursor', null);
        $this->assertContains($oldest->uuid, array_column($next->json('shareLinks'), 'id'));
        $this->assertNotContains('Revoked', array_column($next->json('shareLinks'), 'title'));

        $this->actingAs($this->member('viewer'))->getJson($this->base().'/shares?after='.$cursor)->assertForbidden();
    }

    public function test_share_links_expire_after_30_days_by_default(): void
    {
        Carbon::setTestNow('2026-11-21 12:00:00');

        $response = $this->actingAs($this->owner)->postJson($this->base().'/share', [
            'baseline_id' => $this->baseline->external_crawl_id,
            'target_id' => $this->target->external_crawl_id,
        ])->assertCreated();

        $this->assertSame('2026-12-21', Carbon::parse($response->json('expiresAt'))->toDateString());

        Carbon::setTestNow();
    }

    public function test_a_report_from_another_project_cannot_be_revoked_here(): void
    {
        $other = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other',
            'domain' => 'other.test',
            'canonical_url' => 'https://other.test/',
        ]);
        $report = $this->report(['project_id' => $other->id]);

        $this->actingAs($this->owner)->deleteJson($this->base()."/shares/{$report->uuid}")->assertNotFound();
        $this->assertNull($report->fresh()?->revoked_at);
    }

    public function test_a_10k_url_diff_completes_in_under_two_seconds(): void
    {
        $now = now()->toDateTimeString();
        foreach ([$this->baseline, $this->target] as $run) {
            $isTarget = $run->is($this->target);
            $urls = [];
            $issues = [];
            for ($i = 0; $i < 10_000; $i++) {
                $url = "https://acme.test/page/{$i}";
                $hash = hash('sha256', $url);
                $urls[] = [
                    'crawl_run_id' => $run->id,
                    'url' => $url,
                    'url_hash' => $hash,
                    // A tenth of pages break, and a tenth change title.
                    'status' => $isTarget && $i % 10 === 0 ? 404 : 200,
                    'title' => $isTarget && $i % 10 === 1 ? "Changed {$i}" : "Page {$i}",
                    'depth' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($i % ($isTarget ? 4 : 5) === 0) {
                    $issues[] = [
                        'crawl_run_id' => $run->id,
                        'url' => $url,
                        'url_hash' => $hash,
                        'rule' => $i % 2 === 0 ? 'missing-h1' : 'thin-content',
                        'severity' => 'warning',
                        'detail' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($urls, 500) as $chunk) {
                DB::table('crawl_urls')->insert($chunk);
            }
            foreach (array_chunk($issues, 500) as $chunk) {
                DB::table('crawl_issues')->insert($chunk);
            }
        }

        // Production tables have planner statistics; rows inserted inside
        // the test transaction don't, so Postgres could plan for a near-empty
        // table and take nearly a minute. ANALYZE counts this transaction's rows.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ANALYZE crawl_urls, crawl_issues');
        }

        $started = microtime(true);
        $diff = app(CrawlDiffService::class)->compare($this->baseline, $this->target);
        $elapsed = microtime(true) - $started;

        $this->assertSame(1_000, $diff['summary']['delta']['newlyBrokenCount']);
        $this->assertGreaterThan(0, $diff['summary']['delta']['newIssuesCount']);
        $this->assertLessThan(2.0, $elapsed, sprintf('10k-URL diff took %.2fs', $elapsed));
    }
}
