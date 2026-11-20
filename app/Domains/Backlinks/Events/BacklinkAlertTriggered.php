<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Events;

use App\Domains\Projects\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BacklinkAlertTriggered implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $projectId,
        public string $alertType,
        public string $severity,
        public string $message,
        public string $targetUrl,
        public array $meta = [],
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
        return 'BacklinkAlertTriggered';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'alert_type' => $this->alertType,
            'severity' => $this->severity,
            'message' => $this->message,
            'target_url' => $this->targetUrl,
            'meta' => $this->meta,
            'triggered_at' => now()->toIso8601String(),
        ];
    }
}
