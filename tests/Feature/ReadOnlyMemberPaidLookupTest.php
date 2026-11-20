<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\DataForSeo\DataForSeoReadOnlyException;
use App\Domains\Serp\DataForSeo\DataForSeoSpendGuard;
use App\Domains\Serp\ReadOnlySpendScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Read-only workspace members (viewers) may read cached SEO data but must never
 * trigger new paid DataForSEO / SE Ranking lookups billed to the workspace (#67).
 */
class ReadOnlyMemberPaidLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private User $editor;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'test-login');
        config()->set('coqui.dataforseo.password', 'test-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');

        $this->owner = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->editor = User::factory()->create();

        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => 'SEO Agency',
            'slug' => 'seo-agency',
            'owner_id' => $this->owner->id,
            'monthly_dfs_budget_usd' => 100.00,
        ]);
        $this->workspace->members()->attach($this->viewer->id, ['role' => 'viewer']);
        $this->workspace->members()->attach($this->editor->id, ['role' => 'editor']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui Analytics',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    private function url(string $path): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/{$path}";
    }

    private function cacheKeyword(string $keyword): void
    {
        app(DataForSeoCacheRepository::class)->putKeywordMetrics(
            keyword: $keyword,
            metrics: [
                'keyword' => $keyword,
                'location_code' => 2840,
                'language_code' => 'en',
                'search_volume' => 1200,
                'cpc' => 1.5,
                'competition' => 0.4,
                'competition_level' => 'MEDIUM',
                'keyword_difficulty' => 30,
                'main_intent' => 'commercial',
                'secondary_intents' => [],
                'monthly_searches' => [],
            ],
            locationCode: 2840,
            languageCode: 'en',
        );
    }

    public function test_viewer_keyword_overview_cache_miss_is_refused_without_a_paid_call(): void
    {
        Http::fake();

        $this->actingAs($this->viewer)
            ->postJson($this->url('keyword-explorer/overview'), [
                'keywords' => ['uncached keyword'],
                'location_code' => 2840,
                'language_code' => 'en',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'read_only');

        Http::assertNothingSent();
    }

    public function test_viewer_keyword_overview_serves_cached_data_even_with_bypass_cache(): void
    {
        Http::fake();
        $this->cacheKeyword('cached keyword');

        $this->actingAs($this->viewer)
            ->postJson($this->url('keyword-explorer/overview'), [
                'keywords' => ['cached keyword'],
                'location_code' => 2840,
                'language_code' => 'en',
                'bypass_cache' => true,
            ])
            ->assertOk()
            ->assertJsonPath('summary.cached_count', 1)
            ->assertJsonPath('summary.fetched_count', 0);

        Http::assertNothingSent();
    }

    public function test_viewer_related_keywords_cache_miss_is_refused_without_a_paid_call(): void
    {
        Http::fake();

        $this->actingAs($this->viewer)
            ->postJson($this->url('keyword-explorer/related'), [
                'keyword' => 'best seo tool',
                'location_code' => 2840,
                'language_code' => 'en',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'read_only');

        Http::assertNothingSent();
    }

    public function test_viewer_keyword_gap_cache_miss_is_refused_without_a_paid_call(): void
    {
        Http::fake();

        $this->actingAs($this->viewer)
            ->postJson($this->url('keyword-gap/search'), [
                'competitors' => ['ahrefs.com'],
                'location_code' => 2840,
                'language_code' => 'en',
                'gap_mode' => 'all',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'read_only');

        Http::assertNothingSent();
    }

    public function test_viewer_backlink_intersect_page_never_triggers_a_paid_call(): void
    {
        Http::fake();
        ProjectCompetitor::query()->create(['project_id' => $this->project->id, 'domain' => 'competitor-a.com']);
        ProjectCompetitor::query()->create(['project_id' => $this->project->id, 'domain' => 'competitor-b.com']);

        $this->actingAs($this->viewer)
            ->get($this->url('backlinks/intersect').'?competitors[]=attacker-chosen-1.com&competitors[]=attacker-chosen-2.com')
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_content_brief_get_never_generates_a_brief(): void
    {
        Http::fake();

        $this->actingAs($this->viewer)
            ->get($this->url('keywords/brief').'?keyword=technical+seo+audit&generate=1')
            ->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseMissing('content_briefs', ['project_id' => $this->project->id]);
    }

    public function test_editor_keyword_overview_cache_miss_still_calls_the_provider(): void
    {
        Http::fake();

        $this->actingAs($this->editor)
            ->postJson($this->url('keyword-explorer/overview'), [
                'keywords' => ['uncached keyword'],
                'location_code' => 2840,
                'language_code' => 'en',
            ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'keyword_overview'));
    }

    public function test_read_only_scope_blocks_the_spend_guard_and_resets_afterwards(): void
    {
        $guard = app(DataForSeoSpendGuard::class);

        try {
            ReadOnlySpendScope::run(fn () => $guard->assertCanSpend(0.01, $this->workspace));
            $this->fail('Expected the read-only scope to refuse new spend.');
        } catch (DataForSeoReadOnlyException) {
            // expected
        }

        $this->assertFalse(ReadOnlySpendScope::active());
        $guard->assertCanSpend(0.01, $this->workspace);
    }
}
