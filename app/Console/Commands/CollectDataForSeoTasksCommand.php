<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Serp\Actions\RecordSerpRankSnapshots;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\Models\TrackedKeyword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CollectDataForSeoTasksCommand extends Command
{
    protected $signature = 'dataforseo:collect-tasks
                            {--limit=50 : Maximum number of uncollected tasks to poll}
                            {--threshold=5 : Minimum age in minutes for pending tasks to poll}
                            {--force : Poll all pending tasks regardless of age}';

    protected $description = 'Collect completed DataForSEO standard-queue tasks missed by webhooks.';

    public function handle(DataForSeoClient $client): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $thresholdMinutes = max(0, (int) $this->option('threshold'));
        $force = (bool) $this->option('force');

        $query = DB::table('dataforseo_tasks')
            ->where('status', 'pending');

        if (! $force && $thresholdMinutes > 0) {
            $query->where('submitted_at', '<=', now()->subMinutes($thresholdMinutes));
        }

        $pendingTasks = $query
            ->orderBy('submitted_at')
            ->limit($limit)
            ->get();

        if ($pendingTasks->isEmpty()) {
            $this->info('No pending DataForSEO tasks require collection.');

            return self::SUCCESS;
        }

        $this->info("Checking {$pendingTasks->count()} pending DataForSEO tasks...");

        $collected = 0;
        $inProgress = 0;

        foreach ($pendingTasks as $task) {
            $taskId = (string) $task->external_task_id;
            $endpoint = (string) $task->endpoint;

            $getEndpoint = str_contains($endpoint, 'task_post')
                ? str_replace('task_post', 'task_get/advanced', $endpoint)
                : '/v3/serp/google/organic/task_get/advanced';

            try {
                $result = $client->getTaskResult($taskId, $getEndpoint);
            } catch (Throwable $e) {
                $this->warn("Failed checking task [{$taskId}]: {$e->getMessage()}");

                continue;
            }

            if ($result !== null) {
                /** @var array<string, mixed>|null $firstTask */
                $firstTask = $result['tasks'][0] ?? null;

                if (! is_array($firstTask)) {
                    $inProgress++;

                    continue;
                }

                $taskStatusCode = (int) ($firstTask['status_code'] ?? 20000);
                $taskResult = $firstTask['result'] ?? null;

                if ($taskStatusCode >= 40000 && ! empty($firstTask['status_message']) && ! str_contains(strtolower((string) $firstTask['status_message']), 'progress')) {
                    DB::table('dataforseo_tasks')
                        ->where('id', $task->id)
                        ->update([
                            'status' => 'failed',
                            'result_payload' => json_encode($result, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);

                    $this->warn("Task [{$taskId}] failed with status code [{$taskStatusCode}]: {$firstTask['status_message']}");

                    continue;
                }

                if ($taskStatusCode !== 20000 || empty($taskResult) || ! is_array($taskResult)) {
                    $inProgress++;

                    continue;
                }

                DB::transaction(function () use ($task, $result): void {
                    if ($task->task_type === 'serp_rank_tracking' && is_string($task->request_context)) {
                        /** @var array<string, mixed> $context */
                        $context = json_decode($task->request_context, true) ?? [];
                        $trackedKeywordId = isset($context['tracked_keyword_id']) ? (int) $context['tracked_keyword_id'] : null;

                        if ($trackedKeywordId !== null) {
                            $trackedKeyword = TrackedKeyword::query()->find($trackedKeywordId);
                            if ($trackedKeyword !== null) {
                                app(RecordSerpRankSnapshots::class)->handle($trackedKeyword, $result);
                            }
                        }
                    }

                    DB::table('dataforseo_tasks')
                        ->where('id', $task->id)
                        ->update([
                            'status' => 'collected',
                            'result_payload' => json_encode($result, JSON_THROW_ON_ERROR),
                            'collected_at' => now(),
                            'updated_at' => now(),
                        ]);
                });

                $collected++;
                $this->line("Task [{$taskId}] collected successfully.");
            } else {
                $inProgress++;
            }
        }

        $this->info("Collection summary: {$collected} collected, {$inProgress} still in progress.");

        return self::SUCCESS;
    }
}
