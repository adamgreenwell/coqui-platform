<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Events;

use App\Domains\Projects\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CrawlProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $projectId,
        public string $externalCrawlId,
        public int $crawledUrls,
        public int $totalUrls,
        public int $errorCount,
        public int $warningCount,
        public int $opportunityCount,
        public string $status,
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
        return 'CrawlProgressUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'external_crawl_id' => $this->externalCrawlId,
            'crawled_urls' => $this->crawledUrls,
            'total_urls' => $this->totalUrls,
            'error_count' => $this->errorCount,
            'warning_count' => $this->warningCount,
            'opportunity_count' => $this->opportunityCount,
            'status' => $this->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
