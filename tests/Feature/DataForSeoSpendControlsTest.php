<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoBudgetException;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\DataForSeo\DataForSeoSpendGuard;
use App\Models\User;
use App\Notifications\DataForSeoSpendAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DataForSeoSpendControlsTest extends TestCase
{
    use RefreshDatabase;

    private const OVERVIEW = 'api.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Production base URL: sandbox spend isn't counted against caps.
        config()->set('coqui.dataforseo.login', 'test-login');
        config()->set('coqui.dataforseo.password', 'test-password');
        config()->set('coqui.dataforseo.base_url', 'https://api.dataforseo.com');
        config()->set('coqui.dataforseo.daily_cap_usd', 5.00);
        config()->set('coqui.dataforseo.monthly_cap_usd', 100.00);

        $this->user = User::factory()->create();
    }

    private function project(string $name, float $budget = 100.00): Project
    {
        $workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'owner_id' => $this->user->id,
            'monthly_dfs_budget_usd' => $budget,
        ]);

        return Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'domain' => str($name)->slug()->toString().'.com',
            'canonical_url' => 'https://'.str($name)->slug()->toString().'.com/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewResponse(float $cost = 0.0125): array
    {
        return [
            'status_code' => 20000,
            'cost' => $cost,
            'tasks' => [[
                'id' => 'task-1',
                'status_code' => 20000,
                'result' => [['items' => [['keyword' => 'seo crawler', 'keyword_info' => ['search_volume' => 1900]]]]],
            ]],
        ];
    }

    private function seedSpend(float $usd, ?int $workspaceId = null, ?\DateTimeInterface $at = null): void
    {
        $at ??= now();
        DB::table('dataforseo_spend_ledger')->insert([
            'workspace_id' => $workspaceId,
            'endpoint' => '/v3/serp/google/organic/task_post',
            'mode' => 'standard',
            'estimated_cost_usd' => $usd,
            'actual_cost_usd' => $usd,
            'status' => 'settled',
            'is_sandbox' => false,
            'recorded_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_a_paid_call_reserves_its_estimate_then_settles_the_actual_cost(): void
    {
        $project = $this->project('Agency One');
        Http::fake([self::OVERVIEW => Http::response($this->overviewResponse(0.0125), 200)]);

        app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $project);

        $row = DB::table('dataforseo_spend_ledger')->sole();
        $this->assertSame('settled', $row->status);
        $this->assertEqualsWithDelta(0.0125, (float) $row->actual_cost_usd, 0.000001);
        $this->assertEqualsWithDelta(0.01212, (float) $row->estimated_cost_usd, 0.000001);
        $this->assertSame('task-1', $row->external_task_id);
        $this->assertSame($project->workspace_id, (int) $row->workspace_id);

        $archived = DB::table('dataforseo_responses')->sole();
        $this->assertSame((int) $row->id, (int) $archived->ledger_id);
        $this->assertSame(200, (int) $archived->http_status);
        $this->assertSame(['seo crawler'], json_decode((string) $archived->request_payload, true)[0]['keywords']);
        $this->assertSame('task-1', json_decode((string) $archived->response_payload, true)['tasks'][0]['id']);
    }

    public function test_a_timed_out_call_keeps_its_estimate_in_the_ledger(): void
    {
        $project = $this->project('Agency One');
        Http::fake([self::OVERVIEW => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        try {
            app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $project);
            $this->fail('The timeout should surface.');
        } catch (ConnectionException) {
            // expected
        }

        $row = DB::table('dataforseo_spend_ledger')->sole();
        $this->assertSame('unknown', $row->status);
        $this->assertEqualsWithDelta(0.01212, (float) $row->actual_cost_usd, 0.000001);
        $this->assertStringContainsString('timed out', (string) DB::table('dataforseo_responses')->value('error'));
    }

    public function test_an_unsettled_reservation_counts_against_the_cap(): void
    {
        $guard = app(DataForSeoSpendGuard::class);

        // Two jobs reserve before either response is back.
        $guard->reserve('/v3/serp/google/organic/live/advanced', 'live', 3.00);

        $this->expectException(DataForSeoBudgetException::class);
        $guard->reserve('/v3/serp/google/organic/live/advanced', 'live', 3.00);
    }

    public function test_a_call_is_refused_while_another_holds_the_spend_lock(): void
    {
        config()->set('coqui.dataforseo.spend_lock_wait_seconds', 0);
        $project = $this->project('Agency One');
        Http::fake();

        $lock = Cache::lock('dataforseo:spend-guard', 30);
        $this->assertTrue($lock->get());

        try {
            app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $project);
            $this->fail('The call should wait for the lock and then be refused.');
        } catch (DataForSeoBudgetException) {
            // expected
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('dataforseo_spend_ledger')->count());
    }

    public function test_the_monthly_cap_counts_earlier_days(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10));
        $this->seedSpend(99.995, at: now()->subDays(5));
        $project = $this->project('Agency One');
        Http::fake();

        try {
            app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $project);
            $this->fail('The monthly cap should refuse the call.');
        } catch (DataForSeoBudgetException $e) {
            $this->assertStringContainsString('Monthly', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_workspace_allowance_is_per_workspace(): void
    {
        $tight = $this->project('Tight Budget', budget: 1.00);
        $roomy = $this->project('Roomy Budget', budget: 50.00);
        $this->seedSpend(0.995, workspaceId: $tight->workspace_id);
        Http::fake([self::OVERVIEW => Http::response($this->overviewResponse(), 200)]);

        try {
            app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $tight);
            $this->fail('The tight workspace is over its allowance.');
        } catch (DataForSeoBudgetException $e) {
            $this->assertStringContainsString('Workspace', $e->getMessage());
        }

        // Another workspace's spend doesn't count against this one.
        app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $roomy);
        Http::assertSentCount(1);
    }

    public function test_two_projects_asking_for_the_same_data_make_one_call(): void
    {
        $first = $this->project('Agency One');
        $second = $this->project('Agency Two');
        Http::fake([self::OVERVIEW => Http::response($this->overviewResponse(), 200)]);

        $a = app(DataForSeoClient::class)->keywordOverview(['SEO Crawler'], $first);
        $b = app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $second);

        Http::assertSentCount(1);
        $this->assertSame($a, $b);
        $this->assertSame(1, DB::table('dataforseo_spend_ledger')->count());

        $stats = DB::table('dataforseo_cache_stats')->where('endpoint', '/v3/dataforseo_labs/google/keyword_overview/live')->sole();
        $this->assertSame(1, (int) $stats->hits);
        $this->assertSame(1, (int) $stats->misses);
    }

    public function test_serp_snapshots_are_cached_and_a_fresh_refresh_skips_the_cache(): void
    {
        $first = $this->project('Agency One');
        $second = $this->project('Agency Two');
        Http::fake(['api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response($this->overviewResponse(0.002), 200)]);

        $client = app(DataForSeoClient::class);
        $client->serpLive('seo crawler', $first);
        $client->serpLive('seo crawler', $second);
        Http::assertSentCount(1);

        $client->serpLive('seo crawler', $second, bypassCache: true);
        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('dataforseo_spend_ledger')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(float $balance, float $total): array
    {
        return [
            'status_code' => 20000,
            'tasks' => [['result' => [['login' => 'test-login', 'money' => ['balance' => $balance, 'total' => $total]]]]],
        ];
    }

    public function test_reconciliation_measures_drift_and_alerts_operators_once_a_day(): void
    {
        Notification::fake();
        $operator = User::factory()->create(['is_operator' => true]);
        config()->set('coqui.dataforseo.alerts.drift_usd', 1.00);
        config()->set('coqui.dataforseo.alerts.low_balance_usd', 5.00);

        Http::fake(['api.dataforseo.com/v3/appendix/user_data' => Http::sequence()
            ->push($this->userData(balance: 40.00, total: 50.00), 200)
            // A $5 top-up and $3.50 of charges, of which the ledger saw $1.50.
            ->push($this->userData(balance: 41.50, total: 55.00), 200)
            ->push($this->userData(balance: 41.50, total: 55.00), 200),
        ]);

        $this->artisan('dataforseo:reconcile')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travel(1)->hours();
        $this->seedSpend(1.50);
        $this->travel(1)->minutes();

        $this->artisan('dataforseo:reconcile')->assertSuccessful();

        $snapshot = DB::table('dataforseo_balance_snapshots')->orderByDesc('id')->first();
        $this->assertEqualsWithDelta(3.50, (float) $snapshot->interval_vendor_usd, 0.0001);
        $this->assertEqualsWithDelta(1.50, (float) $snapshot->interval_ledger_usd, 0.0001);
        $this->assertEqualsWithDelta(2.00, (float) $snapshot->drift_usd, 0.0001);
        Notification::assertSentTo($operator, DataForSeoSpendAlert::class, fn (DataForSeoSpendAlert $alert): bool => $alert->type === 'drift');
        Notification::assertSentToTimes($operator, DataForSeoSpendAlert::class, 1);

        // The same day: no second email (the drift is logged, not re-sent).
        DB::table('dataforseo_balance_snapshots')->orderByDesc('id')->limit(1)->update(['vendor_spent_usd' => 11.50]);
        $this->artisan('dataforseo:reconcile')->assertSuccessful();
        Notification::assertSentToTimes($operator, DataForSeoSpendAlert::class, 1);
    }

    public function test_a_reservation_settled_after_a_snapshot_counts_in_the_next_interval(): void
    {
        Notification::fake();
        User::factory()->create(['is_operator' => true]);
        config()->set('coqui.dataforseo.alerts.drift_usd', 0.01);
        config()->set('coqui.dataforseo.alerts.low_balance_usd', 0.0);

        Http::fake(['api.dataforseo.com/v3/appendix/user_data' => Http::sequence()
            ->push($this->userData(balance: 40.00, total: 50.00), 200)
            // The $0.50 request is billed only after this snapshot.
            ->push($this->userData(balance: 40.00, total: 50.00), 200)
            ->push($this->userData(balance: 39.50, total: 50.00), 200),
        ]);

        $this->artisan('dataforseo:reconcile')->assertSuccessful();

        // Reserved while that first snapshot was the latest...
        $guard = app(DataForSeoSpendGuard::class);
        $this->travel(1)->minutes();
        $ledgerId = $guard->reserve('/v3/serp/google/organic/task_post', 'standard', 0.40);

        // ...a snapshot lands while it is still open...
        $this->travel(1)->minutes();
        $this->artisan('dataforseo:reconcile')->assertSuccessful();
        $this->assertEqualsWithDelta(0.0, (float) DB::table('dataforseo_balance_snapshots')->orderByDesc('id')->value('interval_ledger_usd'), 0.0001);

        // ...and it settles, at a different cost, before the next one.
        $this->travel(1)->minutes();
        $guard->settle($ledgerId, DataForSeoSpendGuard::STATUS_SETTLED, 0.50, 200);
        $this->travel(1)->minutes();
        $this->artisan('dataforseo:reconcile')->assertSuccessful();

        $snapshot = DB::table('dataforseo_balance_snapshots')->orderByDesc('id')->first();
        $this->assertEqualsWithDelta(0.50, (float) $snapshot->interval_vendor_usd, 0.0001);
        $this->assertEqualsWithDelta(0.50, (float) $snapshot->interval_ledger_usd, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $snapshot->drift_usd, 0.0001);
        Notification::assertNothingSent();
    }

    public function test_reconciliation_alerts_on_low_balance_and_spend_near_a_cap(): void
    {
        Notification::fake();
        $operator = User::factory()->create(['is_operator' => true]);
        config()->set('coqui.dataforseo.alerts.low_balance_usd', 10.00);
        config()->set('coqui.dataforseo.alerts.cap_ratio', 0.8);
        $this->seedSpend(4.20);

        Http::fake(['api.dataforseo.com/v3/appendix/user_data' => Http::response($this->userData(balance: 6.00, total: 50.00), 200)]);

        $this->artisan('dataforseo:reconcile')->assertSuccessful();

        $types = [];
        Notification::assertSentTo($operator, DataForSeoSpendAlert::class, function (DataForSeoSpendAlert $alert) use (&$types): bool {
            $types[] = $alert->type;

            return true;
        });
        sort($types);
        $this->assertSame(['daily_cap', 'low_balance'], $types);
    }

    public function test_stale_reservations_are_marked_unknown_and_sandbox_skips_the_balance(): void
    {
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');
        app(DataForSeoSpendGuard::class)->reserve('/v3/serp/google/organic/live/advanced', 'live', 0.002);
        $this->travel(2)->hours();
        Http::fake();

        $this->artisan('dataforseo:reconcile')
            ->expectsOutputToContain('never settled')
            ->expectsOutputToContain('Skipped: sandbox')
            ->assertSuccessful();

        $this->assertSame('unknown', DB::table('dataforseo_spend_ledger')->value('status'));
        Http::assertNothingSent();
    }

    public function test_status_command_reports_caps_and_cache_rates_and_prunes_the_archive(): void
    {
        $project = $this->project('Agency One');
        Http::fake([self::OVERVIEW => Http::response($this->overviewResponse(), 200)]);
        app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $project);
        app(DataForSeoClient::class)->keywordOverview(['seo crawler'], $project);

        // One output line satisfies one expectation; the endpoint and rate share a row.
        $this->artisan('dataforseo:status')
            ->expectsOutputToContain('This month')
            ->expectsOutputToContain('50%')
            ->assertSuccessful();

        config()->set('coqui.dataforseo.archive_retention_days', 30);
        $this->travel(31)->days();
        $this->artisan('dataforseo:status --prune')->expectsOutputToContain('Pruned 1')->assertSuccessful();
        $this->assertSame(0, DB::table('dataforseo_responses')->count());
    }
}
