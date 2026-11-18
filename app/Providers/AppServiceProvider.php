<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Policies\ProjectPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Workspace::class, WorkspacePolicy::class);
    }
}
