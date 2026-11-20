<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Serp\Models\RankFallbackCheck;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\RankFallback\FetchFallbackRankJob;
use App\Domains\Serp\RankFallback\RankFallbackSpendGuard;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunRankFallbackCommand extends Command
{
    protected $signature = 'rank:fallback
                            {--now : Run the checks inline instead of queueing them}';

    protected $description = 'Re-check rankings with the backup SERP provider for keywords whose primary check failed or stalled.';

    public function handle(RankFallbackSpendGuard $guard): int
    {
        if (! $guard->enabled()) {
            $this->info('Skipped: rank fallback is off (SERPHOUSE_FALLBACK_ENABLED or SERPHOUSE_API_KEY not set).');

            return self::SUCCESS;
        }

        $created = $this->queueFailedAndStaleTasks();

        $pending = RankFallbackCheck::query()
            ->where('status', 'pending')
            ->whereDate('checked_on', today())
            ->orderBy('id')
            ->get();

        foreach ($pending as $check) {
            $check->update(['status' => 'queued']);

            if ($this->option('now')) {
                FetchFallbackRankJob::dispatchSync($check->id);
            } else {
                FetchFallbackRankJob::dispatch($check->id);
            }
        }

        // Submit errors from earlier days won't be retried.
        RankFallbackCheck::query()
            ->where('status', 'pending')
            ->whereDate('checked_on', '<', today())
            ->update(['status' => 'skipped', 'error_message' => 'Expired before it ran.', 'completed_at' => now()]);

        $this->info("Found {$created} new primary failure(s); queued {$pending->count()} fallback check(s).");

        return self::SUCCESS;
    }

    /**
     * Turn failed primary tasks, and ones pending past the stale threshold,
     * into fallback checks. Returns how many were created.
     */
    private function queueFailedAndStaleTasks(): int
    {
        $staleBefore = now()->subHours(max(1, (int) config('coqui.serphouse.stale_after_hours', 6)));

        $tasks = DB::table('dataforseo_tasks')
            ->where('task_type', 'serp_rank_tracking')
            ->where('submitted_at', '>=', now()->subDay())
            ->where(function ($query) use ($staleBefore): void {
                $query->where('status', 'failed')
                    ->orWhere(function ($pending) use ($staleBefore): void {
                        $pending->where('status', 'pending')->where('submitted_at', '<=', $staleBefore);
                    });
            })
            ->orderBy('submitted_at')
            ->get();

        $created = 0;

        foreach ($tasks as $task) {
            /** @var array<string, mixed> $context */
            $context = json_decode((string) $task->request_context, true) ?? [];
            $keywordId = isset($context['tracked_keyword_id']) ? (int) $context['tracked_keyword_id'] : null;
            if ($keywordId === null) {
                continue;
            }

            $keyword = TrackedKeyword::query()->whereHas('project')->with('project')->find($keywordId);
            $submittedAt = Carbon::parse((string) $task->submitted_at);

            if ($keyword === null || ! $keyword->is_active || $keyword->project === null) {
                continue;
            }

            // Already checked since this task was sent.
            if ($keyword->last_checked_at !== null && $keyword->last_checked_at->gte($submittedAt)) {
                continue;
            }

            $created += DB::table('rank_fallback_checks')->insertOrIgnore([
                'tracked_keyword_id' => $keyword->id,
                'project_id' => $keyword->project_id,
                'workspace_id' => $keyword->project->workspace_id,
                'dataforseo_task_id' => $task->id,
                'reason' => $task->status === 'failed' ? RankFallbackCheck::REASON_FAILED : RankFallbackCheck::REASON_STALE,
                'status' => 'pending',
                'checked_on' => today()->toDateString(),
                'reference_at' => $submittedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $created;
    }
}
