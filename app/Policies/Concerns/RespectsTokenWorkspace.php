<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;

/**
 * A request authenticated with a workspace-scoped API token may only act on
 * that workspace and its projects, whatever the user's other memberships.
 */
trait RespectsTokenWorkspace
{
    public function before(User $user, string $ability, mixed $subject = null): ?bool
    {
        $workspaceId = match (true) {
            $subject instanceof Workspace => (int) $subject->id,
            $subject instanceof Project => (int) $subject->workspace_id,
            default => null,
        };

        if ($workspaceId !== null && ! $user->tokenAllowsWorkspace($workspaceId)) {
            return false;
        }

        // Fall through to the ability's own check.
        return null;
    }
}
