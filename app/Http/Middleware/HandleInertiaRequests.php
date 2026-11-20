<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Billing\PlanFeature;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user !== null ? [
                    'id' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
            ],
            // The current workspace's plan features, so pages can lock gated
            // links and actions with an upgrade prompt instead of erroring.
            'plan' => fn (): ?array => $this->planFor($request, $user),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }

    /**
     * @return array{tier: string, features: array<string, bool>, billingUrl: string}|null
     */
    private function planFor(Request $request, ?User $user): ?array
    {
        $route = $request->route();
        if ($user === null || $route === null) {
            return null;
        }

        $project = $route->parameter('project');
        $workspace = $project instanceof Project ? $project->workspace : $route->parameter('workspace');

        if (! $workspace instanceof Workspace || $user->cannot('view', $workspace)) {
            return null;
        }

        return [
            'tier' => $workspace->plan_tier,
            'features' => PlanFeature::flagsFor($workspace),
            'billingUrl' => route('workspaces.billing.index', $workspace),
        ];
    }
}
