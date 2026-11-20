<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\RankFallbackCheck;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\RankFallback\FetchFallbackRankJob;
use App\Domains\Serp\RankFallback\RankFallbackSpendGuard;
use App\Domains\Serp\RankFallback\SerphouseResultAdapter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RankFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'test-dfs-login');
        config()->set('coqui.dataforseo.password', 'test-dfs-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');
        config()->set('coqui.serphouse.api_key', 'test-serphouse-key');
        config()->set('coqui.serphouse.base_url', 'https://api.serphouse.test');
        config()->set('coqui.serphouse.fallback_enabled', true);

        $owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Fallback Workspace',
            'slug' => 'fallback-workspace',
            'owner_id' => $owner->id,
            'monthly_dfs_budget_usd' => 25.00,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui App',
            'domain' => 'coqui.io',
            'canonical_url' => 'https://coqui.io/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
        ProjectCompetitor::query()->create(['project_id' => $this->project->id, 'domain' => 'competitor-a.com', 'label' => 'A', 'sort_order' => 1]);
        ProjectCompetitor::query()->create(['project_id' => $this->project->id, 'domain' => 'competitor-b.org', 'label' => 'B', 'sort_order' => 2]);
    }

    public function test_adapter_numbers_organic_results_across_pages_and_maps_features(): void
    {
        $first = $this->page(['a.com', 'b.com'], [
            'ai_overview' => ['contents' => [['type' => 'heading']], 'references' => [['link' => 'https://www.coqui.io/guide']]],
            'people_also_ask' => [['question' => 'What?']],
        ]);
        // SERPHouse may restart positions on each page; ranks are counted.
        $second = $this->page(['c.com'], [], 1);

        $items = (new SerphouseResultAdapter)->toItems([$first, $second]);

        $organic = array_values(array_filter($items, fn (array $item): bool => $item['type'] === 'organic'));
        $this->assertSame([1, 2, 3], array_column($organic, 'rank_group'));
        $this->assertSame('c.com', $organic[2]['domain']);
        $this->assertContains('people_also_ask', array_column($items, 'type'));

        $overview = collect($items)->firstWhere('type', 'ai_overview');
        $this->assertSame([['url' => 'https://www.coqui.io/guide']], $overview['references']);
    }

    public function test_command_queues_checks_for_failed_and_stale_primary_tasks(): void
    {
        Queue::fake();

        $failed = $this->keyword('failed keyword');
        $stale = $this->keyword('stale keyword');
        $fresh = $this->keyword('fresh keyword');
        $delivered = $this->keyword('delivered keyword', lastCheckedAt: now()->subMinutes(30));

        $this->primaryTask($failed, 'failed', now()->subHour());
        $staleTaskId = $this->primaryTask($stale, 'pending', now()->subHours(7));
        $this->primaryTask($fresh, 'pending', now()->subHour());
        $this->primaryTask($delivered, 'failed', now()->subHours(2));

        $this->artisan('rank:fallback')->assertSuccessful();

        $checks = RankFallbackCheck::query()->orderBy('tracked_keyword_id')->get();
        $this->assertSame([$failed->id, $stale->id], $checks->pluck('tracked_keyword_id')->all());
        $this->assertSame([RankFallbackCheck::REASON_FAILED, RankFallbackCheck::REASON_STALE], $checks->pluck('reason')->all());
        $this->assertSame($staleTaskId, $checks[1]->dataforseo_task_id);
        $this->assertSame(['queued', 'queued'], $checks->pluck('status')->all());
        Queue::assertPushed(FetchFallbackRankJob::class, 2);

        // Running again doesn't queue the same keywords twice on one day.
        $this->artisan('rank:fallback')->assertSuccessful();
        Queue::assertPushed(FetchFallbackRankJob::class, 2);
    }

    public function test_command_does_nothing_when_the_fallback_is_off(): void
    {
        Queue::fake();
        config()->set('coqui.serphouse.fallback_enabled', false);

        $this->primaryTask($this->keyword('failed keyword'), 'failed', now()->subHour());

        $this->artisan('rank:fallback')->assertSuccessful();

        $this->assertSame(0, RankFallbackCheck::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_job_records_rankings_stops_once_everyone_is_found_and_supersedes_the_primary_task(): void
    {
        $keyword = $this->keyword('voice generator');
        $taskId = $this->primaryTask($keyword, 'pending', now()->subHours(7));
        $check = $this->check($keyword, $taskId, now()->subHours(7));

        Http::fake([
            'api.serphouse.test/*' => Http::sequence()
                ->push($this->page(['x.com', 'competitor-a.com', 'y.com', 'z.com', 'q.com', 'w.com', 'e.com', 'r.com', 't.com', 'u.com']))
                ->push($this->page(['i.com', 'o.com', 'www.coqui.io', 'p.com', 'competitor-b.org', 's.com', 'd.com', 'f.com', 'g.com', 'h.com']))
                ->push($this->page(['never-requested.com'])),
        ]);

        FetchFallbackRankJob::dispatchSync($check->id);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-serphouse-key')
            && $request['data']['loc_id'] === 2840
            && $request['data']['page'] === 2
            && $request['data']['device'] === 'desktop');

        $ranks = KeywordRankSnapshot::query()->where('tracked_keyword_id', $keyword->id)->orderBy('domain')->pluck('rank_group', 'domain')->all();
        $this->assertSame(['competitor-a.com' => 2, 'competitor-b.org' => 15, 'coqui.io' => 13], $ranks);

        $check->refresh();
        $this->assertSame('completed', $check->status);
        $this->assertSame(2, $check->pages_fetched);
        $this->assertEqualsWithDelta(0.0015, (float) $check->cost_usd, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $check->reserved_usd, 0.000001);
        $this->assertSame('superseded', DB::table('dataforseo_tasks')->where('id', $taskId)->value('status'));
        $this->assertNotNull($keyword->fresh()?->last_checked_at);
    }

    public function test_job_records_unranked_domains_after_the_last_page(): void
    {
        config()->set('coqui.serphouse.max_pages', 2);
        $keyword = $this->keyword('niche phrase');
        $check = $this->check($keyword, null, now()->subMinute());

        Http::fake(['api.serphouse.test/*' => Http::sequence()
            ->push($this->page(['competitor-a.com']))
            ->push($this->page(['other.com']))]);

        FetchFallbackRankJob::dispatchSync($check->id);

        Http::assertSentCount(2);
        $this->assertNull(KeywordRankSnapshot::query()->where('tracked_keyword_id', $keyword->id)->where('domain', 'coqui.io')->value('rank_group'));
        $this->assertSame('completed', $check->fresh()?->status);
    }

    public function test_a_retry_resumes_after_the_pages_already_fetched(): void
    {
        $keyword = $this->keyword('voice generator');
        $check = $this->check($keyword, null, now()->subHour());
        // An earlier attempt fetched and paid for page 1, then timed out.
        $check->update([
            'pages' => [$this->page(['x.com', 'competitor-a.com', 'y.com', 'z.com', 'q.com', 'w.com', 'e.com', 'r.com', 't.com', 'u.com'])],
            'pages_fetched' => 1,
            'cost_usd' => 0.00075,
        ]);

        Http::fake(['api.serphouse.test/*' => Http::sequence()
            ->push($this->page(['i.com', 'o.com', 'www.coqui.io', 'p.com', 'competitor-b.org', 's.com', 'd.com', 'f.com', 'g.com', 'h.com']))]);

        FetchFallbackRankJob::dispatchSync($check->id);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['data']['page'] === 2);

        $ranks = KeywordRankSnapshot::query()->where('tracked_keyword_id', $keyword->id)->orderBy('domain')->pluck('rank_group', 'domain')->all();
        $this->assertSame(['competitor-a.com' => 2, 'competitor-b.org' => 15, 'coqui.io' => 13], $ranks);

        $check->refresh();
        $this->assertSame('completed', $check->status);
        $this->assertSame(2, $check->pages_fetched);
        $this->assertEqualsWithDelta(0.0015, (float) $check->cost_usd, 0.000001);
    }

    public function test_job_does_not_record_over_a_primary_task_collected_while_it_ran(): void
    {
        $keyword = $this->keyword('voice generator');
        $taskId = $this->primaryTask($keyword, 'collected', now()->subHours(7));
        $check = $this->check($keyword, $taskId, now()->subHours(7));

        Http::fake(['api.serphouse.test/*' => Http::response($this->page(['www.coqui.io', 'competitor-a.com', 'competitor-b.org']))]);

        FetchFallbackRankJob::dispatchSync($check->id);

        $this->assertSame('skipped', $check->fresh()?->status);
        $this->assertSame(0, KeywordRankSnapshot::query()->count());
        $this->assertSame('collected', DB::table('dataforseo_tasks')->where('id', $taskId)->value('status'));
    }

    public function test_job_skips_when_the_primary_result_arrived_first(): void
    {
        Http::fake();
        $keyword = $this->keyword('voice generator', lastCheckedAt: now()->subMinute());
        $check = $this->check($keyword, null, now()->subHour());

        FetchFallbackRankJob::dispatchSync($check->id);

        Http::assertNothingSent();
        $this->assertSame('skipped', $check->fresh()?->status);
    }

    public function test_job_skips_when_the_workspace_allowance_is_used_up(): void
    {
        Http::fake();
        $this->workspace->update(['monthly_dfs_budget_usd' => 0.001]);
        $check = $this->check($this->keyword('voice generator'), null, now()->subHour());

        FetchFallbackRankJob::dispatchSync($check->id);

        Http::assertNothingSent();
        $check->refresh();
        $this->assertSame('skipped', $check->status);
        $this->assertStringContainsString('monthly live data budget', (string) $check->error_message);
    }

    public function test_failed_provider_request_marks_the_check_failed(): void
    {
        Http::fake(['api.serphouse.test/*' => Http::response(['status' => 'error', 'msg' => 'Insufficient credits'], 402)]);
        $check = $this->check($this->keyword('voice generator'), null, now()->subHour());

        try {
            FetchFallbackRankJob::dispatch($check->id);
            $this->fail('Expected the provider error to surface.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('out of credits', $e->getMessage());
        }

        $check->refresh();
        $this->assertSame('failed', $check->status);
        $this->assertSame(0, $check->pages_fetched);
    }

    public function test_late_primary_postback_does_not_overwrite_a_fallback_result(): void
    {
        $keyword = $this->keyword('voice generator');
        $token = Str::random(32);
        $externalId = (string) Str::uuid();
        $this->primaryTask($keyword, 'superseded', now()->subHours(8), $externalId, $token);

        $this->postJson(route('webhooks.dataforseo', ['token' => $token]), [
            'id' => $externalId,
            'tasks' => [['result' => [['items' => [['type' => 'organic', 'domain' => 'coqui.io', 'url' => 'https://coqui.io/', 'rank_group' => 4, 'rank_absolute' => 4]]]]]],
        ])->assertOk()->assertJsonPath('status', 'superseded');

        $this->assertSame(0, KeywordRankSnapshot::query()->count());
    }

    public function test_batch_submit_errors_leave_fallback_checks_but_spend_refusals_do_not(): void
    {
        config()->set('coqui.dataforseo.scheduled_spend', true);
        $keyword = $this->keyword('voice generator');

        Http::fake(['sandbox.dataforseo.com/*' => Http::response(['status_code' => 50000], 500)]);
        $this->artisan('dataforseo:run-rank-batches')->assertSuccessful();

        $check = RankFallbackCheck::query()->sole();
        $this->assertSame($keyword->id, $check->tracked_keyword_id);
        $this->assertSame(RankFallbackCheck::REASON_SUBMIT_ERROR, $check->reason);

        $check->delete();
        config()->set('coqui.dataforseo.scheduled_spend', false);
        $this->artisan('dataforseo:run-rank-batches')->assertSuccessful();

        $this->assertSame(0, RankFallbackCheck::query()->count());
    }

    public function test_running_checks_hold_their_worst_case_cost_against_the_allowance(): void
    {
        Http::fake();
        // Room for one worst-case check ($0.0075), not two.
        $this->workspace->update(['monthly_dfs_budget_usd' => 0.01]);

        $running = $this->check($this->keyword('first keyword'), null, now()->subHour());
        $running->update(['reserved_usd' => 0.0075]);
        $second = $this->check($this->keyword('second keyword'), null, now()->subHour());

        FetchFallbackRankJob::dispatchSync($second->id);

        Http::assertNothingSent();
        $this->assertSame('skipped', $second->fresh()?->status);
        $this->assertEqualsWithDelta(0.0075, $this->workspace->fresh()?->currentMonthDfsSpend(), 0.000001);
    }

    public function test_deleting_a_keyword_keeps_its_fallback_spend(): void
    {
        $keyword = $this->keyword('voice generator');
        $check = $this->check($keyword, null, now());
        app(RankFallbackSpendGuard::class)->recordCharge($check, 0.5);

        $keyword->delete();

        $this->assertNull($check->fresh()?->tracked_keyword_id);
        $this->assertEqualsWithDelta(0.5, $this->workspace->fresh()?->currentMonthDfsSpend(), 0.000001);
    }

    public function test_job_waits_on_the_rate_limiter_by_time_not_by_attempts(): void
    {
        $job = new FetchFallbackRankJob(1);

        $this->assertFalse(property_exists($job, 'tries'));
        $this->assertSame(3, $job->maxExceptions);
        $this->assertTrue($job->retryUntil() > now()->addHours(5));

        // A running check must not be handed to a second worker.
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $job->timeout);
        $this->assertContains(WithoutOverlapping::class, array_map(get_class(...), $job->middleware()));
    }

    public function test_spend_counts_in_the_period_it_was_paid_and_holds_count_in_every_period(): void
    {
        $guard = app(RankFallbackSpendGuard::class);
        $check = $this->check($this->keyword('voice generator'), null, now()->subDay());
        $check->update(['reserved_usd' => 0.0075]);

        // Created yesterday, paid today: the charge lands in today's total,
        // and the rest of the hold still counts.
        $guard->recordCharge($check, 0.00075);

        $this->assertEqualsWithDelta(0.0075, RankFallbackSpendGuard::spendSince(now()->startOfDay()), 0.000001);
        $this->assertEqualsWithDelta(0.00675, (float) $check->fresh()?->reserved_usd, 0.000001);
        $this->assertSame(1, $check->fresh()?->pages_fetched);
    }

    public function test_fallback_spend_counts_toward_the_workspace_allowance(): void
    {
        $check = $this->check($this->keyword('voice generator'), null, now());
        app(RankFallbackSpendGuard::class)->recordCharge($check, 1.25);

        $this->assertEqualsWithDelta(1.25, $this->workspace->fresh()?->currentMonthDfsSpend(), 0.000001);
        $this->assertEqualsWithDelta(23.75, $this->workspace->fresh()?->remainingDfsBudget(), 0.000001);
    }

    private function keyword(string $phrase, mixed $lastCheckedAt = null): TrackedKeyword
    {
        return TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => $phrase,
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
            'last_checked_at' => $lastCheckedAt,
        ]);
    }

    private function primaryTask(TrackedKeyword $keyword, string $status, mixed $submittedAt, ?string $externalId = null, ?string $token = null): int
    {
        return (int) DB::table('dataforseo_tasks')->insertGetId([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'external_task_id' => $externalId ?? (string) Str::uuid(),
            'endpoint' => '/v3/serp/google/organic/task_post',
            'task_type' => 'serp_rank_tracking',
            'status' => $status,
            'postback_token' => $token ?? Str::random(32),
            'request_context' => json_encode(['tracked_keyword_id' => $keyword->id], JSON_THROW_ON_ERROR),
            'submitted_at' => $submittedAt,
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ]);
    }

    private function check(TrackedKeyword $keyword, ?int $taskId, mixed $referenceAt): RankFallbackCheck
    {
        $id = DB::table('rank_fallback_checks')->insertGetId([
            'tracked_keyword_id' => $keyword->id,
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'dataforseo_task_id' => $taskId,
            'reason' => $taskId === null ? RankFallbackCheck::REASON_SUBMIT_ERROR : RankFallbackCheck::REASON_STALE,
            'status' => 'queued',
            'checked_on' => today()->toDateString(),
            'reference_at' => $referenceAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return RankFallbackCheck::query()->findOrFail($id);
    }

    /**
     * A live-search response in SERPHouse's status envelope.
     *
     * @param  list<string>  $hosts
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function page(array $hosts, array $extra = [], int $firstPosition = 1): array
    {
        $organic = [];
        foreach ($hosts as $i => $host) {
            $organic[] = [
                'position' => $firstPosition + $i,
                'title' => "Result on {$host}",
                'link' => "https://{$host}/page",
                'snippet' => '...',
            ];
        }

        return [
            'status' => 'success',
            'msg' => 'Completed',
            'results' => [
                'search_metadata' => ['status' => 'success'],
                'results' => ['organic' => $organic, ...$extra],
            ],
        ];
    }
}
