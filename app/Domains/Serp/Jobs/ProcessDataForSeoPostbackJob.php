<?php

declare(strict_types=1);

namespace App\Domains\Serp\Jobs;

use App\Domains\Serp\Actions\RecordDataForSeoTaskResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records a postback that the webhook stored on the task (status
 * "processing"), off the request.
 */
class ProcessDataForSeoPostbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $taskId)
    {
        $this->onQueue('serp');
    }

    public function handle(RecordDataForSeoTaskResult $record): void
    {
        $task = DB::table('dataforseo_tasks')->where('id', $this->taskId)->first();
        if ($task === null || $task->status !== 'processing' || ! is_string($task->result_payload)) {
            return;
        }

        /** @var array<string, mixed>|null $result */
        $result = json_decode($task->result_payload, true);
        if (! is_array($result)) {
            $this->failed(null);

            return;
        }

        $record->handle($this->taskId, $result);
    }

    /**
     * Give up as failed, so the backup check and the next rank run pick the
     * keyword up again instead of it waiting on this task forever.
     */
    public function failed(?Throwable $e): void
    {
        DB::table('dataforseo_tasks')
            ->where('id', $this->taskId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'error_message' => $e !== null ? mb_substr('Recording the result failed: '.$e->getMessage(), 0, 1000) : 'The stored result could not be read.',
                'updated_at' => now(),
            ]);
    }
}
