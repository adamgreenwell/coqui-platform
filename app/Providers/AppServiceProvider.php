<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use App\Models\PersonalAccessToken;
use App\Policies\ProjectPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;

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

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Laravel Pennant Plan Tier Feature Gating
        Feature::define('content-briefs', fn (Workspace $workspace): bool => in_array($workspace->plan_tier, ['pro', 'agency'], true));
        Feature::define('backlink-intelligence', fn (Workspace $workspace): bool => in_array($workspace->plan_tier, ['pro', 'agency'], true));
        Feature::define('traffic-attribution', fn (Workspace $workspace): bool => in_array($workspace->plan_tier, ['pro', 'agency'], true));
        Feature::define('white-label-reports', fn (Workspace $workspace): bool => $workspace->plan_tier === 'agency');
        Feature::define('priority-queue', fn (Workspace $workspace): bool => $workspace->plan_tier === 'agency');
        Feature::define('runnr-smoke-tests', fn (Workspace $workspace): bool => $workspace->hasRunnrEntitlement());
        Feature::define('bot-telemetry', fn (Workspace $workspace): bool => $workspace->hasRunnrEntitlement() || in_array($workspace->plan_tier, ['pro', 'agency'], true));

        // Global launch switch for the Runnr integration; off until activated.
        Feature::define(RunnrIntegration::class);
    }
}
