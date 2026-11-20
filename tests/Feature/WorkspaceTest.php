<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_registration_provisions_personal_workspace_with_owner_role(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alice Developer',
            'email' => 'alice@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertRedirect('/dashboard');

        /** @var User $user */
        $user = User::query()->where('email', 'alice@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->where('owner_id', $user->id)->firstOrFail();
        $this->assertSame("Alice Developer's Workspace", $workspace->name);
        $this->assertTrue($workspace->members()->where('users.id', $user->id)->wherePivot('role', 'owner')->exists());
    }

    public function test_user_can_login_and_is_redirected_to_workspace(): void
    {
        $user = User::factory()->create([
            'email' => 'bob@example.com',
            'password' => 'SecurePass123!',
        ]);

        $workspace = Workspace::query()->create([
            'name' => 'Bob SEO',
            'slug' => 'bob-seo',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        $response = $this->post('/login', [
            'email' => 'bob@example.com',
            'password' => 'SecurePass123!',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        // Dashboard redirects to active workspace
        $dashboardResponse = $this->get('/dashboard');
        $dashboardResponse->assertRedirect("/workspaces/{$workspace->uuid}");
    }

    public function test_multi_tenant_isolation_prevents_unauthorized_workspace_access(): void
    {
        $userA = User::factory()->create();
        $workspaceA = Workspace::query()->create([
            'name' => 'Workspace A',
            'slug' => 'workspace-a',
            'owner_id' => $userA->id,
        ]);
        $workspaceA->members()->attach($userA->id, ['role' => 'owner']);

        $userB = User::factory()->create();

        // User B cannot view User A's workspace
        $this->actingAs($userB)
            ->get("/workspaces/{$workspaceA->uuid}")
            ->assertForbidden();

        // User B cannot update User A's workspace
        $this->actingAs($userB)
            ->put("/workspaces/{$workspaceA->uuid}", ['name' => 'Hacked Workspace'])
            ->assertForbidden();

        // User B cannot view or invite members in Workspace A
        $this->actingAs($userB)
            ->get("/workspaces/{$workspaceA->uuid}/members")
            ->assertForbidden();

        $this->actingAs($userB)
            ->post("/workspaces/{$workspaceA->uuid}/members", [
                'email' => 'newuser@example.com',
                'role' => 'editor',
            ])
            ->assertForbidden();
    }

    public function test_owner_and_admin_can_manage_members_and_roles(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $admin = User::factory()->create(['name' => 'Admin']);
        $editor = User::factory()->create(['name' => 'Editor']);
        $viewer = User::factory()->create(['name' => 'Viewer']);
        $outsider = User::factory()->create(['name' => 'Outsider']);

        $workspace = Workspace::query()->create([
            'name' => 'Collab Workspace',
            'slug' => 'collab-workspace',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);
        $workspace->members()->attach($admin->id, ['role' => 'admin']);
        $workspace->members()->attach($editor->id, ['role' => 'editor']);
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        // Admin invites outsider as editor
        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uuid}/members", [
                'email' => $outsider->email,
                'role' => 'editor',
            ])
            ->assertRedirect();

        $this->assertTrue($workspace->members()->where('users.id', $outsider->id)->wherePivot('role', 'editor')->exists());

        // Editor cannot invite or update members
        $newUser = User::factory()->create();
        $this->actingAs($editor)
            ->post("/workspaces/{$workspace->uuid}/members", [
                'email' => $newUser->email,
                'role' => 'viewer',
            ])
            ->assertForbidden();

        // Viewer cannot invite or update members
        $this->actingAs($viewer)
            ->post("/workspaces/{$workspace->uuid}/members", [
                'email' => $newUser->email,
                'role' => 'viewer',
            ])
            ->assertForbidden();

        // Owner can promote editor to admin
        $this->actingAs($owner)
            ->put("/workspaces/{$workspace->uuid}/members/{$editor->uuid}", [
                'role' => 'admin',
            ])
            ->assertRedirect();

        $this->assertSame('admin', $workspace->members()->where('users.id', $editor->id)->first()?->pivot?->role);

        // Owner cannot be removed
        $this->actingAs($admin)
            ->delete("/workspaces/{$workspace->uuid}/members/{$owner->uuid}")
            ->assertSessionHasErrors(['member']);

        // Admin can remove outsider
        $this->actingAs($admin)
            ->delete("/workspaces/{$workspace->uuid}/members/{$outsider->uuid}")
            ->assertRedirect();

        $this->assertFalse($workspace->members()->where('users.id', $outsider->id)->exists());
    }

    public function test_sanctum_desktop_token_generation_and_revocation(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Device Workspace',
            'slug' => 'device-workspace',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        // Reject empty abilities array
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->uuid}/tokens", [
                'name' => 'Empty Abilities Token',
                'abilities' => [],
            ])
            ->assertSessionHasErrors(['abilities']);

        // Generate token
        $response = $this->actingAs($user)
            ->post("/workspaces/{$workspace->uuid}/tokens", [
                'name' => 'MacBook Pro Desktop',
                'abilities' => ['crawls:sync', 'projects:read'],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('plainTextToken');

        $tokenModel = $user->tokens()->where('name', 'MacBook Pro Desktop')->firstOrFail();
        $this->assertSame(['crawls:sync', 'projects:read'], $tokenModel->abilities);

        // Revoke token
        $this->actingAs($user)
            ->delete("/workspaces/{$workspace->uuid}/tokens/{$tokenModel->uuid}")
            ->assertRedirect();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenModel->id,
        ]);
    }

    public function test_sanctum_token_authenticates_desktop_crawl_sync(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Sync Workspace',
            'slug' => 'sync-workspace',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Sync Site',
            'domain' => 'syncsite.com',
            'canonical_url' => 'https://syncsite.com/',
        ]);

        $token = $user->createToken('Coqui Desktop Client', ['crawls:sync']);
        $plainToken = $token->plainTextToken;

        $crawlId = (string) Str::uuid();

        // Authentic request using Bearer token
        $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
                'id' => $crawlId,
                'seed' => 'https://syncsite.com/',
                'seedKind' => 'url',
                'status' => 'running',
                'config' => [
                    'maxDepth' => 2,
                    'maxUrls' => 100,
                ],
                'createdAt' => 1795022400000,
                'totalUrls' => 1,
            ])
            ->assertCreated();

        // Revoke token immediately and clear test guard cache
        $user->tokens()->delete();
        $this->app['auth']->forgetGuards();

        // Subsequent request fails with 401 Unauthorized
        $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
                'id' => (string) Str::uuid(),
                'seed' => 'https://syncsite.com/',
                'seedKind' => 'url',
                'status' => 'running',
                'config' => ['maxDepth' => 1],
                'createdAt' => 1795022400000,
            ])
            ->assertUnauthorized();
    }

    public function test_project_policy_enforces_workspace_role_permissions(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();

        $workspace = Workspace::query()->create([
            'name' => 'Role Policy Workspace',
            'slug' => 'role-policy-workspace',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Policy Project',
            'domain' => 'policy.com',
            'canonical_url' => 'https://policy.com/',
        ]);

        // Viewer token cannot sync crawls (requires update permission on project)
        Sanctum::actingAs($viewer, ['crawls:sync']);
        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
            'id' => (string) Str::uuid(),
            'seed' => 'https://policy.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 1],
            'createdAt' => 1795022400000,
        ])->assertForbidden();

        // Outsider cannot sync crawls
        Sanctum::actingAs($outsider, ['crawls:sync']);
        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
            'id' => (string) Str::uuid(),
            'seed' => 'https://policy.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 1],
            'createdAt' => 1795022400000,
        ])->assertForbidden();

        // Owner can sync crawls
        Sanctum::actingAs($owner, ['crawls:sync']);
        $this->postJson("/api/v1/projects/{$project->uuid}/crawls/sync", [
            'id' => (string) Str::uuid(),
            'seed' => 'https://policy.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 1],
            'createdAt' => 1795022400000,
        ])->assertCreated();
    }
}
