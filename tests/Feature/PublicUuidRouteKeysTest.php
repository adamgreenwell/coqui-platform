<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Billing\Services\StripeWebhookSignature;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Routes are keyed by public UUIDs so sequential IDs can't be enumerated.
 */
class PublicUuidRouteKeysTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $outsider;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->workspace = Workspace::query()->create([
            'name' => 'Owner Workspace',
            'slug' => 'owner-workspace',
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->members()->attach($this->owner->id, ['role' => 'owner']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Owner Project',
            'domain' => 'owner-project.test',
            'canonical_url' => 'https://owner-project.test/',
        ]);
    }

    public function test_models_get_a_uuid_v7_route_key_and_keep_integer_primary_keys(): void
    {
        foreach ([$this->owner, $this->workspace, $this->project] as $model) {
            $this->assertIsInt($model->getKey());
            $this->assertTrue($model->getIncrementing());
            $this->assertTrue(Str::isUuid($model->uuid, 7));
            $this->assertSame('uuid', $model->getRouteKeyName());
            $this->assertSame($model->uuid, $model->getRouteKey());
        }

        $this->assertStringContainsString(
            '/workspaces/'.$this->workspace->uuid.'/projects/'.$this->project->uuid,
            route('workspaces.projects.show', [$this->workspace, $this->project]),
        );
    }

    public function test_workspace_and_project_pages_resolve_by_uuid_and_404_on_integer_ids(): void
    {
        $ws = $this->workspace;
        $project = $this->project;

        $this->actingAs($this->owner)->get("/workspaces/{$ws->uuid}")->assertOk();
        $this->actingAs($this->owner)->get("/workspaces/{$ws->uuid}/projects/{$project->uuid}")->assertOk();

        $this->actingAs($this->owner)->get("/workspaces/{$ws->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/workspaces/{$ws->id}/projects/{$project->uuid}")->assertNotFound();
        $this->actingAs($this->owner)->get("/workspaces/{$ws->uuid}/projects/{$project->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/workspaces/{$ws->uuid}/members")->assertOk();
        $this->actingAs($this->owner)->get("/workspaces/{$ws->id}/members")->assertNotFound();
        $this->actingAs($this->owner)->get("/workspaces/{$ws->id}/tokens")->assertNotFound();
        $this->actingAs($this->owner)->get("/workspaces/{$ws->id}/billing")->assertNotFound();
        $this->actingAs($this->owner)->post("/workspaces/{$ws->id}/billing/checkout", ['tier' => 'pro'])->assertNotFound();
        $this->actingAs($this->owner)->put("/workspaces/{$ws->id}", ['name' => 'Renamed'])->assertNotFound();
        $this->actingAs($this->owner)->delete("/workspaces/{$ws->uuid}/projects/{$project->id}")->assertNotFound();

        $this->assertSame('Owner Workspace', $ws->fresh()?->name);
        $this->assertNotNull($project->fresh());
    }

    public function test_knowing_a_uuid_does_not_grant_access_to_someone_elses_workspace(): void
    {
        $ws = $this->workspace;
        $project = $this->project;

        $this->actingAs($this->outsider)->get("/workspaces/{$ws->uuid}")->assertForbidden();
        $this->actingAs($this->outsider)->get("/workspaces/{$ws->uuid}/members")->assertForbidden();
        $this->actingAs($this->outsider)->get("/workspaces/{$ws->uuid}/billing")->assertForbidden();
        $this->actingAs($this->outsider)->get("/workspaces/{$ws->uuid}/projects/{$project->uuid}")->assertForbidden();
        $this->actingAs($this->outsider)->put("/workspaces/{$ws->uuid}", ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($this->outsider)->delete("/workspaces/{$ws->uuid}/projects/{$project->uuid}")->assertForbidden();

        // Pairing your own workspace UUID with someone else's project UUID is a 404.
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Outsider Workspace',
            'slug' => 'outsider-workspace',
            'owner_id' => $this->outsider->id,
        ]);
        $otherWorkspace->members()->attach($this->outsider->id, ['role' => 'owner']);

        $this->actingAs($this->outsider)
            ->get("/workspaces/{$otherWorkspace->uuid}/projects/{$project->uuid}")
            ->assertNotFound();

        $this->assertSame('Owner Workspace', $ws->fresh()?->name);
        $this->assertNotNull($project->fresh());
    }

    public function test_inertia_props_expose_uuids_instead_of_integer_ids(): void
    {
        $this->actingAs($this->owner)
            ->get("/workspaces/{$this->workspace->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.id', $this->workspace->uuid)
                ->where('projects.0.id', $this->project->uuid)
                ->where('allWorkspaces.0.id', $this->workspace->uuid)
                ->where('auth.user.id', $this->owner->uuid)
                ->etc());
    }

    /**
     * Every page builds its URLs from `workspace.id` / `project.id`, so each
     * one must receive UUIDs (an integer here means broken links on that page).
     */
    public function test_every_workspace_page_receives_uuid_ids(): void
    {
        Http::fake();
        $this->workspace->update(['plan_tier' => 'agency']);
        $ws = "/workspaces/{$this->workspace->uuid}";
        $project = "{$ws}/projects/{$this->project->uuid}";

        $workspacePages = [$ws, "{$ws}/members", "{$ws}/tokens", "{$ws}/billing", "{$ws}/projects/create"];
        $projectPages = [
            $project,
            "{$project}/settings",
            "{$project}/keywords",
            "{$project}/keywords/brief",
            "{$project}/keyword-explorer",
            "{$project}/keyword-gap",
            "{$project}/backlinks",
            "{$project}/backlinks/watchlist",
            "{$project}/backlinks/intersect",
            "{$project}/analytics/performance",
            "{$project}/bot-logs",
            "{$project}/crawls/compare",
        ];

        foreach ([...$workspacePages, ...$projectPages] as $url) {
            $hasProject = in_array($url, $projectPages, true);

            $this->actingAs($this->owner)
                ->get($url)
                ->assertOk()
                ->assertInertia(function (Assert $page) use ($hasProject, $url): void {
                    $page->where('workspace.id', fn ($id): bool => $id === $this->workspace->uuid ?: $this->fail("{$url}: workspace.id is not the UUID"));
                    if ($hasProject) {
                        $page->where('project.id', fn ($id): bool => $id === $this->project->uuid ?: $this->fail("{$url}: project.id is not the UUID"));
                    }
                    $page->etc();
                });
        }
    }

    public function test_member_routes_use_user_uuids(): void
    {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'viewer']);
        $base = "/workspaces/{$this->workspace->uuid}/members";

        $this->actingAs($this->owner)->put("{$base}/{$member->id}", ['role' => 'editor'])->assertNotFound();
        $this->actingAs($this->owner)->delete("{$base}/{$member->id}")->assertNotFound();

        $this->actingAs($this->owner)->put("{$base}/{$member->uuid}", ['role' => 'editor'])->assertRedirect();
        $this->assertTrue($this->workspace->members()->where('users.id', $member->id)->wherePivot('role', 'editor')->exists());

        $this->actingAs($this->outsider)->delete("{$base}/{$member->uuid}")->assertForbidden();
        $this->actingAs($this->owner)->delete("{$base}/{$member->uuid}")->assertRedirect();
        $this->assertFalse($this->workspace->members()->where('users.id', $member->id)->exists());
    }

    public function test_desktop_tokens_are_revoked_by_uuid_only(): void
    {
        $this->owner->createToken('Desktop', ['crawls:sync']);
        /** @var PersonalAccessToken $token */
        $token = $this->owner->tokens()->firstOrFail();
        $this->assertInstanceOf(PersonalAccessToken::class, $token);
        $this->assertTrue(Str::isUuid($token->uuid));

        $base = "/workspaces/{$this->workspace->uuid}/tokens";

        $this->actingAs($this->owner)
            ->get($base)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tokens.0.id', $token->uuid)->etc());

        $this->actingAs($this->owner)->delete("{$base}/{$token->id}")->assertNotFound();
        $this->assertSame(1, $this->owner->tokens()->count());

        $this->actingAs($this->owner)->delete("{$base}/{$token->uuid}")->assertRedirect();
        $this->assertSame(0, $this->owner->tokens()->count());
    }

    public function test_project_child_resources_404_on_integer_ids(): void
    {
        $this->workspace->update(['plan_tier' => 'agency']);
        $base = "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}";

        /** @var ProjectCompetitor $competitor */
        $competitor = $this->project->competitors()->create(['domain' => 'rival.test', 'sort_order' => 1]);
        /** @var TrackedKeyword $keyword */
        $keyword = TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'uuid routes',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
        /** @var MonitoredBacklink $link */
        $link = MonitoredBacklink::query()->create([
            'project_id' => $this->project->id,
            'source_url' => 'https://partner.test/post',
            'source_url_hash' => hash('sha256', 'https://partner.test/post'),
            'target_url' => 'https://owner-project.test/',
            'expected_rel' => 'dofollow',
            'state' => 'active',
        ]);
        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'plausible',
            'external_identifier' => 'owner-project.test',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner)->delete("{$base}/competitors/{$competitor->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("{$base}/keywords/{$keyword->id}/snapshots")->assertNotFound();
        $this->actingAs($this->owner)->delete("{$base}/keywords/{$keyword->id}")->assertNotFound();
        $this->actingAs($this->owner)->delete("{$base}/backlinks/watchlist/{$link->id}")->assertNotFound();
        $this->actingAs($this->owner)->delete("{$base}/integrations/{$integration->id}")->assertNotFound();

        $this->actingAs($this->owner)
            ->get("{$base}/keywords/{$keyword->uuid}/snapshots")
            ->assertOk()
            ->assertJsonPath('keyword.id', $keyword->uuid);

        // Competitor reordering takes UUIDs, not integer IDs.
        $this->actingAs($this->owner)
            ->post("{$base}/competitors/reorder", ['order' => [$competitor->id]])
            ->assertSessionHasErrors('order.0');

        $this->actingAs($this->outsider)->delete("{$base}/competitors/{$competitor->uuid}")->assertForbidden();
        $this->actingAs($this->owner)->delete("{$base}/competitors/{$competitor->uuid}")->assertRedirect();
        $this->actingAs($this->owner)->delete("{$base}/backlinks/watchlist/{$link->uuid}")->assertRedirect();
        $this->actingAs($this->owner)->delete("{$base}/integrations/{$integration->uuid}")->assertRedirect();

        $this->assertNull(ProjectCompetitor::query()->find($competitor->id));
        $this->assertNull(MonitoredBacklink::query()->find($link->id));
        $this->assertNull(ProjectIntegration::query()->find($integration->id));
    }

    public function test_api_project_and_crawl_routes_use_uuids(): void
    {
        $run = CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => (string) Str::uuid(),
            'seed' => 'https://owner-project.test/',
            'seed_kind' => 'url',
            'source' => 'desktop',
            'status' => 'completed',
            'config' => ['maxDepth' => 1],
            'total_urls' => 3,
        ]);

        Sanctum::actingAs($this->owner, ['projects:read', 'crawls:read']);

        $this->getJson("/api/v1/projects/{$this->project->id}")->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->uuid}")
            ->assertOk()
            ->assertJsonPath('id', $this->project->uuid)
            ->assertJsonPath('workspaceId', $this->workspace->uuid);

        $this->getJson("/api/v1/projects/{$this->project->uuid}/crawls/{$run->id}")->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->uuid}/crawls/{$run->external_crawl_id}")
            ->assertOk()
            ->assertJsonPath('id', $run->external_crawl_id)
            ->assertJsonPath('projectId', $this->project->uuid);

        $this->getJson("/api/v1/projects?workspace_id={$this->workspace->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/projects?workspace_id={$this->workspace->uuid}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->project->uuid);

        Sanctum::actingAs($this->outsider, ['projects:read', 'crawls:read']);
        $this->getJson("/api/v1/projects/{$this->project->uuid}")->assertForbidden();
        $this->getJson("/api/v1/projects/{$this->project->uuid}/crawls/{$run->external_crawl_id}")->assertForbidden();
    }

    public function test_crawl_compare_ignores_sequential_run_ids(): void
    {
        // Creating share links needs the white-label reports feature.
        $this->workspace->update(['plan_tier' => 'agency']);
        $runs = collect([1, 2])->map(fn (int $i): CrawlRun => CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => (string) Str::uuid(),
            'seed' => 'https://owner-project.test/',
            'seed_kind' => 'url',
            'source' => 'desktop',
            'status' => 'completed',
            'config' => ['maxDepth' => 1],
            'total_urls' => $i,
        ]));
        [$baseline, $target] = [$runs[0], $runs[1]];
        $url = "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/crawls/compare";

        $this->actingAs($this->owner)
            ->get("{$url}?baseline={$baseline->id}&target={$target->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedBaselineId', null)
                ->where('diff', null)
                ->etc());

        $this->actingAs($this->owner)
            ->get("{$url}?baseline={$baseline->external_crawl_id}&target={$target->external_crawl_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedBaselineId', $baseline->external_crawl_id)
                ->where('selectedTargetId', $target->external_crawl_id)
                ->where('availableRuns.0.id', $target->external_crawl_id)
                ->etc());

        $this->actingAs($this->owner)
            ->postJson("{$url}/share", ['baseline_id' => $baseline->id, 'target_id' => $target->id])
            ->assertUnprocessable();
    }

    public function test_stripe_webhook_still_resolves_workspaces_by_integer_metadata(): void
    {
        $secret = 'whsec_uuid_route_keys';
        config()->set('services.stripe.webhook_secret', $secret);

        // Checkout sessions and subscriptions already in Stripe carry the
        // integer workspace ID in metadata; that internal lookup is unchanged.
        $payload = (string) json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_uuid_compat',
                'customer' => 'cus_uuid_compat',
                'subscription' => 'sub_uuid_compat',
                'metadata' => ['workspace_id' => (string) $this->workspace->id, 'plan_tier' => 'pro'],
            ]],
        ]);

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => StripeWebhookSignature::sign($payload, $secret),
        ], $payload)->assertOk();

        $this->assertSame('pro', $this->workspace->fresh()?->plan_tier);
    }

    public function test_migration_backfills_existing_rows(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/0001_01_18_000000_add_public_uuids_to_routed_tables.php');

        // Simulate production before this change: rows exist without a uuid column.
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('workspaces', 'uuid'));

        $userId = DB::table('users')->insertGetId([
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspaceId = DB::table('workspaces')->insertGetId([
            'name' => 'Legacy Workspace',
            'slug' => 'legacy-workspace',
            'owner_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('projects')->insert([
            'workspace_id' => $workspaceId,
            'name' => 'Legacy Project',
            'domain' => 'legacy.test',
            'canonical_url' => 'https://legacy.test/',
            'canonical_url' => 'https://legacy.test/',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        foreach (['users', 'workspaces', 'projects'] as $table) {
            $uuids = DB::table($table)->pluck('uuid');
            $this->assertNotEmpty($uuids);
            $this->assertSame($uuids->count(), $uuids->unique()->count(), "{$table} uuids must be unique");
            $uuids->each(fn ($uuid) => $this->assertTrue(Str::isUuid((string) $uuid), "{$table} row missing uuid"));
        }

        /** @var Workspace $legacy */
        $legacy = Workspace::query()->findOrFail($workspaceId);
        $this->assertSame(1, DB::table('projects')->where('workspace_id', $workspaceId)->count());
        $this->assertStringEndsWith('/workspaces/'.$legacy->uuid, route('workspaces.show', $legacy));
    }
}
