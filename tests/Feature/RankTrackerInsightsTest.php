<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Actions\RecordSerpRankSnapshots;
use App\Domains\Serp\Models\KeywordAiOverviewCitation;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\Services\KeywordResearchService;
use App\Domains\Serp\Services\RankTrendService;
use App\Domains\Serp\Services\SerpResultParser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RankTrackerInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const OVERVIEW_URL = 'https://sandbox.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live';

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'test-dfs-login');
        config()->set('coqui.dataforseo.password', 'test-dfs-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Insights',
            'slug' => 'insights',
            'owner_id' => $this->owner->id,
            'monthly_dfs_budget_usd' => 50.00,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui',
            'domain' => 'coqui.io',
            'canonical_url' => 'https://coqui.io/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
        ProjectCompetitor::query()->create([
            'project_id' => $this->project->id,
            'domain' => 'rival.com',
            'label' => 'Rival',
            'sort_order' => 1,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serpItems(): array
    {
        return [
            [
                'type' => 'ai_overview',
                'rank_group' => 1,
                'rank_absolute' => 1,
                'references' => [
                    ['domain' => 'www.rival.com', 'url' => 'https://www.rival.com/guide', 'title' => 'Rival guide'],
                    ['domain' => 'coqui.io', 'url' => 'https://coqui.io/blog/rank', 'title' => 'Coqui on ranking'],
                ],
                'items' => [
                    ['type' => 'ai_overview_element', 'references' => [
                        // Same URL again: counted once.
                        ['domain' => 'coqui.io', 'url' => 'https://coqui.io/blog/rank', 'title' => 'Coqui on ranking'],
                        ['url' => 'https://docs.example.org/page', 'source' => 'Example Docs'],
                    ]],
                ],
            ],
            ['type' => 'local_pack', 'rank_group' => 1, 'rank_absolute' => 2, 'title' => 'Coqui HQ', 'domain' => 'coqui.io', 'url' => 'https://coqui.io/', 'rating' => ['value' => 4.7]],
            ['type' => 'local_pack', 'rank_group' => 2, 'rank_absolute' => 3, 'title' => 'Other Shop'],
            ['type' => 'people_also_ask', 'rank_absolute' => 4, 'items' => [
                ['type' => 'people_also_ask_element', 'title' => 'What is rank tracking?'],
                ['type' => 'people_also_ask_element', 'title' => 'How often should I check rankings?'],
            ]],
            ['type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 5, 'domain' => 'rival.com', 'url' => 'https://rival.com/guide', 'title' => 'Rival guide'],
            ['type' => 'organic', 'rank_group' => 2, 'rank_absolute' => 6, 'domain' => 'coqui.io', 'url' => 'https://coqui.io/blog/rank', 'title' => 'Coqui on ranking'],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['items' => $this->serpItems()];
    }

    private function keyword(string $text = 'rank tracker'): TrackedKeyword
    {
        return TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => $text,
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
        ]);
    }

    public function test_the_parser_reads_ai_overview_citations_local_pack_and_people_also_ask(): void
    {
        $parsed = (new SerpResultParser)->parse($this->serpItems(), 'coqui.io', ['rival.com']);

        $this->assertTrue($parsed['ai_overview']['present']);
        $this->assertSame(1, $parsed['ai_overview']['position']);
        $this->assertSame(
            [[1, 'rival.com'], [2, 'coqui.io'], [3, 'docs.example.org']],
            array_map(fn (array $c): array => [$c['position'], $c['domain']], $parsed['ai_overview']['citations']),
        );
        $this->assertSame('Example Docs', $parsed['ai_overview']['citations'][2]['title']);

        $this->assertCount(2, $parsed['local_pack']);
        $this->assertSame('coqui.io', $parsed['local_pack'][0]['domain']);
        $this->assertSame(4.7, $parsed['local_pack'][0]['rating']);
        $this->assertNull($parsed['local_pack'][1]['domain']);

        $this->assertSame(['What is rank tracking?', 'How often should I check rankings?'], $parsed['people_also_ask']);

        $own = $parsed['domain_snapshots']['coqui.io']['serp_features'];
        $this->assertSame(2, $own['ai_overview_citation_position']);
        $this->assertTrue($own['local_pack_owned']);
        $this->assertSame(1, $parsed['domain_snapshots']['rival.com']['serp_features']['ai_overview_citation_position']);
        $this->assertFalse($parsed['domain_snapshots']['rival.com']['serp_features']['local_pack_owned']);
    }

    public function test_recording_a_check_stores_citations_and_replaces_them_on_a_recheck(): void
    {
        $keyword = $this->keyword();
        $recorder = app(RecordSerpRankSnapshots::class);

        $recorder->handle($keyword, $this->payload(), '2026-11-20');

        $this->assertSame(3, KeywordAiOverviewCitation::query()->where('tracked_keyword_id', $keyword->id)->count());
        $this->assertDatabaseHas('keyword_ai_overview_citations', [
            'tracked_keyword_id' => $keyword->id,
            'position' => 2,
            'domain' => 'coqui.io',
            'url' => 'https://coqui.io/blog/rank',
        ]);

        /** @var KeywordRankSnapshot $own */
        $own = KeywordRankSnapshot::query()->where('tracked_keyword_id', $keyword->id)->where('domain', 'coqui.io')->firstOrFail();
        $this->assertSame(1, $own->serp_features['ai_overview_position']);
        $this->assertSame(3, $own->serp_features['ai_overview_citation_count']);
        $this->assertCount(2, $own->serp_features['people_also_ask']);
        $this->assertCount(2, $own->serp_features['local_pack']);

        // A competitor snapshot doesn't repeat the page-level extras.
        $rival = KeywordRankSnapshot::query()->where('tracked_keyword_id', $keyword->id)->where('domain', 'rival.com')->firstOrFail();
        $this->assertArrayNotHasKey('people_also_ask', $rival->serp_features);

        // Re-checked the same day, now without an AI Overview. (SQLite keeps a
        // time on date casts, so updateOrCreate can't find the day's snapshot
        // rows here as Postgres does; clear them so only citations are tested.)
        KeywordRankSnapshot::query()->where('tracked_keyword_id', $keyword->id)->delete();
        $items = array_values(array_filter($this->serpItems(), fn (array $i): bool => $i['type'] !== 'ai_overview'));
        $recorder->handle($keyword, ['items' => $items], '2026-11-20');

        $this->assertSame(0, KeywordAiOverviewCitation::query()->where('tracked_keyword_id', $keyword->id)->count());
    }

    public function test_rank_checks_wait_for_async_ai_overviews_and_estimate_their_cost(): void
    {
        config()->set('coqui.dataforseo.scheduled_spend', true);
        config()->set('coqui.dataforseo.prices.serp_standard_task', 0.0006);
        config()->set('coqui.dataforseo.prices.serp_ai_overview_surcharge', 0.0006);
        $this->keyword();

        Http::fake([
            'https://sandbox.dataforseo.com/v3/serp/google/organic/task_post' => Http::response([
                'status_code' => 20000,
                'tasks' => [['id' => (string) Str::uuid(), 'status_code' => 20000]],
            ], 200),
        ]);

        $this->artisan('dataforseo:run-rank-batches', ['--project' => $this->project->id])->assertSuccessful();

        Http::assertSent(fn ($request): bool => ($request->data()[0]['load_async_ai_overview'] ?? null) === true);
        $this->assertEqualsWithDelta(0.0012, (float) DB::table('dataforseo_spend_ledger')->value('estimated_cost_usd'), 0.000001);
    }

    public function test_async_ai_overviews_can_be_turned_off(): void
    {
        config()->set('coqui.dataforseo.scheduled_spend', true);
        config()->set('coqui.dataforseo.load_async_ai_overview', false);
        config()->set('coqui.dataforseo.prices.serp_standard_task', 0.0006);
        $this->keyword();

        Http::fake([
            'https://sandbox.dataforseo.com/v3/serp/google/organic/task_post' => Http::response([
                'status_code' => 20000,
                'tasks' => [['id' => (string) Str::uuid(), 'status_code' => 20000]],
            ], 200),
        ]);

        $this->artisan('dataforseo:run-rank-batches', ['--project' => $this->project->id])->assertSuccessful();

        Http::assertSent(fn ($request): bool => ($request->data()[0]['load_async_ai_overview'] ?? null) === false);
        $this->assertEqualsWithDelta(0.0006, (float) DB::table('dataforseo_spend_ledger')->value('estimated_cost_usd'), 0.000001);
    }

    public function test_keywords_with_no_data_are_remembered_so_they_are_not_paid_for_again(): void
    {
        Http::fake([
            self::OVERVIEW_URL => Http::response([
                'status_code' => 20000,
                'tasks' => [[
                    'status_code' => 20000,
                    'result' => [['items' => [[
                        'keyword' => 'known keyword',
                        'keyword_info' => ['search_volume' => 900],
                    ]]]],
                ]],
            ], 200),
        ]);

        $service = app(KeywordResearchService::class);
        $first = $service->overview(['known keyword', 'obscure keyword'], $this->project);
        $this->assertSame(2, $first['summary']['fetched_count']);

        $second = $service->overview(['known keyword', 'obscure keyword'], $this->project);

        Http::assertSentCount(1);
        $this->assertSame(0, $second['summary']['fetched_count']);
        $obscure = collect($second['keywords'])->firstWhere('keyword', 'obscure keyword');
        $this->assertNull($obscure['search_volume']);
        $this->assertTrue($obscure['cached']);
    }

    public function test_a_failed_lookup_is_not_remembered_as_no_data(): void
    {
        Http::fake([
            self::OVERVIEW_URL => Http::response([
                'status_code' => 20000,
                'tasks' => [['status_code' => 40501, 'status_message' => 'Invalid field.', 'result' => null]],
            ], 200),
        ]);

        $service = app(KeywordResearchService::class);
        $service->overview(['obscure keyword'], $this->project);
        $service->overview(['obscure keyword'], $this->project);

        Http::assertSentCount(2);
    }

    public function test_daily_trend_carries_each_keyword_forward_and_measures_movement(): void
    {
        Carbon::setTestNow('2026-11-21 12:00:00');
        $weekly = $this->keyword('weekly keyword');
        $daily = $this->keyword('daily keyword');

        $snap = fn (TrackedKeyword $kw, string $date, ?int $rank, bool $aio = false) => KeywordRankSnapshot::query()->create([
            'tracked_keyword_id' => $kw->id,
            'project_id' => $this->project->id,
            'domain' => 'coqui.io',
            'rank_group' => $rank,
            'has_ai_overview' => $aio,
            'has_featured_snippet' => false,
            'serp_features' => ['ai_overview_cited' => $aio],
            'checked_on' => $date,
        ]);

        // Checked before the window: seeds the first day.
        $snap($weekly, '2026-11-10', 4);
        $snap($daily, '2026-11-19', 10);
        $snap($daily, '2026-11-20', 6, true);
        $snap($daily, '2026-11-21', null);

        $series = app(RankTrendService::class)->daily('coqui.io', [$weekly->id, $daily->id], 3);
        $byDate = collect($series)->keyBy('date');

        $this->assertSame(['2026-11-19', '2026-11-20', '2026-11-21'], $byDate->keys()->all());

        // Nov 19: weekly at 4 (carried), daily at 10 (first check, no movement).
        $this->assertSame(2, $byDate['2026-11-19']['tracked']);
        $this->assertSame(7.0, $byDate['2026-11-19']['avg_rank']);
        $this->assertNull($byDate['2026-11-19']['volatility']);
        $this->assertSame(27.5, $byDate['2026-11-19']['visibility']); // (35 + 20) / 200

        // Nov 20: daily moved 10 -> 6.
        $this->assertSame(4.0, $byDate['2026-11-20']['volatility']);
        $this->assertSame(1, $byDate['2026-11-20']['ai_overview']);
        $this->assertSame(1, $byDate['2026-11-20']['ai_overview_cited']);

        // Nov 21: daily dropped out of the top 100 (6 -> 101).
        $this->assertSame(95.0, $byDate['2026-11-21']['volatility']);
        $this->assertSame(1, $byDate['2026-11-21']['unranked']);
        $this->assertSame(1, $byDate['2026-11-21']['top_10']);

        Carbon::setTestNow();
    }

    public function test_the_keywords_page_and_history_include_trends_citations_and_serp_extras(): void
    {
        Carbon::setTestNow('2026-11-21 12:00:00');
        $keyword = $this->keyword();
        $recorder = app(RecordSerpRankSnapshots::class);
        $recorder->handle($keyword, $this->payload(), '2026-11-20');
        $recorder->handle($keyword, $this->payload(), '2026-11-21');

        $base = "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords";

        $this->actingAs($this->owner)
            ->get($base)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('trend', 30)
                ->where('trend.29.date', '2026-11-21')
                ->where('trend.29.tracked', 1)
                ->where('trend.29.top_3', 1));

        $this->actingAs($this->owner)
            ->getJson("{$base}/{$keyword->uuid}/snapshots")
            ->assertOk()
            ->assertJsonPath('volatility', 0)
            ->assertJsonPath('ai_overview.checked_on', '2026-11-21')
            ->assertJsonPath('ai_overview.position', 1)
            ->assertJsonPath('ai_overview.citations.1.domain', 'coqui.io')
            ->assertJsonPath('ai_overview.citations.1.is_project', true)
            ->assertJsonPath('ai_overview.citations.0.is_project', false)
            ->assertJsonPath('people_also_ask.0', 'What is rank tracking?')
            ->assertJsonPath('local_pack.0.title', 'Coqui HQ');

        Carbon::setTestNow();
    }

    public function test_history_shows_no_ai_overview_sources_when_the_latest_check_had_none(): void
    {
        Carbon::setTestNow('2026-11-21 12:00:00');
        $keyword = $this->keyword();
        $recorder = app(RecordSerpRankSnapshots::class);
        $recorder->handle($keyword, $this->payload(), '2026-11-20');
        $items = array_values(array_filter($this->serpItems(), fn (array $i): bool => $i['type'] !== 'ai_overview'));
        $recorder->handle($keyword, ['items' => $items], '2026-11-21');

        $this->actingAs($this->owner)
            ->getJson("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/{$keyword->uuid}/snapshots")
            ->assertOk()
            ->assertJsonPath('ai_overview.checked_on', null)
            ->assertJsonPath('ai_overview.position', null)
            ->assertJsonPath('ai_overview.citations', []);

        Carbon::setTestNow();
    }
}
