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

    public int $timeout = 180;

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
        return [new RateLimited('rank-fallback')];
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

        // Reserve the worst case up front, so a check never stops halfway
        // and records a partial SERP as "not ranking".
        try {
            $guard->reserve($check, $pageCost * $maxPages, $workspace);
        } catch (RankFallbackBudgetException $e) {
            $this->finish($check, 'skipped', $e->getMessage());

            return;
        }

        $competitors = $project->competitors->pluck('domain')->all();
        $pages = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $client->googlePage($keyword->keyword, $keyword->language_code, $keyword->device, $keyword->location_code, $page);
            $pages[] = $response;

            // Only successful requests are billed.
            $check->increment('pages_fetched');
            $check->increment('cost_usd', $pageCost);

            if ($adapter->organicCount($response) === 0) {
                break;
            }

            // Stop once the project and every competitor have been found.
            $found = $parser->parse($adapter->toItems($pages), $project->domain, $competitors)['domain_snapshots'];
            if (collect($found)->every(fn (array $snapshot): bool => $snapshot['rank_group'] !== null)) {
                break;
            }
        }

        $items = $adapter->toItems($pages);

        DB::transaction(function () use ($check, $keyword, $items, $recorder): void {
            $fresh = TrackedKeyword::query()->lockForUpdate()->find($keyword->id);
            if ($fresh === null || $this->primaryArrived($fresh, $check)) {
                $this->finish($check, 'skipped', 'The primary result arrived while checking.');

                return;
            }

            $recorder->handle($fresh, $items);

            if ($check->dataforseo_task_id !== null) {
                // A late primary result must not overwrite this check.
                DB::table('dataforseo_tasks')
                    ->where('id', $check->dataforseo_task_id)
                    ->where('status', 'pending')
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
