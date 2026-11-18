<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Projects\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $this->belongsToWorkspace($user, $project);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->belongsToWorkspace($user, $project);
    }

    private function belongsToWorkspace(User $user, Project $project): bool
    {
        return $project->workspace()
            ->where(function ($query) use ($user): void {
                $query->where('owner_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members->where('users.id', $user->id));
            })
            ->exists();
    }
}
