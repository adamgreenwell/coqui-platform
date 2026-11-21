<?php

declare(strict_types=1);

namespace App\Domains\Serp\Actions;

use App\Domains\Serp\Models\TrackedKeyword;
use Illuminate\Support\Facades\DB;

/**
 * Records a standard-queue task's result exactly once: the task row is
 * locked and re-read, so a duplicate postback, the collector and a backup
 * check can't record the same task twice.
 */
final class RecordDataForSeoTaskResult
{
    public function __construct(private readonly RecordSerpRankSnapshots $recordSnapshots) {}

    /**
     * @param  array<string, mixed>  $result
     * @return bool Whether this call recorded it.
     */
    public function handle(int $taskId, array $result): bool
    {
        return DB::transaction(function () use ($taskId, $result): bool {
            $task = DB::table('dataforseo_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if ($task === null || in_array($task->status, ['collected', 'superseded'], true)) {
                return false;
            }

            if ($task->task_type === 'serp_rank_tracking' && is_string($task->request_context)) {
                /** @var array<string, mixed> $context */
                $context = json_decode($task->request_context, true) ?? [];
                $trackedKeywordId = isset($context['tracked_keyword_id']) ? (int) $context['tracked_keyword_id'] : null;

                if ($trackedKeywordId !== null) {
                    $trackedKeyword = TrackedKeyword::query()
                        ->whereHas('project')
                        ->with('project')
                        ->find($trackedKeywordId);

                    if ($trackedKeyword !== null && $trackedKeyword->project !== null) {
                        $this->recordSnapshots->handle($trackedKeyword, $result);
                    }
                }
            }

            DB::table('dataforseo_tasks')
                ->where('id', $taskId)
                ->update([
                    'status' => 'collected',
                    'result_payload' => json_encode($result, JSON_THROW_ON_ERROR),
                    'error_message' => null,
                    'collected_at' => now(),
                    'updated_at' => now(),
                ]);

            return true;
        });
    }
}
