<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Billing\Models\BillingInvoice;
use App\Domains\Billing\Services\StripeBillingService;
use App\Domains\Projects\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceBillingController extends Controller
{
    public function __construct(
        private readonly StripeBillingService $billingService,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        $plans = $this->billingService->getPlans();
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

        return Inertia::render('Workspaces/Billing', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'plan_tier' => $workspace->plan_tier,
                'has_runnr_addon' => $workspace->has_runnr_addon,
                'subscription_status' => $workspace->subscription_status,
                'current_period_end' => $workspace->current_period_end instanceof CarbonInterface
                    ? $workspace->current_period_end->toIso8601String()
                    : null,
            ],
            'usage' => [
                'keywords_count' => $keywordsCount,
                'keywords_limit' => $workspace->tracked_keyword_limit,
                'crawl_urls_limit' => $workspace->cloud_crawl_url_limit,
                'dfs_spend_usd' => round($dfsSpend, 4),
                'dfs_budget_usd' => $dfsBudget,
                'dfs_remaining_usd' => round(max(0.0, $dfsBudget - $dfsSpend), 4),
                'runnr_entitled' => $workspace->hasRunnrEntitlement(),
            ],
            'plans' => $plans,
            'invoices' => $invoices,
            'canEdit' => $request->user()?->can('update', $workspace) ?? false,
        ]);
    }

    public function checkout(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        $validated = $request->validate([
            'plan_tier' => ['required', 'string', 'in:pro,agency,runnr_addon'],
        ]);

        $tier = (string) $validated['plan_tier'];
        $successUrl = route('workspaces.billing.index', $workspace);
        $cancelUrl = route('workspaces.billing.index', $workspace);

        $checkoutUrl = $this->billingService->createCheckoutSession(
            $workspace,
            $tier,
            $successUrl,
            $cancelUrl,
        );

        return redirect()->away($checkoutUrl);
    }

    public function portal(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        $returnUrl = route('workspaces.billing.index', $workspace);
        $portalUrl = $this->billingService->createCustomerPortalSession($workspace, $returnUrl);

        return redirect()->away($portalUrl);
    }

    public function changeTier(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        $validated = $request->validate([
            'plan_tier' => ['required', 'string', 'in:free,pro,agency'],
            'has_runnr_addon' => ['nullable', 'boolean'],
        ]);

        $tier = (string) $validated['plan_tier'];
        $hasRunnrAddon = (bool) ($validated['has_runnr_addon'] ?? false);

        $workspace->applyPlanTier($tier, $hasRunnrAddon);

        return redirect()
            ->route('workspaces.billing.index', $workspace)
            ->with('success', "Workspace plan updated to {$tier}. Quotas and feature entitlements have been recalibrated.");
    }
}
