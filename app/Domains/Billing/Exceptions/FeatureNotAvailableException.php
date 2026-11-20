<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use App\Domains\Billing\PlanFeature;
use App\Domains\Projects\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a workspace's plan does not include a feature.
 *
 * API and JSON callers get a 403 with the feature name and the billing URL.
 * Browser and Inertia requests are sent to the workspace billing page with an
 * upgrade message instead of an error page. Extending HttpException keeps it
 * out of the error log, like any other 403.
 */
class FeatureNotAvailableException extends HttpException
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly PlanFeature $feature,
    ) {
        parent::__construct(403, self::messageFor($workspace, $feature));
    }

    public static function messageFor(Workspace $workspace, PlanFeature $feature): string
    {
        return sprintf(
            '%s %s not included in the %s plan. Upgrade this workspace to unlock %s.',
            $feature->label(),
            str_ends_with($feature->label(), 's') ? 'are' : 'is',
            ucfirst($workspace->plan_tier),
            str_ends_with($feature->label(), 's') ? 'them' : 'it',
        );
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $billingUrl = route('workspaces.billing.index', $this->workspace);

        $wantsJson = $request->header('X-Inertia') === null
            && ($request->expectsJson() || $request->is('api/*'));

        if ($wantsJson) {
            return response()->json([
                'message' => $this->getMessage(),
                'feature' => $this->feature->value,
                'upgrade_url' => $billingUrl,
            ], 403);
        }

        return redirect()->to($billingUrl)->with('status', $this->getMessage());
    }
}
