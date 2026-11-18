<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('project.{projectId}', function (User $user, int|string $projectId): bool {
    $project = Project::query()->find((int) $projectId);
    if ($project === null) {
        return false;
    }

    return (bool) $user->can('view', $project);
});

Broadcast::channel('workspace.{workspaceId}', function (User $user, int|string $workspaceId): bool {
    $workspace = Workspace::query()->find((int) $workspaceId);
    if ($workspace === null) {
        return false;
    }

    return (bool) $user->can('view', $workspace);
});
