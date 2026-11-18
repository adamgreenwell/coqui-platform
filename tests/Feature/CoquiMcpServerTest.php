<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Mcp\Servers\CoquiSeoServer;
use App\Mcp\Tools\InspectProjectSeoTool;
use App\Mcp\Tools\LookupKeywordMetricsTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoquiMcpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_mcp_endpoint_lists_coqui_tools(): void
    {
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

        CoquiSeoServer::tool(InspectProjectSeoTool::class, [
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
            ->assertSee('dataforseo_fallback');
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
            ->assertSee('Unauthorized access to project [secret-project.com].');

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
}
