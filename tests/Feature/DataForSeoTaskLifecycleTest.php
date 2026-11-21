<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Actions\RecordDataForSeoTaskResult;
use App\Domains\Serp\Jobs\ProcessDataForSeoPostbackJob;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DataForSeoTaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'test-dfs-login');
        config()->set('coqui.dataforseo.password', 'test-dfs-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');
        config()->set('coqui.dataforseo.task_max_age_hours', 12);

        $owner = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Agency', 'slug' => 'agency', 'owner_id' => $owner->id]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui App',
            'domain' => 'coqui.io',
            'canonical_url' => 'https://coqui.io/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    private function keyword(string $keyword): TrackedKeyword
    {
        /** @var TrackedKeyword */
        return TrackedKeyword::query()->create([
            'project_id' => $this->project->id,
            'keyword' => $keyword,
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{id: int, external: string, token: string}
     */
    private function task(TrackedKeyword $keyword, string $status = 'pending', int $ageMinutes = 15): array
    {
        $external = (string) Str::uuid();
        $token = Str::random(32);
        $id = DB::table('dataforseo_tasks')->insertGetId([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'external_task_id' => $external,
            'endpoint' => '/v3/serp/google/organic/task_post',
            'task_type' => 'serp_rank_tracking',
            'status' => $status,
            'postback_token' => $token,
            'request_context' => json_encode(['tracked_keyword_id' => $keyword->id], JSON_THROW_ON_ERROR),
            'submitted_at' => now()->subMinutes($ageMinutes),
            'created_at' => now()->subMinutes($ageMinutes),
            'updated_at' => now()->subMinutes($ageMinutes),
        ]);

        return ['id' => $id, 'external' => $external, 'token' => $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $external): array
    {
        return [
            'id' => $external,
            'tasks' => [[
                'id' => $external,
                'status_code' => 20000,
                'result' => [[
                    'items' => [[
                        'type' => 'organic',
                        'domain' => 'coqui.io',
                        'url' => 'https://coqui.io/voice',
                        'rank_group' => 4,
                        'rank_absolute' => 4,
                        'title' => 'Voice',
                    ]],
                ]],
            ]],
        ];
    }

    private function taskStatus(int $id): string
    {
        return (string) DB::table('dataforseo_tasks')->where('id', $id)->value('status');
    }

    public function test_a_postback_with_the_wrong_token_changes_nothing(): void
    {
        Queue::fake();
        $task = $this->task($this->keyword('voice generator'));

        $this->postJson(route('webhooks.dataforseo', ['token' => 'not-the-token']), $this->payload($task['external']))
            ->assertForbidden();

        $this->assertSame('pending', $this->taskStatus($task['id']));
        Queue::assertNothingPushed();
    }

    public function test_a_duplicate_postback_is_recorded_once(): void
    {
        Queue::fake();
        $keyword = $this->keyword('voice generator');
        $task = $this->task($keyword);
        $url = route('webhooks.dataforseo', ['token' => $task['token']]);

        $this->postJson($url, $this->payload($task['external']))->assertOk()->assertJson(['status' => 'accepted']);
        $this->postJson($url, $this->payload($task['external']))->assertOk()->assertJson(['status' => 'already_received']);

        // Stored on the task and queued once; nothing recorded inline.
        $this->assertSame('processing', $this->taskStatus($task['id']));
        Queue::assertPushed(ProcessDataForSeoPostbackJob::class, 1);
        $this->assertSame(0, KeywordRankSnapshot::query()->count());

        (new ProcessDataForSeoPostbackJob($task['id']))->handle(app(RecordDataForSeoTaskResult::class));
        // A retry of the same job doesn't record it again.
        (new ProcessDataForSeoPostbackJob($task['id']))->handle(app(RecordDataForSeoTaskResult::class));

        $this->assertSame('collected', $this->taskStatus($task['id']));
        $this->assertSame(1, KeywordRankSnapshot::query()->where('tracked_keyword_id', $keyword->id)->where('domain', 'coqui.io')->count());

        $this->postJson($url, $this->payload($task['external']))->assertOk()->assertJson(['status' => 'already_collected']);
        Queue::assertPushed(ProcessDataForSeoPostbackJob::class, 1);
    }

    public function test_a_postback_job_that_gives_up_marks_the_task_failed(): void
    {
        $task = $this->task($this->keyword('voice generator'), 'processing');

        (new ProcessDataForSeoPostbackJob($task['id']))->failed(new RuntimeException('database went away'));

        $this->assertSame('failed', $this->taskStatus($task['id']));
        $this->assertStringContainsString('database went away', (string) DB::table('dataforseo_tasks')->where('id', $task['id'])->value('error_message'));
    }

    public function test_an_errored_check_is_not_counted_as_in_progress(): void
    {
        $task = $this->task($this->keyword('voice generator'));
        Http::fake(['https://sandbox.dataforseo.com/v3/serp/google/organic/task_get/advanced/*' => Http::response('Bad gateway', 502)]);

        $this->artisan('dataforseo:collect-tasks')
            ->assertSuccessful()
            ->expectsOutputToContain('0 still in progress, 1 could not be checked');

        // Still young, so it stays pending for the next run.
        $this->assertSame('pending', $this->taskStatus($task['id']));
    }

    public function test_an_error_status_from_the_api_is_an_error_too(): void
    {
        $this->task($this->keyword('voice generator'));
        Http::fake(['https://sandbox.dataforseo.com/v3/serp/google/organic/task_get/advanced/*' => Http::response(['status_code' => 50000, 'status_message' => 'Internal error.'], 200)]);

        $this->artisan('dataforseo:collect-tasks')
            ->assertSuccessful()
            ->expectsOutputToContain('1 could not be checked');
    }

    public function test_a_stuck_task_is_failed_and_its_keyword_is_tracked_again(): void
    {
        config()->set('coqui.dataforseo.scheduled_spend', true);
        $keyword = $this->keyword('stuck keyword');
        $stuck = $this->task($keyword, 'pending', 13 * 60);
        // A stored postback that was never recorded is stuck too.
        $neverRecorded = $this->task($this->keyword('never recorded'), 'processing', 13 * 60);
        $young = $this->task($this->keyword('young keyword'), 'pending', 30);

        Http::fake([
            'https://sandbox.dataforseo.com/v3/serp/google/organic/task_get/advanced/*' => Http::response([
                'status_code' => 20000,
                'tasks' => [['status_code' => 40602, 'status_message' => 'Task In Queue.', 'result' => null]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/serp/google/organic/task_post' => Http::response([
                'status_code' => 20000,
                'tasks' => [['id' => (string) Str::uuid(), 'status_code' => 20100], ['id' => (string) Str::uuid(), 'status_code' => 20100]],
            ], 200),
        ]);

        $this->artisan('dataforseo:collect-tasks')
            ->assertSuccessful()
            ->expectsOutputToContain('Marked 2 DataForSEO tasks failed');

        $this->assertSame('failed', $this->taskStatus($stuck['id']));
        $this->assertSame('failed', $this->taskStatus($neverRecorded['id']));
        $this->assertSame('pending', $this->taskStatus($young['id']));
        $this->assertSame('No result after 12 hours.', DB::table('dataforseo_tasks')->where('id', $stuck['id'])->value('error_message'));

        // The next rank run includes the stuck keyword again (the young one is still in flight).
        $this->artisan('dataforseo:run-rank-batches')->assertSuccessful();

        Http::assertSent(function ($request) use ($keyword): bool {
            if (! str_ends_with($request->url(), '/task_post')) {
                return false;
            }

            return collect($request->data())->pluck('keyword')->contains($keyword->keyword)
                && ! collect($request->data())->pluck('keyword')->contains('young keyword');
        });
    }

    public function test_a_late_postback_for_a_failed_task_is_still_recorded(): void
    {
        $keyword = $this->keyword('late keyword');
        $task = $this->task($keyword, 'failed', 13 * 60);

        $this->postJson(route('webhooks.dataforseo', ['token' => $task['token']]), $this->payload($task['external']))
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        // The sync queue runs the job straight away.
        $this->assertSame('collected', $this->taskStatus($task['id']));
        $this->assertSame(1, KeywordRankSnapshot::query()->where('tracked_keyword_id', $keyword->id)->where('domain', 'coqui.io')->count());
    }

    public function test_a_late_postback_waiting_in_the_queue_is_not_expired(): void
    {
        Queue::fake();
        $keyword = $this->keyword('late queued keyword');
        // Submitted long ago, so only the postback's arrival is recent.
        $task = $this->task($keyword, 'pending', 13 * 60);

        $this->postJson(route('webhooks.dataforseo', ['token' => $task['token']]), $this->payload($task['external']))
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        Http::fake();
        $this->artisan('dataforseo:collect-tasks')->assertSuccessful();

        $this->assertSame('processing', $this->taskStatus($task['id']));

        // The queued job still records it.
        (new ProcessDataForSeoPostbackJob($task['id']))->handle(app(RecordDataForSeoTaskResult::class));
        $this->assertSame('collected', $this->taskStatus($task['id']));
    }
}
