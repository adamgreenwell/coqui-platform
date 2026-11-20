<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Billing\Exceptions\FeatureNotAvailableException;
use App\Domains\Billing\PlanFeature;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `feature:<name>` that requires the route's workspace plan to
 * include a Pennant plan feature.
 *
 * The workspace comes from the route's project when there is one, never from a
 * workspace segment alone, so a paid workspace in the URL can't unlock a
 * project that belongs to a free workspace.
 */
class EnsureWorkspaceHasFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $planFeature = PlanFeature::from($feature);

        $project = $request->route('project');
        $routeWorkspace = $request->route('workspace');

        if ($project instanceof Project) {
            if ($routeWorkspace instanceof Workspace && $project->workspace_id !== $routeWorkspace->id) {
                abort(404);
            }
            /** @var Workspace $workspace */
            $workspace = $project->workspace;
            $subject = $project;
        } elseif ($routeWorkspace instanceof Workspace) {
            $workspace = $routeWorkspace;
            $subject = $routeWorkspace;
        } else {
            throw new LogicException("The feature:{$feature} middleware needs a {project} or {workspace} route parameter.");
        }

        if ($planFeature->activeFor($workspace)) {
            return $next($request);
        }

        // People outside the workspace get the same 403 the controller would
        // give them, rather than a billing page that reveals the plan.
        if (Gate::forUser($request->user())->denies('view', $subject)) {
            abort(403);
        }

        throw new FeatureNotAvailableException($workspace, $planFeature);
    }
}
