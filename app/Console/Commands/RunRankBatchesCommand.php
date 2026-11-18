<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\Models\TrackedKeyword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RunRankBatchesCommand extends Command
{
    protected $signature = 'dataforseo:run-rank-batches
                            {--project= : Optional project ID to process}
                            {--limit=100 : Maximum keywords to submit}
                            {--force : Force dispatch ignoring cadence timing}';

    protected $description = 'Submit due rank tracking batches to DataForSEO standard queue.';

    public function handle(DataForSeoClient $client): int
    {
        $scheduledSpendEnabled = (bool) config('coqui.dataforseo.scheduled_spend');
        $isTesting = app()->environment('testing');

        if (! $scheduledSpendEnabled && ! $isTesting) {
            $this->info('Skipped: DATAFORSEO_SCHEDULED_SPEND is disabled.');

            return self::SUCCESS;
        }

        $projectId = $this->option('project') ? (int) $this->option('project') : null;
        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');

        $query = TrackedKeyword::query()
            ->with(['project.workspace'])
            ->where('is_active', true);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        if (! $force) {
            $query->where(function ($q): void {
                $q->whereNull('last_checked_at')
                    ->orWhere(function ($dailyQ): void {
                        $dailyQ->where('cadence', 'daily')
                            ->where('last_checked_at', '<=', now()->subHours(20));
                    })
                    ->orWhere(function ($weeklyQ): void {
                        $weeklyQ->where('cadence', 'weekly')
                            ->where('last_checked_at', '<=', now()->subDays(6)->subHours(20));
                    });
            });
        }

        $keywords = $query->limit($limit)->get();

        if ($keywords->isEmpty()) {
            $this->info('No tracked keywords are currently due for rank tracking.');

            return self::SUCCESS;
        }

        $this->info("Found {$keywords->count()} keywords due for rank tracking.");

        /** @var array<int, list<TrackedKeyword>> $grouped */
        $grouped = [];
        foreach ($keywords as $keyword) {
            $grouped[$keyword->project_id][] = $keyword;
        }

        $submittedCount = 0;

        foreach ($grouped as $kwList) {
            $project = $kwList[0]->project;
            if ($project === null || $project->workspace === null) {
                continue;
            }

            $tasksPayload = [];
            $tokenMap = [];

            foreach ($kwList as $kw) {
                $token = Str::random(32);
                $callbackUrl = route('webhooks.dataforseo', ['token' => $token]);

                $tasksPayload[] = [
                    'keyword' => $kw->keyword,
                    'device' => $kw->device,
                    'location_code' => $kw->location_code,
                    'language_code' => $kw->language_code,
                    'postback_url' => $callbackUrl,
                    'tag' => "kw:{$kw->id}",
                ];

                $tokenMap[$kw->id] = $token;
            }

            try {
                $response = $client->submitStandardSerpTasks($tasksPayload, $project, isScheduled: true);
            } catch (Throwable $e) {
                $this->error("Failed submitting SERP tasks for project {$project->id}: {$e->getMessage()}");

                continue;
            }

            $returnedTasks = $response['tasks'] ?? [];

            foreach ($returnedTasks as $index => $taskData) {
                $externalTaskId = (string) ($taskData['id'] ?? '');
                $kw = $kwList[$index] ?? null;

                if ($externalTaskId === '' || $kw === null) {
                    continue;
                }

                $token = $tokenMap[$kw->id] ?? Str::random(32);

                DB::table('dataforseo_tasks')->insert([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'external_task_id' => $externalTaskId,
                    'endpoint' => '/v3/serp/google/organic/task_post',
                    'task_type' => 'serp_rank_tracking',
                    'status' => 'pending',
                    'postback_token' => $token,
                    'request_context' => json_encode([
                        'tracked_keyword_id' => $kw->id,
                        'keyword' => $kw->keyword,
                        'device' => $kw->device,
                        'location_code' => $kw->location_code,
                    ], JSON_THROW_ON_ERROR),
                    'result_payload' => null,
                    'submitted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $submittedCount++;
            }
        }

        $this->info("Successfully submitted {$submittedCount} SERP rank tracking tasks to DataForSEO standard queue.");

        return self::SUCCESS;
    }
}
