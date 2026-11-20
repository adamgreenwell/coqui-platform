<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Integrations\Models\BotCrawlLog;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmokeAndTelemetryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $outsider;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Victim Workspace',
            'slug' => 'victim-workspace',
            'has_runnr_addon' => true,
            'owner_id' => $this->owner->id,
        ]);
        $workspace->members()->attach($this->owner, ['role' => 'owner']);
        $this->project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Victim Site',
            'domain' => 'victim-site.com',
            'canonical_url' => 'https://victim-site.com/',
            'runnr_site_id' => 'site_victim_1',
        ]);

        // The outsider owns a different workspace, so they hold valid tokens.
        $this->outsider = User::factory()->create();
        $other = Workspace::query()->create([
            'name' => 'Outsider Workspace',
            'slug' => 'outsider-workspace',
            'owner_id' => $this->outsider->id,
        ]);
        $other->members()->attach($this->outsider, ['role' => 'owner']);

        CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => '99999999-9999-9999-9999-999999999965',
            'source' => 'deploy_smoke',
            'seed' => 'https://victim-site.com/',
            'seed_kind' => 'url',
            'status' => 'completed',
            'config' => ['deploy' => ['deploy_id' => 'dep_secret'], 'smoke_results' => ['passed' => false, 'regressions' => []]],
        ]);
    }

    public function test_latest_smoke_details_are_not_readable_across_workspaces(): void
    {
        Sanctum::actingAs($this->outsider, ['projects:read', 'crawls:read']);

        $this->getJson("/api/v1/projects/{$this->project->uuid}/deploy-smoke/latest")->assertForbidden();
    }

    public function test_latest_smoke_details_remain_readable_by_members(): void
    {
        Sanctum::actingAs($this->owner, ['projects:read', 'crawls:read']);

        $this->getJson("/api/v1/projects/{$this->project->uuid}/deploy-smoke/latest")->assertOk();
    }

    public function test_badge_is_served_by_token_not_project_id(): void
    {
        $this->get("/api/v1/projects/{$this->project->uuid}/deploy-smoke/badge")->assertNotFound();
        $this->get('/api/v1/deploy-smoke/badge/'.str_repeat('x', 40))->assertNotFound();

        $token = $this->project->deployBadgeToken();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        $this->assertSame($token, $this->project->refresh()->deployBadgeToken(), 'Token must be stable once issued.');

        $this->get('/api/v1/deploy-smoke/badge/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');
    }

    public function test_signed_in_outsider_cannot_write_telemetry_for_another_tenants_site(): void
    {
        config()->set('services.runnr.telemetry_token', 'runnr-telemetry-secret');

        $this->actingAs($this->outsider)
            ->postJson('/api/v1/telemetry/bot-logs', $this->telemetryPayload())
            ->assertForbidden();

        $this->assertSame(0, BotCrawlLog::query()->count());
    }

    public function test_signed_in_member_can_write_telemetry_for_their_own_site(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/v1/telemetry/bot-logs', $this->telemetryPayload())
            ->assertStatus(202);
    }

    public function test_runnr_platform_token_can_write_telemetry(): void
    {
        config()->set('services.runnr.telemetry_token', 'runnr-telemetry-secret');

        $this->withToken('runnr-telemetry-secret')
            ->postJson('/api/v1/telemetry/bot-logs', $this->telemetryPayload())
            ->assertStatus(202);
    }

    /**
     * @return array<string, mixed>
     */
    private function telemetryPayload(): array
    {
        return [
            'siteId' => 'site_victim_1',
            'entries' => [[
                'botFamily' => 'googlebot',
                'statusBucket' => 200,
                'hitCount' => 1000,
                'bucketHour' => '2026-11-19T04:00:00Z',
            ]],
        ];
    }
}
