<?php

declare(strict_types=1);

namespace App\Domains\Serp\Events;

use App\Domains\Projects\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A content brief's generation started, finished or failed.
 */
class ContentBriefUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $projectId,
        public int $briefId,
        public string $keyword,
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
        return 'ContentBriefUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'brief_id' => $this->briefId,
            'keyword' => $this->keyword,
            'status' => $this->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
