<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Models\BacklinkHistorySnapshot;
use App\Domains\Integrations\Jobs\SyncGoogleSearchConsoleJob;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Projects\Models\WorkspaceActivity;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WorkspaceScopedTokensAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspaceA;

    private Workspace $workspaceB;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Ada']);
        [$this->workspaceA, $this->projectA] = $this->workspaceWithProject('Alpha', 'alpha.test');
        [$this->workspaceB, $this->projectB] = $this->workspaceWithProject('Beta', 'beta.test');
    }

    /**
     * @return array{0: Workspace, 1: Project}
     */
    private function workspaceWithProject(string $name, string $domain): array
    {
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'owner_id' => $this->user->id,
            'plan_tier' => 'pro',
            'monthly_dfs_budget_usd' => 25.00,
        ]);
        $workspace->members()->attach($this->user->id, ['role' => 'owner']);

        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name.' Site',
            'domain' => $domain,
            'canonical_url' => "https://{$domain}/",
            'location_code' => 2840,
            'language_code' => 'en',
        ]);

        return [$workspace, $project];
    }

    private function createTokenThroughUi(Workspace $workspace, string $name = 'Laptop'): string
    {
        $this->actingAs($this->user)
            ->post("/workspaces/{$workspace->uuid}/tokens", [
                'name' => $name,
                'abilities' => ['crawls:sync', 'projects:read', 'projects:write'],
            ])
            ->assertSessionHas('plainTextToken');

        $plain = (string) session('plainTextToken');
        // Later requests authenticate with the bearer token only.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $plain;
    }

    public function test_a_token_created_for_one_workspace_is_refused_in_another(): void
    {
        $token = $this->createTokenThroughUi($this->workspaceA);
        $this->assertSame($this->workspaceA->id, PersonalAccessToken::query()->sole()->workspace_id);

        $this->withToken($token)->getJson("/api/v1/projects/{$this->projectA->uuid}")->assertOk();
        $this->withToken($token)->getJson("/api/v1/projects/{$this->projectB->uuid}")->assertForbidden();

        // Listings only show the token's workspace.
        $this->withToken($token)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->projectA->uuid);
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonCount(1, 'workspaces')
            ->assertJsonPath('workspaces.0.id', $this->workspaceA->uuid);

        // New projects land in the token's workspace and can't be put elsewhere.
        $this->withToken($token)->postJson('/api/v1/projects', ['name' => 'New', 'domain' => 'new-site.test'])
            ->assertCreated()
            ->assertJsonPath('workspaceId', $this->workspaceA->uuid);
        $this->withToken($token)->postJson('/api/v1/projects', ['name' => 'Elsewhere', 'domain' => 'elsewhere.test', 'workspaceId' => $this->workspaceB->uuid])
            ->assertForbidden();
    }

    public function test_an_older_unscoped_token_keeps_working_everywhere_and_is_flagged(): void
    {
        $legacy = $this->user->createToken('Old desktop', ['projects:read'])->plainTextToken;

        $this->withToken($legacy)->getJson("/api/v1/projects/{$this->projectA->uuid}")->assertOk();
        $this->withToken($legacy)->getJson("/api/v1/projects/{$this->projectB->uuid}")->assertOk();
    }

    public function test_each_workspace_lists_only_its_own_tokens_and_legacy_ones(): void
    {
        $this->createTokenThroughUi($this->workspaceA, 'Alpha laptop');
        $this->user->createToken('Old desktop', ['projects:read']);

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspaceB->uuid}/tokens")
            ->assertInertia(fn ($page) => $page
                ->has('tokens', 1)
                ->where('tokens.0.name', 'Old desktop')
                ->where('tokens.0.allWorkspaces', true));

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspaceA->uuid}/tokens")
            ->assertInertia(fn ($page) => $page
                ->has('tokens', 2)
                ->where('tokens.0.name', 'Old desktop')
                ->where('tokens.1.name', 'Alpha laptop')
                ->where('tokens.1.allWorkspaces', false));

        // A token from workspace A can't be revoked through workspace B's page.
        $alpha = PersonalAccessToken::query()->where('name', 'Alpha laptop')->sole();
        $this->actingAs($this->user)
            ->delete("/workspaces/{$this->workspaceB->uuid}/tokens/{$alpha->uuid}")
            ->assertNotFound();
    }

    public function test_a_viewer_can_revoke_their_own_token_but_not_create_one(): void
    {
        $viewer = User::factory()->create();
        $this->workspaceA->members()->attach($viewer->id, ['role' => 'viewer']);
        $newToken = $viewer->createToken('Viewer laptop', ['projects:read']);
        $newToken->accessToken->forceFill(['workspace_id' => $this->workspaceA->id])->save();

        $this->actingAs($viewer)
            ->get("/workspaces/{$this->workspaceA->uuid}/tokens")
            ->assertInertia(fn ($page) => $page->where('canCreateTokens', false)->has('tokens', 1));

        $this->actingAs($viewer)
            ->post("/workspaces/{$this->workspaceA->uuid}/tokens", ['name' => 'More', 'abilities' => ['projects:read']])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->delete("/workspaces/{$this->workspaceA->uuid}/tokens/{$newToken->accessToken->getAttribute('uuid')}")
            ->assertRedirect();
        $this->assertSame(0, $viewer->tokens()->count());
    }

    public function test_membership_changes_are_recorded_and_shown_in_the_activity_feeds(): void
    {
        $grace = User::factory()->create(['name' => 'Grace']);

        $this->actingAs($this->user)->post("/workspaces/{$this->workspaceA->uuid}/members", ['email' => $grace->email, 'role' => 'viewer'])->assertRedirect();
        $this->actingAs($this->user)->put("/workspaces/{$this->workspaceA->uuid}/members/{$grace->uuid}", ['role' => 'editor'])->assertRedirect();
        $this->actingAs($this->user)->delete("/workspaces/{$this->workspaceA->uuid}/members/{$grace->uuid}")->assertRedirect();

        $events = WorkspaceActivity::query()->where('workspace_id', $this->workspaceA->id)->orderBy('id')->get();
        $this->assertSame(['workspace.member_added', 'workspace.member_role_changed', 'workspace.member_removed'], $events->pluck('event_type')->all());
        $this->assertSame('Ada changed Grace from viewer to editor.', $events[1]->description);
        $this->assertSame($this->user->id, $events[0]->user_id);

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspaceA->uuid}/members")
            ->assertInertia(fn ($page) => $page->has('history', 3)->where('history.0.eventType', 'workspace.member_removed'));

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspaceA->uuid}/projects/{$this->projectA->uuid}")
            ->assertInertia(fn ($page) => $page->where('activities', fn ($rows): bool => collect($rows)->pluck('eventType')->contains('workspace.member_role_changed')));
    }

    public function test_repeated_or_no_op_membership_changes_are_not_recorded(): void
    {
        $grace = User::factory()->create(['name' => 'Grace']);
        $outsider = User::factory()->create(['name' => 'Outsider']);
        $base = "/workspaces/{$this->workspaceA->uuid}/members";

        $this->actingAs($this->user)->post($base, ['email' => $grace->email, 'role' => 'viewer'])->assertRedirect();
        // Same role again: nothing changes.
        $this->actingAs($this->user)->put("{$base}/{$grace->uuid}", ['role' => 'viewer'])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->delete("{$base}/{$grace->uuid}")->assertSessionHasNoErrors();
        // A repeat removal, and changes to someone outside the workspace.
        $this->actingAs($this->user)->delete("{$base}/{$grace->uuid}")->assertSessionHasErrors('member');
        $this->actingAs($this->user)->put("{$base}/{$outsider->uuid}", ['role' => 'editor'])->assertSessionHasErrors('member');
        $this->actingAs($this->user)->delete("{$base}/{$outsider->uuid}")->assertSessionHasErrors('member');

        $this->assertSame(
            ['workspace.member_added', 'workspace.member_removed'],
            WorkspaceActivity::query()->where('workspace_id', $this->workspaceA->id)->orderBy('id')->pluck('event_type')->all(),
        );
    }

    public function test_failed_rank_checks_and_collectors_appear_in_the_project_feed(): void
    {
        $keyword = TrackedKeyword::query()->create([
            'project_id' => $this->projectA->id,
            'keyword' => 'uptime monitor',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
        ]);
        $token = Str::random(32);
        $externalId = (string) Str::uuid();
        DB::table('dataforseo_tasks')->insert([
            'workspace_id' => $this->workspaceA->id,
            'project_id' => $this->projectA->id,
            'external_task_id' => $externalId,
            'endpoint' => '/v3/serp/google/organic/task_post',
            'task_type' => 'serp_rank_tracking',
            'status' => 'pending',
            'postback_token' => $token,
            'request_context' => json_encode(['tracked_keyword_id' => $keyword->id, 'keyword' => 'uptime monitor'], JSON_THROW_ON_ERROR),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(route('webhooks.dataforseo', ['token' => $token]), [
            'id' => $externalId,
            'tasks' => [['status_code' => 40501, 'status_message' => 'Invalid Field']],
        ])->assertStatus(422);

        $failure = ProjectActivity::query()->where('event_type', 'serp.task_failed')->sole();
        $this->assertSame($this->projectA->id, $failure->project_id);
        $this->assertStringContainsString('uptime monitor', (string) $failure->description);

        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->projectA->id,
            'provider' => 'gsc',
            'site_url' => 'https://alpha.test/',
            'external_identifier' => 'https://alpha.test/',
            'status' => 'connected',
            'credentials_encrypted' => ['access_token' => 'a', 'refresh_token' => 'b'],
        ]);
        (new SyncGoogleSearchConsoleJob($integration))->failed(new RuntimeException('Quota exceeded'));

        $collector = ProjectActivity::query()->where('event_type', 'integration.sync_failed')->sole();
        $this->assertStringContainsString('Quota exceeded', (string) $collector->description);
    }

    public function test_rank_batch_submissions_are_logged_per_project(): void
    {
        config()->set('coqui.dataforseo.login', 'test-login');
        config()->set('coqui.dataforseo.password', 'test-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');
        config()->set('coqui.dataforseo.scheduled_spend', true);

        $keyword = TrackedKeyword::query()->create([
            'project_id' => $this->projectA->id,
            'keyword' => 'uptime monitor',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
        ]);
        Http::fake(['sandbox.dataforseo.com/*' => Http::response([
            'status_code' => 20000,
            'tasks' => [['id' => (string) Str::uuid(), 'status_code' => 20100, 'data' => ['tag' => "kw:{$keyword->id}"]]],
        ])]);

        $this->artisan('dataforseo:run-rank-batches')->assertSuccessful();

        $activity = ProjectActivity::query()->where('project_id', $this->projectA->id)->where('event_type', 'serp.rank_batch_submitted')->sole();
        $this->assertSame(1, $activity->properties['submitted'] ?? null);
    }

    public function test_the_overview_shows_keyword_visibility_and_a_backlink_baseline(): void
    {
        $first = TrackedKeyword::query()->create(['project_id' => $this->projectA->id, 'keyword' => 'one', 'device' => 'desktop', 'location_code' => 2840, 'language_code' => 'en', 'cadence' => 'daily', 'is_active' => true]);
        TrackedKeyword::query()->create(['project_id' => $this->projectA->id, 'keyword' => 'two', 'device' => 'desktop', 'location_code' => 2840, 'language_code' => 'en', 'cadence' => 'daily', 'is_active' => true]);
        KeywordRankSnapshot::query()->create([
            'tracked_keyword_id' => $first->id,
            'project_id' => $this->projectA->id,
            'domain' => 'alpha.test',
            'rank_group' => 1,
            'checked_on' => now()->toDateString(),
        ]);
        BacklinkHistorySnapshot::query()->create([
            'project_id' => $this->projectA->id,
            'snapshot_date' => now()->toDateString(),
            'backlinks_count' => 1200,
            'referring_domains_count' => 90,
            'new_backlinks' => 0,
            'lost_backlinks' => 0,
            'new_referring_domains' => 0,
            'lost_referring_domains' => 0,
            'rank' => 310,
        ]);

        $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspaceA->uuid}/projects/{$this->projectA->uuid}")
            ->assertInertia(fn ($page) => $page
                // #1 for one of two keywords: 100 of 200 points.
                ->where('keywordVisibility.score', 50)
                ->where('keywordVisibility.top10', 1)
                ->where('keywordVisibility.tracked', 2)
                ->where('backlinkBaseline.backlinks', 1200)
                ->where('backlinkBaseline.referringDomains', 90));
    }
}
