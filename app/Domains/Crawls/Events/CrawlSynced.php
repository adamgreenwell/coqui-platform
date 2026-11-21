<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Events;

use App\Domains\Projects\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A crawl run was finalized (sealed). Documented in
 * docs/desktop-sync-contract.md; Coqui Desktop listens for it.
 */
class CrawlSynced implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $projectId,
        public string $externalCrawlId,
        public string $status,
        public int $totalUrls,
        public int $errorCount,
        public int $warningCount,
        public int $opportunityCount,
        public string $source,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(Project::broadcastChannelName($this->projectId)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'CrawlSynced';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'external_crawl_id' => $this->externalCrawlId,
            'status' => $this->status,
            'total_urls' => $this->totalUrls,
            'error_count' => $this->errorCount,
            'warning_count' => $this->warningCount,
            'opportunity_count' => $this->opportunityCount,
            'source' => $this->source,
            'finalized_at' => now()->toIso8601String(),
        ];
    }
}
