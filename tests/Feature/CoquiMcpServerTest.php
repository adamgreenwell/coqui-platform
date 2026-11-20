<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Mcp\Servers\CoquiSeoServer;
use App\Mcp\Tools\InspectProjectSeoTool;
use App\Mcp\Tools\ListCrawlIssuesTool;
use App\Mcp\Tools\LookupKeywordMetricsTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoquiMcpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_mcp_endpoint_lists_coqui_tools(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->postJson('/mcp/coqui', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'inspect_project_seo'])
            ->assertJsonFragment(['name' => 'lookup_keyword_metrics'])
            ->assertJsonFragment(['name' => 'list_crawl_issues']);
    }

    public function test_inspect_project_seo_and_lookup_keyword_metrics_tools_work(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Coqui SEO Workspace',
            'slug' => 'coqui-seo',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Coqui SEO',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
        ]);
        CrawlRun::query()->create([
            'project_id' => $project->id,
            'external_crawl_id' => (string) Str::uuid(),
            'source' => 'desktop',
            'seed' => 'https://coquiseo.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => ['maxDepth' => 3],
            'total_urls' => 42,
            'error_count' => 1,
            'warning_count' => 3,
            'opportunity_count' => 2,
        ]);

        CoquiSeoServer::actingAs($user)->tool(InspectProjectSeoTool::class, [
            'domain' => 'https://www.coquiseo.com/',
        ])
            ->assertOk()
            ->assertSee('coquiseo.com')
            ->assertSee('"totalUrls":42');

        CoquiSeoServer::tool(LookupKeywordMetricsTool::class, [
            'keywords' => 'seo crawler, 2026, 180 e-cut™ hybrid',
        ])
            ->assertOk()
            ->assertSee('dry_run')
            ->assertSee('seo crawler')
            ->assertDontSee('dataforseo')
            ->assertDontSee('seranking');
    }

    public function test_project_tools_enforce_workspace_authorization(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $workspace = Workspace::query()->create([
            'name' => 'Private Workspace',
            'slug' => 'private-workspace',
            'owner_id' => $owner->id,
        ]);
        Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Secret Project',
            'domain' => 'secret-project.com',
            'canonical_url' => 'https://secret-project.com/',
        ]);

        // Unauthorized user cannot inspect
        CoquiSeoServer::actingAs($stranger)
            ->tool(InspectProjectSeoTool::class, ['domain' => 'secret-project.com'])
            ->assertSee('Project not found for domain [secret-project.com].');

        // Owner can inspect
        CoquiSeoServer::actingAs($owner)
            ->tool(InspectProjectSeoTool::class, ['domain' => 'secret-project.com'])
            ->assertOk()
            ->assertSee('secret-project.com');
    }

    public function test_tools_require_authentication_when_configured(): void
    {
        config()->set('coqui.mcp.require_auth', true);

        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Protected Workspace',
            'slug' => 'protected-workspace',
            'owner_id' => $user->id,
        ]);
        Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Protected Project',
            'domain' => 'protected.com',
            'canonical_url' => 'https://protected.com/',
        ]);

        // Anonymous request rejected
        CoquiSeoServer::tool(InspectProjectSeoTool::class, ['domain' => 'protected.com'])
            ->assertSee('Authentication required to inspect project SEO.');

        // Authenticated request allowed
        CoquiSeoServer::actingAs($user)
            ->tool(InspectProjectSeoTool::class, ['domain' => 'protected.com'])
            ->assertOk()
            ->assertSee('protected.com');
    }

    private function makeProject(User $owner, string $domain): Project
    {
        $workspace = Workspace::query()->create([
            'name' => 'Workspace '.$domain,
            'slug' => Str::slug($domain).'-'.Str::random(4),
            'owner_id' => $owner->id,
            'monthly_dfs_budget_usd' => 50.00,
        ]);

        return Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Project '.$domain,
            'domain' => $domain,
            'canonical_url' => "https://{$domain}/",
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    private function listTools(): TestResponse
    {
        return $this->postJson('/mcp/coqui', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    }

    public function test_mcp_endpoint_rejects_anonymous_callers(): void
    {
        $this->listTools()->assertUnauthorized();
    }

    public function test_mcp_endpoint_rejects_tokens_without_the_mcp_ability(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['crawls:sync', 'projects:read']);

        $this->listTools()->assertForbidden();
    }

    public function test_project_tools_refuse_anonymous_calls_even_when_route_auth_is_off(): void
    {
        config()->set('coqui.mcp.require_auth', false);
        $this->makeProject(User::factory()->create(), 'open-site.com');

        CoquiSeoServer::tool(InspectProjectSeoTool::class, ['domain' => 'open-site.com'])
            ->assertSee('Authentication required to inspect project SEO.')
            ->assertDontSee('"totalUrls"');

        CoquiSeoServer::tool(ListCrawlIssuesTool::class, ['domain' => 'open-site.com'])
            ->assertSee('Authentication required to list crawl issues.');
    }

    public function test_project_tools_resolve_the_callers_project_when_domains_collide(): void
    {
        $stranger = User::factory()->create();
        $owner = User::factory()->create();
        $this->makeProject($stranger, 'shared-domain.com');
        $mine = $this->makeProject($owner, 'shared-domain.com');

        CoquiSeoServer::actingAs($owner)
            ->tool(InspectProjectSeoTool::class, ['domain' => 'shared-domain.com'])
            ->assertOk()
            ->assertSee('"id":"'.$mine->uuid.'"');
    }

    public function test_live_keyword_lookup_requires_a_user_and_a_billable_project(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $this->makeProject($owner, 'billable.com');

        CoquiSeoServer::tool(LookupKeywordMetricsTool::class, ['keywords' => 'seo crawler', 'execute' => true])
            ->assertSee('Authentication required for live keyword lookups.');

        CoquiSeoServer::actingAs($owner)
            ->tool(LookupKeywordMetricsTool::class, ['keywords' => 'seo crawler', 'execute' => true])
            ->assertSee('Live keyword lookups require the domain of a project to bill.');

        CoquiSeoServer::actingAs(User::factory()->create())
            ->tool(LookupKeywordMetricsTool::class, ['keywords' => 'seo crawler', 'execute' => true, 'domain' => 'billable.com'])
            ->assertSee('Project not found for domain [billable.com].');

        Http::assertNothingSent();
        $this->assertDatabaseCount('dataforseo_spend_ledger', 0);
    }

    public function test_live_keyword_lookup_is_billed_to_the_project_workspace(): void
    {
        config()->set('coqui.dataforseo.login', 'test-login');
        config()->set('coqui.dataforseo.password', 'test-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');
        config()->set('coqui.seranking.api_key', null);

        Http::fake([
            'https://sandbox.dataforseo.com/*' => Http::response([
                'status_code' => 20000,
                'cost' => 0.0121,
                'tasks' => [['status_code' => 20000, 'cost' => 0.0121, 'result' => [['items' => [[
                    'keyword' => 'seo crawler',
                    'keyword_info' => ['search_volume' => 1900, 'cpc' => 2.5, 'competition' => 0.4],
                    'keyword_properties' => ['keyword_difficulty' => 41],
                ]]]]]],
            ], 200),
        ]);

        $owner = User::factory()->create();
        $project = $this->makeProject($owner, 'billable.com');

        CoquiSeoServer::actingAs($owner)
            ->tool(LookupKeywordMetricsTool::class, ['keywords' => 'seo crawler, unknown phrase', 'execute' => true, 'domain' => 'billable.com'])
            ->assertOk()
            ->assertSee('live')
            ->assertSee('"search_volume":1900')
            ->assertSee('"keyword_difficulty":41')
            ->assertSee('"not_found":["unknown phrase"]')
            // Metrics come back in one neutral shape, not a provider's raw payload.
            ->assertDontSee('"tasks"')
            ->assertDontSee('"cost"')
            ->assertDontSee('dataforseo')
            ->assertDontSee('seranking');

        $this->assertDatabaseHas('dataforseo_spend_ledger', ['workspace_id' => $project->workspace_id]);
    }
}
