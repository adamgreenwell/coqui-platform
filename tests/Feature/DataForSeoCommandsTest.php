<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataForSeoCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'test-dfs-login');
        config()->set('coqui.dataforseo.password', 'test-dfs-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'SEO Agency',
            'slug' => 'seo-agency',
            'owner_id' => $this->user->id,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Client Site',
            'domain' => 'client-site.com',
            'canonical_url' => 'https://client-site.com/',
        ]);
    }

    public function test_smoke_command_authenticates_and_reports_account_balance(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/appendix/user_data' => Http::response([
                'version' => '0.1.20230605',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'id' => '06051515-2840-0066-0000-54b036511a51',
                        'status_code' => 20000,
                        'result' => [
                            [
                                'login' => 'demo_dfs_user',
                                'money' => [
                                    'balance' => 42.50,
                                    'total' => 100.00,
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('dataforseo:smoke')
            ->assertSuccessful()
            ->expectsOutputToContain('Checking DataForSEO connection')
            ->expectsOutputToContain('Authenticated as: demo_dfs_user')
            ->expectsOutputToContain('Account Balance: $42.50');
    }

    public function test_smoke_command_reports_failure_on_network_or_api_error(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/appendix/user_data' => Http::response([
                'status_code' => 40101,
                'status_message' => 'Authorization error.',
            ], 401),
        ]);

        $this->artisan('dataforseo:smoke')
            ->assertFailed()
            ->expectsOutputToContain('DataForSEO API connection failed');
    }

    public function test_collect_tasks_recovers_ready_tasks_and_leaves_in_progress(): void
    {
        $readyTaskId = (string) Str::uuid();
        $pendingTaskId = (string) Str::uuid();

        DB::table('dataforseo_tasks')->insert([
            [
                'workspace_id' => $this->workspace->id,
                'project_id' => $this->project->id,
                'external_task_id' => $readyTaskId,
                'endpoint' => '/v3/serp/google/organic/task_post',
                'task_type' => 'serp_standard',
                'status' => 'pending',
                'postback_token' => 'token-123',
                'submitted_at' => now()->subMinutes(15),
                'created_at' => now()->subMinutes(15),
                'updated_at' => now()->subMinutes(15),
            ],
            [
                'workspace_id' => $this->workspace->id,
                'project_id' => $this->project->id,
                'external_task_id' => $pendingTaskId,
                'endpoint' => '/v3/serp/google/organic/task_post',
                'task_type' => 'serp_standard',
                'status' => 'pending',
                'postback_token' => 'token-456',
                'submitted_at' => now()->subMinutes(10),
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ],
        ]);

        Http::fake([
            "https://sandbox.dataforseo.com/v3/serp/google/organic/task_get/advanced/{$readyTaskId}" => Http::response([
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'id' => $readyTaskId,
                        'status_code' => 20000,
                        'result' => [
                            ['keyword' => 'test keyword', 'items_count' => 10],
                        ],
                    ],
                ],
            ], 200),
            "https://sandbox.dataforseo.com/v3/serp/google/organic/task_get/advanced/{$pendingTaskId}" => Http::response([
                'status_code' => 40602,
                'status_message' => 'Task Not Ready.',
            ], 200),
        ]);

        $this->artisan('dataforseo:collect-tasks', ['--threshold' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain("Task [{$readyTaskId}] collected successfully.")
            ->expectsOutputToContain('Collection summary: 1 collected, 1 still in progress, 0 could not be checked.');

        $readyTask = DB::table('dataforseo_tasks')->where('external_task_id', $readyTaskId)->first();
        $this->assertSame('collected', $readyTask->status);
        $this->assertNotNull($readyTask->collected_at);
        $this->assertStringContainsString('test keyword', (string) $readyTask->result_payload);

        $pendingTask = DB::table('dataforseo_tasks')->where('external_task_id', $pendingTaskId)->first();
        $this->assertSame('pending', $pendingTask->status);
        $this->assertNull($pendingTask->collected_at);
    }
}
