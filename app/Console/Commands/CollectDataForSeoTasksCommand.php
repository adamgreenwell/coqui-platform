<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Serp\Actions\RecordDataForSeoTaskResult;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\Jobs\ProcessDataForSeoPostbackJob;
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
        if (! $client->isConfigured()) {
            $this->info('Skipped: DataForSEO credentials are not configured.');

            return self::SUCCESS;
        }

        $expired = $this->failExpiredTasks();
        if ($expired > 0) {
            $this->warn("Marked {$expired} DataForSEO tasks failed after waiting too long for a result.");
        }

        $requeued = $this->requeueStalledPostbacks();
        if ($requeued > 0) {
            $this->warn("Re-queued {$requeued} received DataForSEO results that hadn't been recorded.");
        }

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
        $errors = 0;

        foreach ($pendingTasks as $task) {
            $taskId = (string) $task->external_task_id;
            $endpoint = (string) $task->endpoint;

            $getEndpoint = str_contains($endpoint, 'task_post')
                ? str_replace('task_post', 'task_get/advanced', $endpoint)
                : '/v3/serp/google/organic/task_get/advanced';

            try {
                $result = $client->getTaskResult($taskId, $getEndpoint);
            } catch (Throwable $e) {
                // Not "in progress": the check itself failed. The task stays
                // pending and is failed once it passes the maximum age.
                $errors++;
                $this->warn("Failed checking task [{$taskId}]: {$e->getMessage()}");

                continue;
            }

            /** @var array<string, mixed>|null $firstTask */
            $firstTask = $result['tasks'][0] ?? null;

            if (! is_array($firstTask)) {
                $inProgress++;

                continue;
            }

            $taskStatusCode = (int) ($firstTask['status_code'] ?? 20000);
            $taskResult = $firstTask['result'] ?? null;
            $statusMessage = strtolower((string) ($firstTask['status_message'] ?? ''));

            $isQueuedOrProcessing = in_array($taskStatusCode, [40601, 40602], true)
                || str_contains($statusMessage, 'progress')
                || str_contains($statusMessage, 'queue')
                || str_contains($statusMessage, 'handed');

            if ($isQueuedOrProcessing) {
                $inProgress++;

                continue;
            }

            if ($taskStatusCode >= 40000) {
                DB::table('dataforseo_tasks')
                    ->where('id', $task->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'failed',
                        'result_payload' => json_encode($result, JSON_THROW_ON_ERROR),
                        'error_message' => mb_substr(sprintf('Task failed with status %d: %s', $taskStatusCode, (string) ($firstTask['status_message'] ?? '')), 0, 1000),
                        'updated_at' => now(),
                    ]);

                $this->warn("Task [{$taskId}] failed with status code [{$taskStatusCode}]: {$firstTask['status_message']}");

                continue;
            }

            if ($taskStatusCode !== 20000 || empty($taskResult) || ! is_array($taskResult)) {
                $inProgress++;

                continue;
            }

            if (! app(RecordDataForSeoTaskResult::class)->handle((int) $task->id, $result)) {
                continue;
            }

            $collected++;
            $this->line("Task [{$taskId}] collected successfully.");
        }

        $this->info("Collection summary: {$collected} collected, {$inProgress} still in progress, {$errors} could not be checked.");

        return self::SUCCESS;
    }

    /**
     * Fail tasks that have waited past the maximum age (pending with no
     * result, or a stored postback that was never recorded), so the next
     * rank run tracks their keywords again instead of skipping them.
     */
    /**
     * Queue the recording job again for results the webhook stored but
     * that still aren't recorded, e.g. because queueing the job failed
     * after the claim committed. Recording is idempotent (it locks the task
     * and skips one already collected), so a job that was only slow does
     * no harm.
     */
    private function requeueStalledPostbacks(): int
    {
        $minutes = max(1, (int) config('coqui.dataforseo.postback_requeue_minutes', 15));

        $ids = DB::table('dataforseo_tasks')
            ->where('status', 'processing')
            ->whereNotNull('result_payload')
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->orderBy('updated_at')
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessDataForSeoPostbackJob::dispatch((int) $id);
        }

        return $ids->count();
    }

    private function failExpiredTasks(): int
    {
        $hours = max(1, (int) config('coqui.dataforseo.task_max_age_hours', 12));

        $cutoff = now()->subHours($hours);

        return DB::table('dataforseo_tasks')
            ->where(function ($query) use ($cutoff): void {
                // Waiting for a result since it was submitted...
                $query->where(fn ($q) => $q->where('status', 'pending')->where('submitted_at', '<=', $cutoff))
                    // ...or received but not recorded since the postback
                    // arrived (claiming it stamps updated_at), so a late
                    // result gets the full window to be recorded.
                    ->orWhere(fn ($q) => $q->where('status', 'processing')->where('updated_at', '<=', $cutoff));
            })
            ->update([
                'status' => 'failed',
                'error_message' => "No result after {$hours} hours.",
                'updated_at' => now(),
            ]);
    }
}
