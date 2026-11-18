<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('project.{projectId}', function (User $user, int $projectId): bool {
    $project = Project::query()->find($projectId);
    if ($project === null) {
        return false;
    }

    return $user->can('view', $project);
});
