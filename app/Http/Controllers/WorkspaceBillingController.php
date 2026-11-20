<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Billing\Exceptions\BillingUnavailableException;
use App\Domains\Billing\Models\BillingInvoice;
use App\Domains\Billing\Services\StripeBillingService;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WorkspaceBillingController extends Controller
{
    public function __construct(
        private readonly StripeBillingService $billingService,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        $runnrEnabled = RunnrIntegration::enabled();
        $plans = $this->billingService->getPlans();

        if (! $runnrEnabled) {
            $plans = $this->withoutRunnrOffers($plans);
        }
        $dfsSpend = $workspace->currentMonthDfsSpend();
        $dfsBudget = (float) $workspace->monthly_dfs_budget_usd;
        $keywordsCount = $workspace->trackedKeywordsCount();

        $invoices = $workspace->invoices()
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (BillingInvoice $inv): array => [
                'id' => $inv->id,
                'stripe_invoice_id' => $inv->stripe_invoice_id,
                'amount_paid_usd' => $inv->amount_paid_usd,
                'status' => $inv->status,
                'invoice_pdf_url' => $inv->invoice_pdf_url,
                'paid_at' => $inv->paid_at instanceof CarbonInterface ? $inv->paid_at->toIso8601String() : null,
                'created_at' => $inv->created_at->toIso8601String(),
            ])
            ->all();

        $workspaceProps = [
            'id' => $workspace->uuid,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'plan_tier' => $workspace->plan_tier,
            'subscription_status' => $workspace->subscription_status,
            'current_period_end' => $workspace->current_period_end instanceof CarbonInterface
                ? $workspace->current_period_end->toIso8601String()
                : null,
        ];

        $usage = [
            'keywords_count' => $keywordsCount,
            'keywords_limit' => $workspace->tracked_keyword_limit,
            'crawl_urls_limit' => $workspace->cloud_crawl_url_limit,
            'dfs_spend_usd' => round($dfsSpend, 4),
            'dfs_budget_usd' => $dfsBudget,
            'dfs_remaining_usd' => round(max(0.0, $dfsBudget - $dfsSpend), 4),
        ];

        if ($runnrEnabled) {
            $workspaceProps['has_runnr_addon'] = $workspace->has_runnr_addon;
            $usage['runnr_entitled'] = $workspace->hasRunnrEntitlement();
        }

        return Inertia::render('Workspaces/Billing', [
            'workspace' => $workspaceProps,
            'usage' => $usage,
            'plans' => $plans,
            'invoices' => $invoices,
            'canEdit' => $request->user()?->can('update', $workspace) ?? false,
            'billingMode' => $this->billingService->mode(),
        ]);
    }

    public function checkout(Request $request, Workspace $workspace): SymfonyResponse
    {
        Gate::authorize('update', $workspace);

        $validated = $request->validate([
            'plan_tier' => ['required', 'string', 'in:pro,agency,runnr_addon'],
        ]);

        $tier = (string) $validated['plan_tier'];
        $billingUrl = route('workspaces.billing.index', $workspace);

        if ($tier === 'runnr_addon' && ! RunnrIntegration::enabled()) {
            return redirect($billingUrl)->with('error', 'The Runnr hosting add-on is not available yet.');
        }

        try {
            $checkoutUrl = $this->billingService->createCheckoutSession($workspace, $tier, $billingUrl, $billingUrl);
        } catch (BillingUnavailableException $e) {
            return redirect($billingUrl)->with('error', $e->getMessage());
        }

        // Inertia::location makes the browser do a full visit, which an
        // external Stripe URL needs (an XHR redirect would be blocked by CORS).
        return Inertia::location($checkoutUrl);
    }

    public function portal(Request $request, Workspace $workspace): SymfonyResponse
    {
        Gate::authorize('update', $workspace);

        $billingUrl = route('workspaces.billing.index', $workspace);

        try {
            $portalUrl = $this->billingService->createCustomerPortalSession($workspace, $billingUrl);
        } catch (BillingUnavailableException $e) {
            return redirect($billingUrl)->with('error', $e->getMessage());
        }

        return Inertia::location($portalUrl);
    }

    /**
     * Drop the Runnr add-on plan and Runnr plan perks while the integration is hidden.
     *
     * @param  array<string, array{name: string, price_usd: int, tracked_keyword_limit: int, cloud_crawl_url_limit: int, monthly_dfs_budget_usd: float, features: list<string>}>  $plans
     * @return array<string, array{name: string, price_usd: int, tracked_keyword_limit: int, cloud_crawl_url_limit: int, monthly_dfs_budget_usd: float, features: list<string>}>
     */
    private function withoutRunnrOffers(array $plans): array
    {
        unset($plans['runnr_addon']);

        foreach ($plans as $tier => $plan) {
            $plan['features'] = array_values(array_filter(
                $plan['features'],
                fn (string $feature): bool => stripos($feature, 'runnr') === false,
            ));
            $plans[$tier] = $plan;
        }

        return $plans;
    }
}
