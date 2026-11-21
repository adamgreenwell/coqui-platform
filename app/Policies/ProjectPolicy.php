<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Projects\Models\Project;
use App\Models\User;
use App\Policies\Concerns\RespectsTokenWorkspace;

class ProjectPolicy
{
    use RespectsTokenWorkspace;

    public function view(User $user, Project $project): bool
    {
        return $this->belongsToWorkspace($user, $project);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->hasRoleInWorkspace($user, $project, ['owner', 'admin', 'editor']);
    }

    /**
     * Whether the user may trigger new paid provider lookups (DataForSEO / SE Ranking)
     * for this project. Read-only members get cached data only.
     */
    public function spend(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->hasRoleInWorkspace($user, $project, ['owner', 'admin']);
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

    /**
     * @param  list<string>  $roles
     */
    private function hasRoleInWorkspace(User $user, Project $project, array $roles): bool
    {
        $workspace = $project->workspace;
        if ($workspace === null) {
            return false;
        }

        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return $workspace->members()
            ->where('users.id', $user->id)
            ->whereIn('workspace_memberships.role', $roles)
            ->exists();
    }
}
