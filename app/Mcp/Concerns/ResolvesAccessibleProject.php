<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Domains\Projects\Models\Project;
use App\Models\User;

trait ResolvesAccessibleProject
{
    /**
     * Find a project by domain among those the user may access.
     *
     * Projects in other workspaces are treated as missing so callers cannot
     * probe which domains exist elsewhere on the platform.
     */
    protected function resolveAccessibleProject(User $user, string $normalizedDomain, string $ability = 'view'): ?Project
    {
        return Project::query()
            ->where('domain', $normalizedDomain)
            ->orderBy('id')
            ->get()
            ->first(fn (Project $project): bool => $user->can($ability, $project));
    }
}
