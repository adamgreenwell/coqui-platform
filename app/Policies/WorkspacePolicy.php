<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Projects\Models\Workspace;
use App\Models\User;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace, ['owner', 'admin']);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_id === $user->id;
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace, ['owner', 'admin']);
    }

    public function createTokens(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace, ['owner', 'admin', 'editor']);
    }

    public function createProjects(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace, ['owner', 'admin', 'editor']);
    }

    private function isMember(User $user, Workspace $workspace): bool
    {
        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return $workspace->members()->where('users.id', $user->id)->exists();
    }

    /**
     * @param  list<string>  $roles
     */
    private function hasRole(User $user, Workspace $workspace, array $roles): bool
    {
        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return $workspace->members()
            ->where('users.id', $user->id)
            ->whereIn('workspace_memberships.role', $roles)
            ->exists();
    }
}
