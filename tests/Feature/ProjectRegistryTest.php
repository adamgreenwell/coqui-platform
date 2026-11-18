<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_creation_normalizes_protocol_subdomain_and_trailing_slashes_to_canonical_domain_and_url(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'SEO Agency',
            'slug' => 'seo-agency',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects", [
                'name' => 'E-Commerce UK Store',
                'url' => 'https://www.my-shop.co.uk/store/collections?page=2#top',
                'location_code' => 2826,
                'language_code' => 'en',
                'crawl_cadence' => 'daily',
                'competitors' => [
                    'https://www.rival-one.co.uk/products/',
                    'rival-two.co.uk',
                ],
            ]);

        $project = Project::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $response->assertRedirect("/workspaces/{$workspace->id}/projects/{$project->id}");

        $this->assertSame('E-Commerce UK Store', $project->name);
        $this->assertSame('my-shop.co.uk', $project->domain);
        $this->assertSame('https://my-shop.co.uk/', $project->canonical_url);
        $this->assertSame(2826, $project->location_code);
        $this->assertSame('en', $project->language_code);
        $this->assertSame('daily', $project->crawl_cadence);

        // Competitors were normalized and attached
        $this->assertCount(2, $project->competitors);
        $this->assertTrue($project->competitors()->where('domain', 'rival-one.co.uk')->exists());
        $this->assertTrue($project->competitors()->where('domain', 'rival-two.co.uk')->exists());

        // Malformed inputs are rejected
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects", [
                'name' => 'Malformed URL',
                'url' => 'https://',
            ])
            ->assertSessionHasErrors(['url']);

        $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects", [
                'name' => 'Space in domain',
                'url' => 'not a domain at all',
            ])
            ->assertSessionHasErrors(['url']);
    }

    public function test_project_domain_is_unique_within_workspace_but_allowed_across_different_workspaces(): void
    {
        $userA = User::factory()->create();
        $workspaceA = Workspace::query()->create([
            'name' => 'Workspace A',
            'slug' => 'workspace-a',
            'owner_id' => $userA->id,
        ]);
        $workspaceA->members()->attach($userA->id, ['role' => 'owner']);

        $userB = User::factory()->create();
        $workspaceB = Workspace::query()->create([
            'name' => 'Workspace B',
            'slug' => 'workspace-b',
            'owner_id' => $userB->id,
        ]);
        $workspaceB->members()->attach($userB->id, ['role' => 'owner']);

        // User A creates project with example.com
        $this->actingAs($userA)
            ->post("/workspaces/{$workspaceA->id}/projects", [
                'name' => 'Project A',
                'url' => 'https://example.com',
            ])
            ->assertRedirect();

        // User A attempts to create another project in Workspace A with http://www.example.com/blog
        $this->actingAs($userA)
            ->post("/workspaces/{$workspaceA->id}/projects", [
                'name' => 'Duplicate Project A',
                'url' => 'http://www.example.com/blog',
            ])
            ->assertSessionHasErrors(['url']);

        // User B in Workspace B CAN create project with same domain (multi-tenant isolation)
        $this->actingAs($userB)
            ->post("/workspaces/{$workspaceB->id}/projects", [
                'name' => 'Project B',
                'url' => 'https://www.example.com/',
            ])
            ->assertRedirect();

        $this->assertTrue(Project::query()->where('workspace_id', $workspaceA->id)->where('domain', 'example.com')->exists());
        $this->assertTrue(Project::query()->where('workspace_id', $workspaceB->id)->where('domain', 'example.com')->exists());
    }

    public function test_users_can_add_reorder_and_delete_competitors_with_validation(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Competitor Workspace',
            'slug' => 'competitor-workspace',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Primary Brand',
            'domain' => 'brand.com',
            'canonical_url' => 'https://brand.com/',
        ]);

        // Add Competitor 1
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects/{$project->id}/competitors", [
                'domain' => 'https://www.comp1.com/pricing',
                'label' => 'Competitor One',
            ])
            ->assertRedirect();

        $comp1 = $project->competitors()->where('domain', 'comp1.com')->firstOrFail();
        $this->assertSame('Competitor One', $comp1->label);
        $this->assertSame(1, $comp1->sort_order);

        // Cannot add project's own domain as competitor
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects/{$project->id}/competitors", [
                'domain' => 'https://www.brand.com/',
            ])
            ->assertSessionHasErrors(['domain']);

        // Cannot add duplicate competitor
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects/{$project->id}/competitors", [
                'domain' => 'http://comp1.com',
            ])
            ->assertSessionHasErrors(['domain']);

        // Add 4 more competitors to reach maximum limit of 5
        for ($i = 2; $i <= 5; $i++) {
            $this->actingAs($user)
                ->post("/workspaces/{$workspace->id}/projects/{$project->id}/competitors", [
                    'domain' => "comp{$i}.com",
                ])
                ->assertRedirect();
        }

        $this->assertSame(5, $project->competitors()->count());

        // Attempting to add a 6th competitor is rejected
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects/{$project->id}/competitors", [
                'domain' => 'comp6.com',
            ])
            ->assertSessionHasErrors(['domain']);

        // Reorder competitors
        $competitorIds = $project->competitors()->pluck('id')->all();
        $reversedIds = array_reverse($competitorIds);

        $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects/{$project->id}/competitors/reorder", [
                'order' => $reversedIds,
            ])
            ->assertRedirect();

        $firstOrdered = $project->competitors()->orderBy('sort_order')->firstOrFail();
        $this->assertSame($reversedIds[0], $firstOrdered->id);

        // Delete competitor
        $this->actingAs($user)
            ->delete("/workspaces/{$workspace->id}/projects/{$project->id}/competitors/{$comp1->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('project_competitors', ['id' => $comp1->id]);
        $this->assertSame(4, $project->competitors()->count());
    }

    public function test_project_deletion_cascades_cleanly_and_soft_deletes_records(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Delete Workspace',
            'slug' => 'delete-workspace',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Project to Delete',
            'domain' => 'delete-me.com',
            'canonical_url' => 'https://delete-me.com/',
        ]);

        $competitor = $project->competitors()->create([
            'domain' => 'rival.com',
            'sort_order' => 1,
        ]);

        $crawlRun = $project->crawlRuns()->create([
            'external_crawl_id' => (string) Str::uuid(),
            'seed' => 'https://delete-me.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => ['maxDepth' => 1],
        ]);

        // Soft delete project
        $this->actingAs($user)
            ->delete("/workspaces/{$workspace->id}/projects/{$project->id}")
            ->assertRedirect("/workspaces/{$workspace->id}");

        $this->assertSoftDeleted('projects', ['id' => $project->id]);

        // Subsequent show request returns 404
        $this->actingAs($user)
            ->get("/workspaces/{$workspace->id}/projects/{$project->id}")
            ->assertNotFound();

        // Re-creating project with same domain restores it cleanly without unique constraint failure
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->id}/projects", [
                'name' => 'Restored Project',
                'url' => 'https://delete-me.com',
                'competitors' => ['https://new-competitor.com'],
            ])
            ->assertRedirect("/workspaces/{$workspace->id}/projects/{$project->id}");

        $restored = Project::query()->findOrFail($project->id);
        $this->assertFalse($restored->trashed());
        $this->assertSame('Restored Project', $restored->name);
        $this->assertTrue($restored->competitors()->where('domain', 'new-competitor.com')->exists());

        // Delete again before testing force delete cascade
        $this->actingAs($user)
            ->delete("/workspaces/{$workspace->id}/projects/{$project->id}")
            ->assertRedirect("/workspaces/{$workspace->id}");

        // Force delete cleanly cascades and leaves no orphaned competitor or crawl records
        $project->forceDelete();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('project_competitors', ['id' => $competitor->id]);
        $this->assertDatabaseMissing('crawl_runs', ['id' => $crawlRun->id]);
    }

    public function test_rbac_authorization_policies_enforced_across_project_endpoints(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();

        $workspace = Workspace::query()->create([
            'name' => 'RBAC Project Workspace',
            'slug' => 'rbac-project-workspace',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);
        $workspace->members()->attach($editor->id, ['role' => 'editor']);
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Protected Project',
            'domain' => 'protected.com',
            'canonical_url' => 'https://protected.com/',
        ]);

        // Viewer can view project overview
        $this->actingAs($viewer)
            ->get("/workspaces/{$workspace->id}/projects/{$project->id}")
            ->assertOk();

        // Viewer cannot create project
        $this->actingAs($viewer)
            ->post("/workspaces/{$workspace->id}/projects", [
                'name' => 'Viewer Project',
                'url' => 'https://viewer.com',
            ])
            ->assertForbidden();

        // Viewer cannot update project settings
        $this->actingAs($viewer)
            ->put("/workspaces/{$workspace->id}/projects/{$project->id}", [
                'name' => 'Hacked Project',
                'location_code' => 2840,
                'language_code' => 'en',
                'crawl_cadence' => 'daily',
            ])
            ->assertForbidden();

        // Viewer cannot add competitors
        $this->actingAs($viewer)
            ->post("/workspaces/{$workspace->id}/projects/{$project->id}/competitors", [
                'domain' => 'hacked-rival.com',
            ])
            ->assertForbidden();

        // Viewer cannot delete project
        $this->actingAs($viewer)
            ->delete("/workspaces/{$workspace->id}/projects/{$project->id}")
            ->assertForbidden();

        // Editor cannot delete project (only owner & admin can delete)
        $this->actingAs($editor)
            ->delete("/workspaces/{$workspace->id}/projects/{$project->id}")
            ->assertForbidden();

        // Editor CAN update project settings
        $this->actingAs($editor)
            ->put("/workspaces/{$workspace->id}/projects/{$project->id}", [
                'name' => 'Editor Updated Name',
                'location_code' => 2840,
                'language_code' => 'en',
                'crawl_cadence' => 'daily',
            ])
            ->assertRedirect();

        $this->assertSame('Editor Updated Name', $project->fresh()?->name);

        // Outsider cannot access project (404/403)
        $this->actingAs($outsider)
            ->get("/workspaces/{$workspace->id}/projects/{$project->id}")
            ->assertForbidden();

        // Owner can delete project
        $this->actingAs($owner)
            ->delete("/workspaces/{$workspace->id}/projects/{$project->id}")
            ->assertRedirect("/workspaces/{$workspace->id}");
    }
}
