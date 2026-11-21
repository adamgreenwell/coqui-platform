<?php

declare(strict_types=1);

namespace App\Domains\Serp\Jobs;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Serp\Events\ContentBriefUpdated;
use App\Domains\Serp\Models\ContentBrief;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Domains\Serp\Services\ContentBriefService;
use App\Domains\Serp\SpendRefusedException;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds a content brief off the web request: the SERP lookup plus fetching
 * every competitor page can take longer than a request should wait.
 */
class GenerateContentBriefJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'serp';

    /**
     * One attempt: the SERP lookup is paid, and the user sees a failure
     * they can retry.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly ContentBrief $brief,
        public readonly ?User $user = null,
        public readonly bool $bypassCache = false,
        public readonly ?string $generation = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ContentBriefService $service): void
    {
        $brief = $this->brief;
        /** @var Project $project */
        $project = $brief->project;

        // A newer request replaced this one while it waited in the queue:
        // that job builds the brief, so don't pay for a second lookup.
        if (! $this->claim()) {
            return;
        }

        try {
            // The project's current market and language, so a refresh after
            // the project's targeting changed doesn't buy the old market's SERP.
            $brief = $service->buildBrief($project, $brief->keyword, bypassCache: $this->bypassCache);
        } catch (Throwable $e) {
            if (! $this->superseded()) {
                $this->markFailed($brief, $e);
            }

            return;
        }

        ProjectActivity::log(
            project: $project,
            eventType: 'content_brief.generated',
            title: sprintf('Content brief generated for "%s"', Str::limit($brief->keyword, 50)),
            description: sprintf('Generated SERP content brief for "%s" with recommendations and competitor analysis.', $brief->keyword),
            properties: [
                'keyword' => $brief->keyword,
                'search_volume' => $brief->search_volume,
                'recommended_words_min' => $brief->recommended_words_min,
                'recommended_words_max' => $brief->recommended_words_max,
                'incomplete' => (bool) ($brief->data['incomplete'] ?? false),
            ],
            user: $this->user,
        );

        $this->broadcast($brief);
    }

    public function failed(?Throwable $e): void
    {
        // The worker died or timed out: don't leave the brief pending,
        // unless a newer job now owns it.
        if ($this->superseded()) {
            return;
        }
        $this->markFailed($this->brief->fresh() ?? $this->brief, $e);
    }

    /**
     * Take ownership of the brief: refresh its pending timestamp if this job
     * still holds the current generation. The controller treats a pending
     * brief touched in the last five minutes as in flight, and this job's
     * timeout is far shorter, so a running job can't be replaced. Uses the
     * controller's lock so the check and the replacement can't interleave.
     */
    private function claim(): bool
    {
        if ($this->generation === null) {
            return true;
        }

        try {
            return (bool) Cache::lock(self::lockKey($this->brief), 10)->block(5, fn (): bool => ContentBrief::query()
                ->whereKey($this->brief->getKey())
                ->where('generation', $this->generation)
                ->update(['updated_at' => now()]) === 1);
        } catch (LockTimeoutException) {
            // Someone else is changing this brief; leave it to them.
            return false;
        }
    }

    public static function lockKey(ContentBrief $brief): string
    {
        return "content-brief:{$brief->project_id}:".md5($brief->keyword);
    }

    private function superseded(): bool
    {
        if ($this->generation === null) {
            return false;
        }
        $current = ContentBrief::query()->whereKey($this->brief->getKey())->value('generation');

        return $current !== $this->generation;
    }

    private function markFailed(ContentBrief $brief, ?Throwable $e): void
    {
        // These carry a message written for the user (missing provider
        // credentials, a spend cap or budget); anything else stays generic.
        $knownMessage = $e instanceof ProviderNotConfiguredException || $e instanceof SpendRefusedException;

        if ($e !== null && ! $knownMessage) {
            Log::warning('Content brief generation failed', [
                'brief_id' => $brief->id,
                'error' => $e->getMessage(),
            ]);
        }

        $brief->update([
            'status' => ContentBrief::STATUS_FAILED,
            'error' => $knownMessage
                ? Str::limit($e->getMessage(), 500)
                : 'The brief could not be generated. Please try again.',
        ]);

        $this->broadcast($brief);
    }

    private function broadcast(ContentBrief $brief): void
    {
        rescue(fn () => broadcast(new ContentBriefUpdated(
            projectId: $brief->project_id,
            briefId: $brief->id,
            keyword: $brief->keyword,
            status: $brief->status,
        )), report: true);
    }
}
