<?php

declare(strict_types=1);

namespace App\Domains\Projects\Events;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectActivityLogged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ProjectActivity $activity) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(Project::broadcastChannelName($this->activity->project_id)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ProjectActivityLogged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->activity->id,
            'project_id' => $this->activity->project_id,
            'user_id' => $this->activity->user_id,
            'user_name' => $this->activity->user?->name,
            'event_type' => $this->activity->event_type,
            'title' => $this->activity->title,
            'description' => $this->activity->description,
            'properties' => $this->activity->properties,
            'created_at' => $this->activity->created_at->toIso8601String(),
        ];
    }
}
