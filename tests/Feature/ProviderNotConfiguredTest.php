<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\Models\ContentBrief;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Domains\Serp\Services\SeoProviderRouter;
use App\Mcp\Servers\CoquiSeoServer;
use App\Mcp\Tools\LookupKeywordMetricsTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * With no SEO data provider credentials (the .env.example default), provider
 * pages explain why there's no data, actions say so instead of erroring, and
 * scheduled work skips quietly.
 */
class ProviderNotConfiguredTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', '');
        config()->set('coqui.dataforseo.password', '');
        config()->set('coqui.seranking.api_key', '');
        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => 'No Providers',
            'slug' => 'no-providers',
            'owner_id' => $this->user->id,
            'monthly_dfs_budget_usd' => 50.00,
        ]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui',
            'domain' => 'coqui.io',
            'canonical_url' => 'https://coqui.io/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providerPages(): array
    {
        return [
            'rank tracker' => ['keywords', 'Keywords/Index'],
            'keyword explorer' => ['keyword-explorer', 'Keywords/Explorer'],
            'keyword gap' => ['keyword-gap', 'Keywords/Gap'],
            'content briefs' => ['keywords/brief', 'Keywords/ContentBrief'],
            'backlinks' => ['backlinks', 'Backlinks/Index'],
            'link intersect' => ['backlinks/intersect', 'Backlinks/Intersect'],
            'backlink watchlist' => ['backlinks/watchlist', 'Backlinks/Watchlist'],
        ];
    }

    #[DataProvider('providerPages')]
    public function test_provider_pages_load_and_say_no_provider_is_set_up(string $path, string $component): void
    {
        $this->actingAs($this->user)
            ->get($this->url($path))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->where('dataProviders.seoData', false)
            );
    }

    public function test_pages_report_the_provider_as_set_up_once_credentials_exist(): void
    {
        config()->set('coqui.dataforseo.login', 'login');
        config()->set('coqui.dataforseo.password', 'password');

        $this->actingAs($this->user)
            ->get($this->url('keyword-explorer'))
            ->assertInertia(fn (Assert $page) => $page->where('dataProviders.seoData', true));
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function jsonLookups(): array
    {
        return [
            'explorer overview' => ['keyword-explorer/overview', ['keywords' => ['seo crawler']]],
            'explorer related' => ['keyword-explorer/related', ['keyword' => 'seo crawler']],
            'keyword gap' => ['keyword-gap/search', ['competitors' => ['example.com']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('jsonLookups')]
    public function test_lookups_explain_that_no_provider_is_set_up(string $path, array $payload): void
    {
        $this->actingAs($this->user)
            ->postJson($this->url($path), $payload)
            ->assertStatus(503)
            ->assertJsonPath('provider_not_configured', true)
            ->assertJsonPath('message', ProviderNotConfiguredException::USER_MESSAGE);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function formActions(): array
    {
        return [
            'backlink refresh' => ['backlinks/refresh', []],
            'link intersect refresh' => ['backlinks/intersect/refresh', ['competitors' => 'example.com']],
            'watchlist import' => ['backlinks/watchlist/import-dfs', []],
            'content brief' => ['keywords/brief', ['keyword' => 'seo crawler']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('formActions')]
    public function test_actions_refuse_instead_of_reporting_success_with_no_data(string $path, array $payload): void
    {
        $this->actingAs($this->user)
            ->from($this->url('keywords'))
            ->post($this->url($path), $payload)
            ->assertRedirect($this->url('keywords'))
            ->assertSessionHas('error', ProviderNotConfiguredException::USER_MESSAGE);

        $this->assertSame(0, ContentBrief::query()->count());
        $this->assertDatabaseMissing('project_activities', ['project_id' => $this->project->id]);
    }

    public function test_scheduled_rank_commands_skip_without_credentials(): void
    {
        TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'seo crawler',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
        ]);

        $this->artisan('dataforseo:run-rank-batches', ['--force' => true])
            ->expectsOutputToContain('credentials are not configured')
            ->assertSuccessful();
        $this->artisan('dataforseo:collect-tasks')
            ->expectsOutputToContain('credentials are not configured')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_router_uses_the_one_provider_that_is_configured(): void
    {
        config()->set('coqui.seranking.api_key', 'seranking-key');
        config()->set('coqui.seranking.base_url', 'https://api.seranking.com');
        Http::fake([
            'api.seranking.com/v1/keywords/export*' => Http::response([
                ['keyword' => 'seo crawler', 'volume' => 1900, 'is_data_found' => true],
            ], 200),
        ]);

        $result = app(SeoProviderRouter::class)->lookupKeywords(['seo crawler', '2026']);

        // '2026' would go to the other provider, which isn't set up: skipped
        // and reported as unavailable rather than as having no data.
        $this->assertSame('seo crawler', $result['providers']['seranking'][0]['keyword']);
        $this->assertSame([], $result['providers']['dataforseo']);
        $this->assertSame(['2026'], $result['unavailable']);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'api.seranking.com'));
        Http::assertSentCount(1);
    }

    public function test_cached_keyword_data_is_still_served_without_credentials(): void
    {
        app(DataForSeoCacheRepository::class)->put(
            '/v3/dataforseo_labs/google/keyword_overview/live',
            ['keywords' => ['seo crawler'], 'location_code' => 2840, 'language_code' => 'en'],
            ['tasks' => [['result' => [['items' => [['keyword' => 'seo crawler']]]]]]],
            24,
        );

        $result = app(SeoProviderRouter::class)->lookupKeywords(['seo crawler']);

        $this->assertSame('seo crawler', $result['providers']['dataforseo']['tasks'][0]['result'][0]['items'][0]['keyword']);
        $this->assertSame([], $result['unavailable']);
        Http::assertNothingSent();
    }

    public function test_the_mcp_tool_lists_keywords_no_provider_could_look_up_as_unavailable(): void
    {
        config()->set('coqui.seranking.api_key', 'seranking-key');
        config()->set('coqui.seranking.base_url', 'https://api.seranking.com');
        Http::fake([
            'api.seranking.com/v1/keywords/export*' => Http::response([
                ['keyword' => 'seo crawler', 'volume' => 1900, 'is_data_found' => true],
            ], 200),
        ]);

        CoquiSeoServer::actingAs($this->user)
            ->tool(LookupKeywordMetricsTool::class, ['keywords' => 'seo crawler, 2026', 'execute' => true, 'domain' => 'coqui.io'])
            ->assertOk()
            ->assertSee('"not_found":[]')
            ->assertSee('"unavailable":["2026"]');
    }

    public function test_the_router_refuses_when_no_provider_is_configured(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);

        app(SeoProviderRouter::class)->lookupKeywords(['seo crawler']);
    }

    public function test_the_mcp_keyword_tool_reports_that_no_provider_is_set_up(): void
    {
        CoquiSeoServer::actingAs($this->user)
            ->tool(LookupKeywordMetricsTool::class, ['keywords' => 'seo crawler', 'execute' => true, 'domain' => 'coqui.io'])
            ->assertHasErrors()
            ->assertSee('isn\'t set up on this server');
    }

    private function url(string $path): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/{$path}";
    }
}
