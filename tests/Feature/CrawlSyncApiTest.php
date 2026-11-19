<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrawlSyncApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_can_sync_chunk_and_finalize_crawl(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Coqui SEO',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
        ]);

        Sanctum::actingAs($user, ['crawls:sync']);

        $crawlId = (string) Str::uuid();

        $this->postJson("/api/v1/projects/{$project->id}/crawls/sync", [
            'id' => $crawlId,
            'seed' => 'https://coquiseo.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => [
                'maxDepth' => 3,
                'maxUrls' => 500,
                'authUser' => 'demo',
                'authPassword' => 'secret123',
                'customHeaders' => ['Authorization: Bearer abc', 'X-Custom: safe'],
            ],
            'createdAt' => 1795022400000,
            'totalUrls' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/projects/{$project->id}/crawls/{$crawlId}/chunks", [
            'urls' => [
                [
                    'id' => 1,
                    'crawlId' => $crawlId,
                    'url' => 'https://coquiseo.com/',
                    'status' => 200,
                    'contentType' => 'text/html',
                    'title' => 'Coqui SEO',
                    'h1Count' => 1,
                    'depth' => 0,
                    'wordCount' => 320,
                    'discoveredAt' => 1795022401000,
                ],
            ],
            'links' => [
                [
                    'fromUrl' => 'https://coquiseo.com/',
                    'toUrl' => 'https://coquiseo.com/pricing',
                    'anchor' => 'Pricing',
                    'rel' => null,
                    'internal' => true,
                ],
            ],
            'issues' => [
                [
                    'crawlId' => $crawlId,
                    'url' => 'https://coquiseo.com/',
                    'rule' => 'missing_meta_description',
                    'severity' => 'warning',
                    'detail' => null,
                ],
            ],
        ])->assertOk();

        $this->postJson("/api/v1/projects/{$project->id}/crawls/{$crawlId}/finalize", [
            'status' => 'completed',
        ])
            ->assertOk()
            ->assertJson([
                'externalCrawlId' => $crawlId,
                'status' => 'completed',
                'totalUrls' => 1,
                'errors' => 0,
                'warnings' => 1,
                'opportunities' => 0,
            ]);

        $run = $project->crawlRuns()->where('external_crawl_id', $crawlId)->firstOrFail();
        $this->assertArrayNotHasKey('authPassword', $run->config);
        $this->assertSame('[redacted]', $run->config['authUser']);
        $this->assertContains('Authorization: [redacted]', $run->config['customHeaders']);
        $this->assertContains('X-Custom: safe', $run->config['customHeaders']);
    }

    public function test_get_me_returns_authenticated_user_and_workspaces(): void
    {
        $user = User::factory()->create(['name' => 'Alex Developer', 'email' => 'alex@example.com']);
        $workspace = Workspace::query()->create([
            'name' => 'Agency Alpha',
            'slug' => 'agency-alpha',
            'owner_id' => $user->id,
            'plan_tier' => 'scale',
        ]);

        Sanctum::actingAs($user, ['crawls:sync']);

        $response = $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJson([
                'user' => [
                    'id' => $user->id,
                    'name' => 'Alex Developer',
                    'email' => 'alex@example.com',
                ],
                'workspaces' => [
                    [
                        'id' => $workspace->id,
                        'name' => 'Agency Alpha',
                        'slug' => 'agency-alpha',
                        'planTier' => 'scale',
                        'role' => 'owner',
                    ],
                ],
            ]);
    }

    public function test_projects_api_can_list_and_filter_projects(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Core Workspace',
            'slug' => 'core-workspace',
            'owner_id' => $user->id,
        ]);

        $projectA = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Laravel Portal',
            'domain' => 'laravelportal.com',
            'canonical_url' => 'https://laravelportal.com/',
        ]);

        $projectB = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Search Engine Hub',
            'domain' => 'searchenginehub.org',
            'canonical_url' => 'https://searchenginehub.org/',
        ]);

        // Attach a crawl run to project A
        $crawlRun = CrawlRun::query()->create([
            'project_id' => $projectA->id,
            'external_crawl_id' => (string) Str::uuid(),
            'source' => 'desktop',
            'seed' => 'https://laravelportal.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => ['maxDepth' => 2],
            'total_urls' => 120,
            'error_count' => 2,
            'warning_count' => 5,
            'opportunity_count' => 1,
            'finalized_at' => now(),
        ]);

        Sanctum::actingAs($user, ['projects:read']);

        // List all projects
        $response = $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'workspaceId',
                        'workspaceName',
                        'name',
                        'domain',
                        'canonicalUrl',
                        'latestCrawl',
                    ],
                ],
                'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
            ]);

        $this->assertCount(2, $response->json('data'));

        // Search filter
        $searchResponse = $this->getJson('/api/v1/projects?search=portal')
            ->assertOk();
        $this->assertCount(1, $searchResponse->json('data'));
        $this->assertSame('Laravel Portal', $searchResponse->json('data.0.name'));
        $this->assertSame(120, $searchResponse->json('data.0.latestCrawl.totalUrls'));
    }

    public function test_projects_api_can_create_project_from_standalone_app(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Default Workspace',
            'slug' => 'default-workspace',
            'owner_id' => $user->id,
        ]);

        Sanctum::actingAs($user, ['projects:write']);

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'New SaaS Product',
            'domain' => 'https://www.newsaas.io/subpath',
            'crawlCadence' => 'daily',
        ])
            ->assertCreated()
            ->assertJson([
                'name' => 'New SaaS Product',
                'domain' => 'newsaas.io',
                'canonicalUrl' => 'https://newsaas.io/',
                'crawlCadence' => 'daily',
                'workspaceId' => $workspace->id,
            ]);

        $this->assertDatabaseHas('projects', [
            'name' => 'New SaaS Product',
            'domain' => 'newsaas.io',
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_projects_api_can_show_project_detail(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Workspace One',
            'slug' => 'workspace-one',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Detail Test',
            'domain' => 'detailtest.com',
            'canonical_url' => 'https://detailtest.com/',
        ]);

        Sanctum::actingAs($user, ['projects:read']);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJson([
                'id' => $project->id,
                'name' => 'Detail Test',
                'domain' => 'detailtest.com',
                'workspaceId' => $workspace->id,
                'workspaceName' => 'Workspace One',
            ]);
    }

    public function test_crawls_api_can_list_and_inspect_crawl_runs(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Workspace One',
            'slug' => 'workspace-one',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Crawl Runs Hub',
            'domain' => 'crawlhub.com',
            'canonical_url' => 'https://crawlhub.com/',
        ]);

        $externalId = (string) Str::uuid();
        $run = CrawlRun::query()->create([
            'project_id' => $project->id,
            'external_crawl_id' => $externalId,
            'source' => 'desktop',
            'seed' => 'https://crawlhub.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => ['maxUrls' => 1000],
            'total_urls' => 50,
            'error_count' => 1,
            'warning_count' => 2,
            'opportunity_count' => 0,
            'finalized_at' => now(),
        ]);

        // Insert issues for issueSummary breakdown
        $run->issues()->createMany([
            [
                'url' => 'https://crawlhub.com/broken',
                'url_hash' => hash('sha256', 'https://crawlhub.com/broken'),
                'rule' => 'broken_internal_link',
                'severity' => 'error',
                'detail' => 'Returned HTTP 404',
            ],
            [
                'url' => 'https://crawlhub.com/slow',
                'url_hash' => hash('sha256', 'https://crawlhub.com/slow'),
                'rule' => 'missing_meta_description',
                'severity' => 'warning',
                'detail' => 'Empty description tag',
            ],
        ]);

        Sanctum::actingAs($user, ['crawls:read']);

        // List crawls
        $this->getJson("/api/v1/projects/{$project->id}/crawls")
            ->assertOk()
            ->assertJson([
                'data' => [
                    [
                        'id' => $run->id,
                        'externalCrawlId' => $externalId,
                        'totalUrls' => 50,
                    ],
                ],
            ]);

        // Show crawl detail
        $this->getJson("/api/v1/projects/{$project->id}/crawls/{$externalId}")
            ->assertOk()
            ->assertJson([
                'id' => $run->id,
                'externalCrawlId' => $externalId,
                'totalUrls' => 50,
                'errorCount' => 1,
                'warningCount' => 2,
                'issueSummary' => [
                    ['severity' => 'error', 'rule' => 'broken_internal_link', 'count' => 1],
                    ['severity' => 'warning', 'rule' => 'missing_meta_description', 'count' => 1],
                ],
            ]);
    }

    public function test_chunk_ingest_handles_duplicate_urls_and_issues_within_same_chunk(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Collision Workspace',
            'slug' => 'collision-workspace',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Collision Test',
            'domain' => 'collisiontest.com',
            'canonical_url' => 'https://collisiontest.com/',
        ]);

        Sanctum::actingAs($user, ['crawls:sync']);

        $crawlId = (string) Str::uuid();

        $this->postJson("/api/v1/projects/{$project->id}/crawls/sync", [
            'id' => $crawlId,
            'seed' => 'https://collisiontest.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 2],
            'createdAt' => 1795022400000,
        ])->assertCreated();

        // Send a chunk that contains the exact same URL twice and duplicate issues
        $this->postJson("/api/v1/projects/{$project->id}/crawls/{$crawlId}/chunks", [
            'urls' => [
                [
                    'url' => 'https://collisiontest.com/page-1',
                    'status' => 200,
                    'title' => 'Page 1 First Draft',
                ],
                [
                    'url' => 'https://collisiontest.com/page-1',
                    'status' => 200,
                    'title' => 'Page 1 Final Title',
                ],
            ],
            'issues' => [
                [
                    'url' => 'https://collisiontest.com/page-1',
                    'rule' => 'missing_h1',
                    'severity' => 'warning',
                    'detail' => 'Draft issue',
                ],
                [
                    'url' => 'https://collisiontest.com/page-1',
                    'rule' => 'missing_h1',
                    'severity' => 'warning',
                    'detail' => 'Final issue',
                ],
            ],
        ])->assertOk();

        $this->postJson("/api/v1/projects/{$project->id}/crawls/{$crawlId}/finalize")
            ->assertOk()
            ->assertJson([
                'totalUrls' => 1,
                'warnings' => 1,
            ]);

        $this->assertDatabaseHas('crawl_urls', [
            'url' => 'https://collisiontest.com/page-1',
            'title' => 'Page 1 Final Title',
        ]);
    }

    public function test_sanctum_abilities_are_strictly_enforced(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Auth Workspace',
            'slug' => 'auth-workspace',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Auth Project',
            'domain' => 'authproject.com',
            'canonical_url' => 'https://authproject.com/',
        ]);

        // Token with only projects:read ability
        Sanctum::actingAs($user, ['projects:read']);

        // Should succeed on read
        $this->getJson("/api/v1/projects/{$project->id}")->assertOk();

        // Should be forbidden on project creation
        $this->postJson('/api/v1/projects', [
            'name' => 'Forbidden Project',
            'domain' => 'forbidden.com',
        ])->assertForbidden();

        // Should be forbidden on crawl sync
        $this->postJson("/api/v1/projects/{$project->id}/crawls/sync", [
            'id' => (string) Str::uuid(),
            'seed' => 'https://authproject.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => [],
            'createdAt' => 1795022400000,
        ])->assertForbidden();
    }

    public function test_unauthorized_user_cannot_access_another_workspace_project(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $workspace = Workspace::query()->create([
            'name' => 'Private Workspace',
            'slug' => 'private-workspace',
            'owner_id' => $owner->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Secret Project',
            'domain' => 'secretproject.com',
            'canonical_url' => 'https://secretproject.com/',
        ]);

        Sanctum::actingAs($attacker, ['crawls:sync', 'projects:read', 'projects:write']);

        $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
        $this->getJson("/api/v1/projects/{$project->id}/crawls")->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->id}/crawls/sync", [
            'id' => (string) Str::uuid(),
            'seed' => 'https://secretproject.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => [],
            'createdAt' => 1795022400000,
        ])->assertForbidden();
    }
}
