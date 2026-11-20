<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

// Channels are keyed by public UUIDs, matching the route keys, so the browser
// never handles sequential IDs. Unknown or non-UUID keys are simply refused.
Broadcast::channel('project.{projectUuid}', function (?User $user, string $projectUuid): bool {
    if ($user === null || ! Str::isUuid($projectUuid)) {
        return false;
    }

    $project = Project::query()->where('uuid', $projectUuid)->first();
    if ($project === null) {
        return false;
    }

    return (bool) $user->can('view', $project);
});

Broadcast::channel('workspace.{workspaceUuid}', function (?User $user, string $workspaceUuid): bool {
    if ($user === null || ! Str::isUuid($workspaceUuid)) {
        return false;
    }

    $workspace = Workspace::query()->where('uuid', $workspaceUuid)->first();
    if ($workspace === null) {
        return false;
    }

    return (bool) $user->can('view', $workspace);
});
