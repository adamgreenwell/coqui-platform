<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Events\BacklinkAlertTriggered;
use App\Domains\Crawls\Actions\IngestDesktopCrawlSnapshot;
use App\Domains\Crawls\Events\CrawlProgressUpdated;
use App\Domains\Projects\Events\ProjectActivityLogged;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Events\SerpBatchCompleted;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectRealtimeAndActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_and_project_private_broadcast_channel_authorization(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'reverb-test-key',
            'broadcasting.connections.reverb.secret' => 'reverb-test-secret',
            'broadcasting.connections.reverb.app_id' => 'reverb-test-app',
        ]);
        Broadcast::purge();
        Broadcast::purge('null');
        Broadcast::purge('reverb');

        // Re-register channel routes on the active reverb broadcaster
        require base_path('routes/channels.php');

        try {
            /** @var User $owner */
            $owner = User::factory()->create();
            /** @var User $member */
            $member = User::factory()->create();
            /** @var User $outsider */
            $outsider = User::factory()->create();

            /** @var Workspace $workspace */
            $workspace = Workspace::query()->create([
                'name' => 'Broadcast Test Workspace',
                'slug' => 'broadcast-test-workspace',
                'owner_id' => $owner->id,
            ]);
            $workspace->members()->attach($owner->id, ['role' => 'owner']);
            $workspace->members()->attach($member->id, ['role' => 'viewer']);

            /** @var Project $project */
            $project = Project::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Broadcast Project',
                'domain' => 'broadcast-app.com',
                'canonical_url' => 'https://broadcast-app.com/',
            ]);

            // Verify policy gates resolve correctly
            $this->assertTrue($owner->can('view', $workspace));
            $this->assertTrue($member->can('view', $workspace));
            $this->assertFalse($outsider->can('view', $workspace));
            $this->assertTrue($owner->can('view', $project));
            $this->assertTrue($member->can('view', $project));
            $this->assertFalse($outsider->can('view', $project));

            // Workspace private channel authorization
            $this->actingAs($owner)
                ->post('/broadcasting/auth', [
                    'channel_name' => "private-workspace.{$workspace->uuid}",
                    'socket_id' => '1234.5678',
                ])
                ->assertOk();

            $this->actingAs($member)
                ->post('/broadcasting/auth', [
                    'channel_name' => "private-workspace.{$workspace->uuid}",
                    'socket_id' => '1234.5678',
                ])
                ->assertOk();

            $this->actingAs($outsider)
                ->post('/broadcasting/auth', [
                    'channel_name' => "private-workspace.{$workspace->uuid}",
                    'socket_id' => '1234.5678',
                ])
                ->assertForbidden();

            // Project private channel authorization
            $this->actingAs($owner)
                ->post('/broadcasting/auth', [
                    'channel_name' => "private-project.{$project->uuid}",
                    'socket_id' => '1234.5678',
                ])
                ->assertOk();

            $this->actingAs($member)
                ->post('/broadcasting/auth', [
                    'channel_name' => "private-project.{$project->uuid}",
                    'socket_id' => '1234.5678',
                ])
                ->assertOk();

            $this->actingAs($outsider)
                ->post('/broadcasting/auth', [
                    'channel_name' => "private-project.{$project->uuid}",
                    'socket_id' => '1234.5678',
                ])
                ->assertForbidden();

            // Non-existent project channel is forbidden
            $this->actingAs($owner)
                ->post('/broadcasting/auth', [
                    'channel_name' => 'private-project.999999',
                    'socket_id' => '1234.5678',
                ])
                ->assertForbidden();

            // The legacy integer channel name is refused, even for the owner
            $this->actingAs($owner)
                ->post('/broadcasting/auth', [
                    'channel_name' => "private-project.{$project->id}",
                    'socket_id' => '1234.5678',
                ])
                ->assertForbidden();
        } finally {
            config(['broadcasting.default' => 'null']);
            Broadcast::purge();
        }
    }

    public function test_standardized_event_payloads_and_channels(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create();
        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Channel Workspace',
            'slug' => 'channel-workspace',
            'owner_id' => $owner->id,
        ]);
        /** @var Project $project */
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Channel Project',
            'domain' => 'channel-app.com',
            'canonical_url' => 'https://channel-app.com/',
        ]);
        $channelName = 'private-project.'.$project->uuid;

        // 1. CrawlProgressUpdated event
        $crawlEvent = new CrawlProgressUpdated(
            projectId: $project->id,
            externalCrawlId: (string) Str::uuid(),
            crawledUrls: 120,
            totalUrls: 500,
            errorCount: 3,
            warningCount: 8,
            opportunityCount: 15,
            status: 'running',
        );

        $channels = $crawlEvent->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame($channelName, $channels[0]->name);
        $this->assertSame('CrawlProgressUpdated', $crawlEvent->broadcastAs());

        $crawlPayload = $crawlEvent->broadcastWith();
        $this->assertSame($project->id, $crawlPayload['project_id']);
        $this->assertSame(120, $crawlPayload['crawled_urls']);
        $this->assertSame(500, $crawlPayload['total_urls']);
        $this->assertSame(3, $crawlPayload['error_count']);
        $this->assertSame('running', $crawlPayload['status']);

        // 2. SerpBatchCompleted event
        $serpEvent = new SerpBatchCompleted(
            projectId: $project->id,
            batchId: 'batch-xyz-123',
            keywordCount: 50,
            updatedCount: 48,
            status: 'completed',
        );

        $serpChannels = $serpEvent->broadcastOn();
        $this->assertCount(1, $serpChannels);
        $this->assertSame($channelName, $serpChannels[0]->name);
        $this->assertSame('SerpBatchCompleted', $serpEvent->broadcastAs());

        $serpPayload = $serpEvent->broadcastWith();
        $this->assertSame('batch-xyz-123', $serpPayload['batch_id']);
        $this->assertSame(50, $serpPayload['keyword_count']);

        // 3. BacklinkAlertTriggered event
        $alertEvent = new BacklinkAlertTriggered(
            projectId: $project->id,
            alertType: 'lost_backlink',
            severity: 'critical',
            message: 'High-authority backlink dropped',
            targetUrl: 'https://broadcast-app.com/blog/featured',
            meta: ['referring_domain' => 'authority.edu', 'domain_rank' => 85],
        );

        $alertChannels = $alertEvent->broadcastOn();
        $this->assertCount(1, $alertChannels);
        $this->assertSame($channelName, $alertChannels[0]->name);
        $this->assertSame('BacklinkAlertTriggered', $alertEvent->broadcastAs());

        $alertPayload = $alertEvent->broadcastWith();
        $this->assertSame('lost_backlink', $alertPayload['alert_type']);
        $this->assertSame('critical', $alertPayload['severity']);
        $this->assertSame('https://broadcast-app.com/blog/featured', $alertPayload['target_url']);
    }

    public function test_activity_logging_and_realtime_broadcast_on_project_lifecycle_events(): void
    {
        Event::fake([ProjectActivityLogged::class]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Activity Workspace',
            'slug' => 'activity-workspace',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        // 1. Creating a project logs activity
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->uuid}/projects", [
                'name' => 'Activity Test Project',
                'url' => 'https://activity-site.com',
            ])
            ->assertRedirect();

        /** @var Project $project */
        $project = $workspace->projects()->where('domain', 'activity-site.com')->firstOrFail();

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'event_type' => 'project.created',
        ]);

        Event::assertDispatched(ProjectActivityLogged::class, function (ProjectActivityLogged $event) use ($project) {
            return $event->activity->project_id === $project->id && $event->activity->event_type === 'project.created';
        });

        // 2. Adding a competitor logs activity
        $this->actingAs($user)
            ->post("/workspaces/{$workspace->uuid}/projects/{$project->uuid}/competitors", [
                'domain' => 'competitor-one.com',
                'label' => 'Rival Brand',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'event_type' => 'competitor.added',
        ]);

        $competitor = $project->competitors()->where('domain', 'competitor-one.com')->firstOrFail();

        // 3. Updating project settings logs activity
        $this->actingAs($user)
            ->put("/workspaces/{$workspace->uuid}/projects/{$project->uuid}", [
                'name' => 'Renamed Activity Project',
                'location_code' => 2826,
                'language_code' => 'en',
                'crawl_cadence' => 'daily',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'event_type' => 'project.updated',
        ]);

        // 4. Removing a competitor logs activity
        $this->actingAs($user)
            ->delete("/workspaces/{$workspace->uuid}/projects/{$project->uuid}/competitors/{$competitor->uuid}")
            ->assertRedirect();

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'event_type' => 'competitor.removed',
        ]);
    }

    public function test_crawl_finalization_logs_activity_and_broadcasts_crawl_progress(): void
    {
        Event::fake([CrawlProgressUpdated::class, ProjectActivityLogged::class]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Crawl Event Workspace',
            'slug' => 'crawl-event-workspace',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        /** @var Project $project */
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Crawl Event Project',
            'domain' => 'crawl-event.com',
            'canonical_url' => 'https://crawl-event.com/',
        ]);

        $crawlRun = $project->crawlRuns()->create([
            'external_crawl_id' => (string) Str::uuid(),
            'source' => 'desktop',
            'seed' => 'https://crawl-event.com/',
            'seed_kind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 2],
        ]);

        $ingest = app(IngestDesktopCrawlSnapshot::class);
        $ingest->finalize($crawlRun, 'completed');

        Event::assertDispatched(CrawlProgressUpdated::class, function (CrawlProgressUpdated $e) use ($project) {
            return $e->projectId === $project->id && $e->status === 'completed';
        });

        Event::assertDispatched(ProjectActivityLogged::class, function (ProjectActivityLogged $e) use ($project) {
            return $e->activity->project_id === $project->id && $e->activity->event_type === 'crawl.completed';
        });

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'event_type' => 'crawl.completed',
        ]);
    }

    public function test_crawl_finalization_handles_soft_deleted_project_cleanly(): void
    {
        Event::fake([CrawlProgressUpdated::class, ProjectActivityLogged::class]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Trashed Project Workspace',
            'slug' => 'trashed-project-workspace',
            'owner_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        /** @var Project $project */
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Trashed Crawl Project',
            'domain' => 'trashed-site.com',
            'canonical_url' => 'https://trashed-site.com/',
        ]);

        $crawlRun = $project->crawlRuns()->create([
            'external_crawl_id' => (string) Str::uuid(),
            'source' => 'desktop',
            'seed' => 'https://trashed-site.com/',
            'seed_kind' => 'url',
            'status' => 'running',
            'config' => ['maxDepth' => 1],
        ]);

        // Project is deleted while crawl is running
        $project->delete();
        $this->assertSoftDeleted('projects', ['id' => $project->id]);

        $ingest = app(IngestDesktopCrawlSnapshot::class);
        $finalized = $ingest->finalize($crawlRun, 'completed');

        $this->assertSame('completed', $finalized->status);
        $this->assertNotNull($finalized->finalized_at);
        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'event_type' => 'crawl.completed',
        ]);
    }

    public function test_project_activities_retrieval_and_multi_tenant_isolation(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create();
        /** @var User $outsider */
        $outsider = User::factory()->create();

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Feed Workspace',
            'slug' => 'feed-workspace',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        /** @var Project $project */
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Feed Project',
            'domain' => 'feed-app.com',
            'canonical_url' => 'https://feed-app.com/',
        ]);

        ProjectActivity::log($project, 'test.event', 'Test Activity 1', 'Sample description', ['sample' => true], $owner);
        ProjectActivity::log($project, 'test.event', 'Test Activity 2', 'Sample description 2', null, $owner);

        // Project Overview Show view hydrates activities in Inertia props
        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->uuid}/projects/{$project->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Show')
                ->has('activities', 2)
                ->where('activities.0.title', 'Test Activity 2')
                ->where('activities.1.title', 'Test Activity 1')
            );

        // JSON activities API endpoint returns paginated results
        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->uuid}/projects/{$project->uuid}/activities")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.title', 'Test Activity 2');

        // Outsider cannot view project overview or activity API
        $this->actingAs($outsider)
            ->get("/workspaces/{$workspace->uuid}/projects/{$project->uuid}")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get("/workspaces/{$workspace->uuid}/projects/{$project->uuid}/activities")
            ->assertForbidden();
    }
}
