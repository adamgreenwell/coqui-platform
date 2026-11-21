<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
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

        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
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

        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/{$crawlId}/chunks", [
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

        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/{$crawlId}/finalize", [
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
                    'id' => $user->uuid,
                    'name' => 'Alex Developer',
                    'email' => 'alex@example.com',
                ],
                'workspaces' => [
                    [
                        'id' => $workspace->uuid,
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
                'workspaceId' => $workspace->uuid,
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

        $this->getJson("/api/v1/projects/{$project->uuid}")
            ->assertOk()
            ->assertJson([
                'id' => $project->uuid,
                'name' => 'Detail Test',
                'domain' => 'detailtest.com',
                'workspaceId' => $workspace->uuid,
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
        $this->getJson("/api/v1/projects/{$project->uuid}/crawls")
            ->assertOk()
            ->assertJson([
                'data' => [
                    [
                        'id' => $externalId,
                        'externalCrawlId' => $externalId,
                        'totalUrls' => 50,
                    ],
                ],
            ]);

        // Show crawl detail
        $this->getJson("/api/v1/projects/{$project->uuid}/crawls/{$externalId}")
            ->assertOk()
            ->assertJson([
                'id' => $externalId,
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

        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
            'id' => $crawlId,
            'seed' => 'https://collisiontest.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 2],
            'createdAt' => 1795022400000,
        ])->assertCreated();

        // Send a chunk that contains the exact same URL twice and duplicate issues
        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/{$crawlId}/chunks", [
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

        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/{$crawlId}/finalize")
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
        $this->getJson("/api/v1/projects/{$project->uuid}")->assertOk();

        // Should be forbidden on project creation
        $this->postJson('/api/v1/projects', [
            'name' => 'Forbidden Project',
            'domain' => 'forbidden.com',
        ])->assertForbidden();

        // Should be forbidden on crawl sync
        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
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

        $this->getJson("/api/v1/projects/{$project->uuid}")->assertForbidden();
        $this->getJson("/api/v1/projects/{$project->uuid}/crawls")->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
            'id' => (string) Str::uuid(),
            'seed' => 'https://secretproject.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => [],
            'createdAt' => 1795022400000,
        ])->assertForbidden();
    }

    public function test_cannot_create_project_with_duplicate_domain_or_invalid_canonical_url(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Validation Workspace',
            'slug' => 'val-workspace',
            'owner_id' => $user->id,
        ]);
        Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Existing Site',
            'domain' => 'existingsite.com',
            'canonical_url' => 'https://existingsite.com/',
        ]);

        Sanctum::actingAs($user, ['projects:write']);

        // Attempting to register existing domain
        $this->postJson('/api/v1/projects', [
            'name' => 'Duplicate Site',
            'domain' => 'https://existingsite.com/',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);

        // Attempting to register invalid canonical URL
        $this->postJson('/api/v1/projects', [
            'name' => 'Invalid Canonical',
            'domain' => 'invalidsite.org',
            'canonicalUrl' => 'not-a-valid-url-structure',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['canonicalUrl']);
    }

    public function test_creating_soft_deleted_project_restores_it(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Trash Workspace',
            'slug' => 'trash-workspace',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Old Trashed Site',
            'domain' => 'trashed.com',
            'canonical_url' => 'https://trashed.com/',
        ]);
        $project->delete();
        $this->assertTrue($project->fresh()->trashed());

        Sanctum::actingAs($user, ['projects:write']);

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Restored Trashed Site',
            'domain' => 'trashed.com',
        ])
            ->assertOk()
            ->assertJson([
                'id' => $project->uuid,
                'name' => 'Restored Trashed Site',
                'domain' => 'trashed.com',
                'restored' => true,
            ]);

        $this->assertFalse($project->fresh()->trashed());
    }

    public function test_import_can_sync_chunk_and_finalize_idempotently(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Import Workspace',
            'slug' => 'import-workspace',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Screaming Frog Import',
            'domain' => 'importtest.com',
            'canonical_url' => 'https://importtest.com/',
        ]);

        Sanctum::actingAs($user, ['crawls:sync']);

        $crawlId = (string) Str::uuid();
        $base = "/api/v1/projects/{$project->uuid}/crawls/{$crawlId}";

        $sync = fn () => $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
            'id' => $crawlId,
            'seed' => 'https://importtest.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 3, 'maxUrls' => 500],
            'createdAt' => 1795022400000,
            'totalUrls' => 2,
            'source' => 'import',
        ])->assertCreated()->assertJson(['externalCrawlId' => $crawlId]);

        // A Screaming Frog import carries URLs and links but no issues.
        $chunk = fn () => $this->postJson("{$base}/chunks", [
            'urls' => [
                [
                    'id' => 1,
                    'url' => 'https://importtest.com/',
                    'status' => 200,
                    'title' => 'Imported Home',
                    'h1Count' => 1,
                    'depth' => 0,
                ],
                [
                    'id' => 2,
                    'url' => 'https://importtest.com/pricing',
                    'status' => 200,
                    'title' => 'Imported Pricing',
                    'h1Count' => 1,
                    'depth' => 1,
                ],
            ],
            'links' => [
                [
                    'fromUrl' => 'https://importtest.com/',
                    'toUrl' => 'https://importtest.com/pricing',
                    'anchor' => 'Pricing',
                    'rel' => null,
                    'internal' => true,
                ],
            ],
        ])->assertOk();

        $finalize = fn () => $this->postJson("{$base}/finalize", ['status' => 'completed'])
            ->assertOk()
            ->assertJson([
                'externalCrawlId' => $crawlId,
                'status' => 'completed',
                'totalUrls' => 2,
                'errors' => 0,
                'warnings' => 0,
                'opportunities' => 0,
            ]);

        $sync();
        $chunk();
        $finalize();

        $run = $project->crawlRuns()->where('external_crawl_id', $crawlId)->sole();
        $this->assertSame('import', $run->source);
        $this->assertNotNull($run->finalized_at);

        // Re-running the same import reopens the finalized run, takes the same
        // chunks again and re-finalizes without creating a second run.
        $sync();
        $chunk();
        $finalize();

        $this->assertSame(1, $project->crawlRuns()->where('external_crawl_id', $crawlId)->count());

        $run->refresh();
        $this->assertSame('import', $run->source);
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->total_urls);
        $this->assertSame(0, $run->error_count);
        $this->assertSame(0, $run->warning_count);
        $this->assertSame(0, $run->opportunity_count);
        $this->assertNotNull($run->finalized_at);
        $this->assertSame(2, DB::table('crawl_urls')->where('crawl_run_id', $run->id)->count());
        $this->assertSame(1, DB::table('crawl_links')->where('crawl_run_id', $run->id)->count());
        $this->assertSame(0, DB::table('crawl_issues')->where('crawl_run_id', $run->id)->count());

        // The source filter includes imports.
        $this->getJson("/api/v1/projects/{$project->uuid}/crawls?source=import")
            ->assertOk()
            ->assertJsonPath('data.0.externalCrawlId', $crawlId)
            ->assertJsonPath('data.0.source', 'import');

        // An import does not count as the project's last audit.
        $this->assertNull($project->fresh()->last_crawled_at);
    }

    public function test_desktop_and_import_sources_cannot_take_over_each_other(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Ownership Workspace',
            'slug' => 'ownership-workspace',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ownership Project',
            'domain' => 'ownershiptest.com',
            'canonical_url' => 'https://ownershiptest.com/',
        ]);

        Sanctum::actingAs($user, ['crawls:sync']);

        $importCrawlId = (string) Str::uuid();
        $payload = fn (string $id, array $overrides): array => $overrides + [
            'id' => $id,
            'seed' => 'https://ownershiptest.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 1],
            'createdAt' => 1795022400000,
        ];

        $sync = fn (array $body) => $this->postJson(
            "/api/v1/projects/{$project->uuid}/crawls/sync",
            $body,
        );

        // An existing import run cannot be claimed by a desktop payload.
        $sync($payload($importCrawlId, ['source' => 'import']))->assertCreated();
        $sync($payload($importCrawlId, ['source' => 'desktop']))->assertStatus(409);

        // A desktop run cannot be claimed by an import payload either.
        $desktopCrawlId = (string) Str::uuid();
        $sync($payload($desktopCrawlId, []))->assertCreated();
        $sync($payload($desktopCrawlId, ['source' => 'import']))->assertStatus(409);

        $this->assertSame('import', $project->crawlRuns()->where('external_crawl_id', $importCrawlId)->sole()->source);
        $this->assertSame('desktop', $project->crawlRuns()->where('external_crawl_id', $desktopCrawlId)->sole()->source);
        $this->assertSame(2, $project->crawlRuns()->count());
    }

    public function test_imported_runs_are_not_the_latest_audit_on_the_project_page_or_api(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Audit Workspace',
            'slug' => 'audit-workspace',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Audit Project',
            'domain' => 'audittest.com',
            'canonical_url' => 'https://audittest.com/',
        ]);

        // A real desktop audit exists, then a newer Screaming Frog import lands.
        $auditId = (string) Str::uuid();
        CrawlRun::query()->create([
            'project_id' => $project->id,
            'external_crawl_id' => $auditId,
            'source' => 'desktop',
            'seed' => 'https://audittest.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => ['maxDepth' => 3],
            'total_urls' => 40,
            'error_count' => 3,
            'warning_count' => 6,
            'opportunity_count' => 1,
            'finalized_at' => now(),
        ]);

        Sanctum::actingAs($user, ['crawls:sync']);
        $importId = (string) Str::uuid();
        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
            'id' => $importId,
            'seed' => 'https://audittest.com/',
            'seedKind' => 'url',
            'status' => 'completed',
            'config' => ['maxDepth' => 3],
            'createdAt' => 1795022400000,
            'totalUrls' => 999,
            'source' => 'import',
        ])->assertCreated();
        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/{$importId}/finalize", [
            'status' => 'completed',
        ])->assertOk();

        // Web page: audit numbers come from the desktop run, the import is a badge.
        $this->actingAs($user)
            ->get("/workspaces/{$workspace->uuid}/projects/{$project->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Show')
                ->where('latestCrawl.source', 'desktop')
                ->where('latestCrawl.externalCrawlId', $auditId)
                ->where('latestCrawl.totalUrls', 40)
                ->where('latestImport.source', 'import')
                ->where('latestImport.externalCrawlId', $importId)
            );

        // API: the latest crawl summary never falls back to an import.
        Sanctum::actingAs($user, ['projects:read']);
        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.0.latestCrawl.source', 'desktop')
            ->assertJsonPath('data.0.latestCrawl.externalCrawlId', $auditId);
        $this->getJson("/api/v1/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('crawlStats.latestRun.source', 'desktop')
            ->assertJsonPath('crawlStats.latestRun.externalCrawlId', $auditId);

        // Both runs stay visible in crawl history.
        $this->getJson("/api/v1/projects/{$project->uuid}/crawls?source=import")
            ->assertOk()
            ->assertJsonPath('data.0.externalCrawlId', $importId);
    }
}
