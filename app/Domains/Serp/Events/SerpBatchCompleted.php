<?php

declare(strict_types=1);

namespace App\Domains\Serp\Events;

use App\Domains\Projects\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SerpBatchCompleted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $projectId,
        public string $batchId,
        public int $keywordCount,
        public int $updatedCount,
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
        return 'SerpBatchCompleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'batch_id' => $this->batchId,
            'keyword_count' => $this->keywordCount,
            'updated_count' => $this->updatedCount,
            'status' => $this->status,
            'completed_at' => now()->toIso8601String(),
        ];
    }
}
