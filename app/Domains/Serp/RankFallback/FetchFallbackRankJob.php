<?php

declare(strict_types=1);

namespace App\Domains\Serp\RankFallback;

use App\Domains\Serp\Actions\RecordSerpRankSnapshots;
use App\Domains\Serp\Models\RankFallbackCheck;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\Services\SerpResultParser;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks one keyword's rankings with the backup provider when the primary
 * check failed or stalled, and records them as that day's snapshots.
 */
class FetchFallbackRankJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Provider errors before the job gives up. Waiting on the rate limiter
     * doesn't count, so a backlog can wait its turn until retryUntil().
     */
    public int $maxExceptions = 3;

    /**
     * Below the queue's retry_after (90s), so a running check is never
     * handed to a second worker. Pages already fetched are kept on retry.
     */
    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $checkId) {}

    /**
     * Rate-limit releases count as attempts, so the job is bounded by time
     * instead of a try count.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // A check makes up to max_pages requests, so this keeps the request
        // rate under SERPHouse's 60 per minute.
        return [
            new RateLimited('rank-fallback'),
            // Never two runs of the same check at once.
            (new WithoutOverlapping("rank-fallback-check:{$this->checkId}"))->releaseAfter(30)->expireAfter(120),
        ];
    }

    public function handle(
        SerphouseClient $client,
        RankFallbackSpendGuard $guard,
        SerphouseResultAdapter $adapter,
        SerpResultParser $parser,
        RecordSerpRankSnapshots $recorder,
    ): void {
        $check = RankFallbackCheck::query()->find($this->checkId);
        if ($check === null || in_array($check->status, ['completed', 'skipped'], true)) {
            return;
        }

        $keyword = TrackedKeyword::query()
            ->whereHas('project')
            ->with(['project.workspace', 'project.competitors'])
            ->find($check->tracked_keyword_id);
        $project = $keyword?->project;
        $workspace = $project?->workspace;

        if ($keyword === null || ! $keyword->is_active || $project === null || $workspace === null) {
            $this->finish($check, 'skipped', 'Keyword or project no longer exists.');

            return;
        }

        if ($this->primaryArrived($keyword, $check)) {
            $this->finish($check, 'skipped', 'The primary result arrived first.');

            return;
        }

        $maxPages = max(1, (int) config('coqui.serphouse.max_pages', 10));
        $pageCost = (float) config('coqui.serphouse.page_cost_usd', 0.00075);
        $competitors = $project->competitors->pluck('domain')->all();

        // Pages saved by an earlier attempt aren't fetched or paid for again.
        $pages = $check->pages ?? [];
        $complete = fn (array $pages): bool => $this->searchComplete($pages, $maxPages, $adapter, $parser, $project->domain, $competitors);

        if (! $complete($pages)) {
            // Reserve the worst case for the remaining pages up front, so a
            // check never stops halfway and records a partial SERP as "not
            // ranking".
            try {
                $guard->reserve($check, $pageCost * ($maxPages - count($pages)), $workspace);
            } catch (RankFallbackBudgetException $e) {
                $this->finish($check, 'skipped', $e->getMessage());

                return;
            }
        }

        while (! $complete($pages)) {
            $pages[] = $client->googlePage($keyword->keyword, $keyword->language_code, $keyword->device, $keyword->location_code, count($pages) + 1);

            // Only successful requests are billed. The page is saved with the
            // charge, so a retry after a timeout resumes from the next one.
            $guard->recordCharge($check, $pageCost, ['pages' => $pages]);
        }

        $items = $adapter->toItems($pages);

        DB::transaction(function () use ($check, $keyword, $items, $recorder): void {
            // Lock the primary task first, the same order the primary
            // collectors use, so whichever records first wins and the other
            // sees it.
            if ($check->dataforseo_task_id !== null) {
                $task = DB::table('dataforseo_tasks')->where('id', $check->dataforseo_task_id)->lockForUpdate()->first();
                if ($task !== null && $task->status === 'collected') {
                    $this->finish($check, 'skipped', 'The primary result arrived while checking.');

                    return;
                }
            }

            $fresh = TrackedKeyword::query()->lockForUpdate()->find($keyword->id);
            if ($fresh === null || $this->primaryArrived($fresh, $check)) {
                $this->finish($check, 'skipped', 'The primary result arrived while checking.');

                return;
            }

            // Record under the day this check is for, even when a retry or
            // saved pages carry it past midnight, so it fills that day's gap
            // and never stands in for the next day's result.
            $recorder->handle($fresh, $items, $check->checked_on);

            // The recorder stamps last_checked_at with now. Date it to when
            // the scheduled check was sent instead, so a fallback that lands
            // hours later doesn't push the keyword out of the next daily batch.
            TrackedKeyword::query()->whereKey($fresh->id)->update(['last_checked_at' => $check->reference_at]);

            if ($check->dataforseo_task_id !== null) {
                // A late primary result must not overwrite this check.
                DB::table('dataforseo_tasks')
                    ->where('id', $check->dataforseo_task_id)
                    ->whereIn('status', ['pending', 'failed'])
                    ->update(['status' => 'superseded', 'updated_at' => now()]);
            }

            $this->finish($check, 'completed');
        });
    }

    public function failed(Throwable $e): void
    {
        $check = RankFallbackCheck::query()->find($this->checkId);
        if ($check !== null) {
            $this->finish($check, 'failed', $e->getMessage());
        }
    }

    /**
     * Whether the pages fetched so far are enough: the last page came back
     * empty, the project and every competitor have been found, or the page
     * limit is reached.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $competitors
     */
    private function searchComplete(array $pages, int $maxPages, SerphouseResultAdapter $adapter, SerpResultParser $parser, string $domain, array $competitors): bool
    {
        if ($pages === []) {
            return false;
        }

        if (count($pages) >= $maxPages || $adapter->organicCount($pages[count($pages) - 1]) === 0) {
            return true;
        }

        $found = $parser->parse($adapter->toItems($pages), $domain, $competitors)['domain_snapshots'];

        return collect($found)->every(fn (array $snapshot): bool => $snapshot['rank_group'] !== null);
    }

    private function primaryArrived(TrackedKeyword $keyword, RankFallbackCheck $check): bool
    {
        return $keyword->last_checked_at !== null && $keyword->last_checked_at->gte($check->reference_at);
    }

    private function finish(RankFallbackCheck $check, string $status, ?string $error = null): void
    {
        $check->update([
            'status' => $status,
            'error_message' => $error,
            'completed_at' => now(),
            // Release the hold; only what was paid counts from here.
            'reserved_usd' => 0,
        ]);
    }
}
