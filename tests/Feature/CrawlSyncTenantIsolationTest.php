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

class CrawlSyncTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $attacker;

    private Project $attackerProject;

    private Project $victimProject;

    protected function setUp(): void
    {
        parent::setUp();

        $victim = User::factory()->create();
        $victimWorkspace = Workspace::query()->create([
            'name' => 'Victim Workspace',
            'slug' => 'victim-workspace',
            'owner_id' => $victim->id,
        ]);
        $this->victimProject = Project::query()->create([
            'workspace_id' => $victimWorkspace->id,
            'name' => 'Victim',
            'domain' => 'victim.example',
            'canonical_url' => 'https://victim.example/',
        ]);

        $this->attacker = User::factory()->create();
        $attackerWorkspace = Workspace::query()->create([
            'name' => 'Attacker Workspace',
            'slug' => 'attacker-workspace',
            'owner_id' => $this->attacker->id,
        ]);
        $this->attackerProject = Project::query()->create([
            'workspace_id' => $attackerWorkspace->id,
            'name' => 'Attacker',
            'domain' => 'attacker.example',
            'canonical_url' => 'https://attacker.example/',
        ]);
    }

    public function test_cannot_take_over_another_projects_crawl_by_id(): void
    {
        $victimRun = $this->makeRun($this->victimProject, 'desktop');

        Sanctum::actingAs($this->attacker, ['crawls:sync']);

        $this->postJson("/api/v1/projects/{$this->attackerProject->id}/crawls/sync", $this->syncPayload($victimRun->external_crawl_id))
            ->assertStatus(409);

        $victimRun->refresh();
        $this->assertSame($this->victimProject->id, $victimRun->project_id);
        $this->assertSame('https://victim.example/', $victimRun->seed);
        $this->assertSame('completed', $victimRun->status);
    }

    public function test_cannot_sync_over_a_server_side_run_in_own_project(): void
    {
        $workerRun = $this->makeRun($this->attackerProject, 'deploy_smoke');

        Sanctum::actingAs($this->attacker, ['crawls:sync']);

        $this->postJson("/api/v1/projects/{$this->attackerProject->id}/crawls/sync", $this->syncPayload($workerRun->external_crawl_id))
            ->assertStatus(409);

        $this->assertSame('deploy_smoke', $workerRun->refresh()->source);
    }

    public function test_client_cannot_create_server_side_sources(): void
    {
        Sanctum::actingAs($this->attacker, ['crawls:sync']);

        foreach (['worker', 'deploy_smoke'] as $source) {
            $this->postJson(
                "/api/v1/projects/{$this->attackerProject->id}/crawls/sync",
                $this->syncPayload((string) Str::uuid()) + ['source' => $source],
            )->assertStatus(422)->assertJsonValidationErrors('source');
        }

        $this->assertSame(0, CrawlRun::query()->count());
    }

    public function test_desktop_runs_are_always_stored_as_desktop_source(): void
    {
        Sanctum::actingAs($this->attacker, ['crawls:sync']);
        $crawlId = (string) Str::uuid();

        $this->postJson("/api/v1/projects/{$this->attackerProject->id}/crawls/sync", $this->syncPayload($crawlId))
            ->assertCreated();
        // Re-syncing the same crawl in the same project still updates it.
        $this->postJson("/api/v1/projects/{$this->attackerProject->id}/crawls/sync", ['status' => 'completed'] + $this->syncPayload($crawlId))
            ->assertCreated();

        $run = CrawlRun::query()->where('external_crawl_id', $crawlId)->sole();
        $this->assertSame('desktop', $run->source);
        $this->assertSame('completed', $run->status);
        $this->assertSame($this->attackerProject->id, $run->project_id);
    }

    public function test_chunks_and_finalize_cannot_target_server_side_runs(): void
    {
        $smokeRun = $this->makeRun($this->attackerProject, 'deploy_smoke', 'running');

        Sanctum::actingAs($this->attacker, ['crawls:sync']);
        $base = "/api/v1/projects/{$this->attackerProject->id}/crawls/{$smokeRun->external_crawl_id}";

        $this->postJson("{$base}/chunks", ['urls' => [], 'links' => [], 'issues' => []])->assertNotFound();
        $this->postJson("{$base}/finalize", ['status' => 'completed'])->assertNotFound();

        $this->assertSame('running', $smokeRun->refresh()->status);
    }

    private function makeRun(Project $project, string $source, string $status = 'completed'): CrawlRun
    {
        return CrawlRun::query()->create([
            'project_id' => $project->id,
            'external_crawl_id' => (string) Str::uuid(),
            'source' => $source,
            'seed' => $project->canonical_url,
            'seed_kind' => 'url',
            'status' => $status,
            'config' => [],
            'total_urls' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncPayload(string $crawlId): array
    {
        return [
            'id' => $crawlId,
            'seed' => 'https://attacker.example/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 1],
            'createdAt' => 1795022400000,
            'totalUrls' => 0,
        ];
    }
}
