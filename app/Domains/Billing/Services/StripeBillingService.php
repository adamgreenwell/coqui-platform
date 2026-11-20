<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Exceptions\BillingUnavailableException;
use App\Domains\Billing\Models\BillingInvoice;
use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Projects\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StripeBillingService
{
    /**
     * Plan specifications and quotas.
     *
     * @return array<string, array{
     *     name: string,
     *     price_usd: int,
     *     tracked_keyword_limit: int,
     *     cloud_crawl_url_limit: int,
     *     monthly_dfs_budget_usd: float,
     *     features: list<string>
     * }>
     */
    public function getPlans(): array
    {
        return [
            'free' => [
                'name' => 'Free / Desktop Sync',
                'price_usd' => 0,
                'tracked_keyword_limit' => 100,
                'cloud_crawl_url_limit' => 1000,
                'monthly_dfs_budget_usd' => 25.00,
                'features' => [
                    'Desktop Crawl Snapshot Sync (Rust engine)',
                    '100 Tracked Keywords & Visibility Scoring',
                    '1,000 Cloud Crawl URLs per audit',
                    '$25/mo DataForSEO API Spend Allowance',
                    'Basic Technical Issue Inspection',
                ],
            ],
            'pro' => [
                'name' => 'Pro Cloud',
                'price_usd' => 49,
                'tracked_keyword_limit' => 1000,
                'cloud_crawl_url_limit' => 25000,
                'monthly_dfs_budget_usd' => 150.00,
                'features' => [
                    'Everything in Free',
                    '1,000 Tracked Keywords with Daily Updates',
                    '25,000 Cloud Crawl URLs per audit',
                    '$150/mo DataForSEO API Spend Allowance',
                    'Live SERP Top-10 Content Brief Generator',
                    'Keyword Gap & Dual-Competitor Matrix',
                    'Backlink Profile Explorer & 404 Recovery',
                    'Google Search Console & Bing Performance Overlay',
                    'GA4 & Plausible Organic & AI Traffic Attribution',
                ],
            ],
            'agency' => [
                'name' => 'Agency & Enterprise',
                'price_usd' => 199,
                'tracked_keyword_limit' => 10000,
                'cloud_crawl_url_limit' => 250000,
                'monthly_dfs_budget_usd' => 750.00,
                'features' => [
                    'Everything in Pro',
                    '10,000 Tracked Keywords with Hourly Alerts',
                    '250,000 Cloud Crawl URLs per audit',
                    '$750/mo DataForSEO API Spend Allowance',
                    'Unlimited Workspace Team Members',
                    'White-Label Client Crawl Diff Reports',
                    'Priority Cloud Queue & Worker Allocation',
                    'Native Runnr Deploy Smoke Tests & Bot Telemetry Included',
                ],
            ],
            'runnr_addon' => [
                'name' => 'Runnr Hosting Add-on',
                'price_usd' => 29,
                'tracked_keyword_limit' => 0,
                'cloud_crawl_url_limit' => 0,
                'monthly_dfs_budget_usd' => 0.00,
                'features' => [
                    'Automated Post-Deploy SEO Smoke Testing',
                    'Bot & AI Crawler Telemetry Ingestion (Googlebot, GPTBot, ClaudeBot, PerplexityBot)',
                    'Automated 301 Redirect Remediation for Dead Bot Hits',
                    'Embeddable Live Smoke Badge for GitHub CI/CD',
                ],
            ],
        ];
    }

    public const MODE_STRIPE = 'stripe';

    public const MODE_MOCK = 'mock';

    public const MODE_UNCONFIGURED = 'unconfigured';

    /**
     * Subscription statuses that keep a plan or add-on usable.
     */
    private const ENTITLED_STATUSES = ['active', 'trialing', 'past_due'];

    /**
     * How billing actions are served:
     * - stripe: a real secret key is set, so Checkout and the portal hit Stripe.
     * - mock: no key (or a mock_ key) in local/testing, so actions return fake
     *   session URLs for UI development.
     * - unconfigured: no key anywhere else, so actions refuse with a clear error.
     */
    public function mode(): string
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret !== '' && ! str_starts_with($secret, 'mock_')) {
            return self::MODE_STRIPE;
        }

        return app()->environment('local', 'testing') ? self::MODE_MOCK : self::MODE_UNCONFIGURED;
    }

    /**
     * Create Stripe Checkout session for plan upgrade.
     *
     * @throws BillingUnavailableException
     */
    public function createCheckoutSession(
        Workspace $workspace,
        string $planTier,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $mode = $this->mode();

        if ($mode === self::MODE_MOCK) {
            return $successUrl.'?session_id=cs_mock_'.bin2hex(random_bytes(8)).'&plan='.$planTier;
        }

        if ($mode === self::MODE_UNCONFIGURED) {
            Log::error('Stripe checkout requested but STRIPE_SECRET is not set.', ['workspace_id' => $workspace->id]);

            throw new BillingUnavailableException('Online payments are not set up yet, so plans cannot be purchased right now. Please contact support.');
        }

        if ($planTier !== 'runnr_addon' && $this->hasActiveSubscription($workspace, 'default')) {
            throw new BillingUnavailableException('This workspace already has a paid plan. Switch plans from the Stripe customer portal so you are not billed twice.');
        }

        $priceId = (string) config("services.stripe.prices.{$planTier}");
        if (! str_starts_with($priceId, 'price_')) {
            Log::error("Stripe checkout requested for {$planTier} but its price ID is missing or invalid.", [
                'workspace_id' => $workspace->id,
                'env' => 'STRIPE_PRICE_'.strtoupper($planTier),
            ]);

            throw new BillingUnavailableException('This plan is not available for purchase yet. Please contact support.');
        }

        $secret = (string) config('services.stripe.secret');
        $baseUrl = (string) config('services.stripe.base_url', 'https://api.stripe.com');

        $params = [
            'mode' => 'subscription',
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}&plan='.$planTier,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $workspace->id,
            'metadata' => [
                'workspace_id' => (string) $workspace->id,
                'plan_tier' => $planTier,
            ],
            // Copied onto the subscription so later subscription webhooks can
            // be tied back to the workspace and plan.
            'subscription_data' => [
                'metadata' => [
                    'workspace_id' => (string) $workspace->id,
                    'plan_tier' => $planTier,
                ],
            ],
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
        ];

        if ($workspace->stripe_id !== null && $workspace->stripe_id !== '') {
            $params['customer'] = $workspace->stripe_id;
        } else {
            $owner = $workspace->owner;
            if ($owner !== null) {
                $params['customer_email'] = $owner->email;
            }
        }

        return $this->postForSessionUrl('/v1/checkout/sessions', $params, 'checkout', $workspace);
    }

    /**
     * Create Stripe Customer Billing Portal session for subscription self-management.
     *
     * @throws BillingUnavailableException
     */
    public function createCustomerPortalSession(Workspace $workspace, string $returnUrl): string
    {
        $mode = $this->mode();

        if ($mode === self::MODE_MOCK) {
            return $returnUrl.'?portal_mock=1';
        }

        if ($mode === self::MODE_UNCONFIGURED) {
            Log::error('Stripe portal requested but STRIPE_SECRET is not set.', ['workspace_id' => $workspace->id]);

            throw new BillingUnavailableException('Online payments are not set up yet, so the billing portal is unavailable. Please contact support.');
        }

        if ($workspace->stripe_id === null || $workspace->stripe_id === '') {
            throw new BillingUnavailableException('This workspace has no Stripe billing account yet. Choose a paid plan first.');
        }

        return $this->postForSessionUrl('/v1/billing_portal/sessions', [
            'customer' => $workspace->stripe_id,
            'return_url' => $returnUrl,
        ], 'portal', $workspace);
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws BillingUnavailableException
     */
    private function postForSessionUrl(string $path, array $params, string $kind, Workspace $workspace): string
    {
        $secret = (string) config('services.stripe.secret');
        $baseUrl = (string) config('services.stripe.base_url', 'https://api.stripe.com');

        try {
            $response = Http::asForm()
                ->withToken($secret)
                ->timeout(20)
                ->post(rtrim($baseUrl, '/').$path, $params);
        } catch (ConnectionException $e) {
            Log::error("Stripe {$kind} session request could not connect.", [
                'workspace_id' => $workspace->id,
                'error' => $e->getMessage(),
            ]);

            throw new BillingUnavailableException('Stripe could not be reached. Please try again in a moment.', previous: $e);
        }

        $url = $response->json('url');

        if (! $response->successful() || ! is_string($url) || $url === '') {
            Log::error("Stripe {$kind} session creation failed.", [
                'workspace_id' => $workspace->id,
                'status' => $response->status(),
                'stripe_error' => $response->json('error.message'),
                'stripe_error_code' => $response->json('error.code'),
            ]);

            throw new BillingUnavailableException('Stripe could not start a billing session. Please try again, or contact support if this keeps happening.');
        }

        return $url;
    }

    /**
     * Handle incoming Stripe webhook event payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, event: string, message: string}
     */
    public function handleWebhookEvent(array $payload): array
    {
        $eventType = (string) ($payload['type'] ?? '');
        /** @var array<string, mixed> $dataObj */
        $dataObj = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        switch ($eventType) {
            case 'checkout.session.completed':
                return $this->handleCheckoutSessionCompleted($dataObj);

            case 'customer.subscription.updated':
                return $this->handleSubscriptionUpdated($dataObj);

            case 'customer.subscription.deleted':
                return $this->handleSubscriptionDeleted($dataObj);

            case 'invoice.payment_succeeded':
                return $this->handleInvoicePaymentSucceeded($dataObj);

            case 'invoice.payment_failed':
                return $this->handleInvoicePaymentFailed($dataObj);

            default:
                return [
                    'success' => true,
                    'event' => $eventType,
                    'message' => 'Ignored unhandled event type.',
                ];
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{success: bool, event: string, message: string}
     */
    private function handleCheckoutSessionCompleted(array $session): array
    {
        $meta = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $workspaceId = (int) ($meta['workspace_id'] ?? $session['client_reference_id'] ?? 0);
        $planTier = (string) ($meta['plan_tier'] ?? 'pro');

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            return [
                'success' => false,
                'event' => 'checkout.session.completed',
                'message' => "Workspace {$workspaceId} not found.",
            ];
        }

        $stripeCustomerId = isset($session['customer']) ? (string) $session['customer'] : null;
        $stripeSubId = isset($session['subscription']) ? (string) $session['subscription'] : null;

        // A checkout event can arrive late (Stripe retries after an outage) or
        // be resent from the dashboard. Once the subscription is known, its own
        // events own the plan: the checkout metadata still names the plan
        // bought at checkout, so applying it again would undo a later portal
        // switch or a cancellation.
        $local = $this->localSubscription((string) $stripeSubId);
        if ($local !== null) {
            if (($workspace->stripe_id === null || $workspace->stripe_id === '') && $stripeCustomerId !== null) {
                $workspace->stripe_id = $stripeCustomerId;
                $workspace->save();
            }

            Log::info("Ignored repeated checkout for workspace {$workspace->id}: subscription {$stripeSubId} is already synced.");

            return [
                'success' => true,
                'event' => 'checkout.session.completed',
                'message' => "Subscription {$stripeSubId} already synced; plan left unchanged.",
            ];
        }

        $workspace->stripe_id = $stripeCustomerId;

        $isRunnrAddon = $planTier === 'runnr_addon';
        if (! $isRunnrAddon) {
            // The workspace columns track the base plan subscription only; an
            // add-on purchase must not overwrite them.
            $workspace->stripe_subscription_id = $stripeSubId;
            $workspace->subscription_status = 'active';
        }

        if ($isRunnrAddon) {
            $workspace->has_runnr_addon = true;
            $workspace->save();
        } else {
            $workspace->applyPlanTier($planTier);
        }

        if ($stripeSubId !== null) {
            WorkspaceSubscription::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'stripe_id' => $stripeSubId,
                ],
                [
                    'type' => $isRunnrAddon ? 'runnr_addon' : 'default',
                    'stripe_status' => 'active',
                    'stripe_price' => $isRunnrAddon ? 'runnr_addon' : $planTier,
                    'quantity' => 1,
                ]
            );
        }

        Log::info("Workspace {$workspace->id} upgraded to plan {$planTier} via Stripe checkout.");

        return [
            'success' => true,
            'event' => 'checkout.session.completed',
            'message' => "Workspace {$workspace->id} plan updated to {$planTier}.",
        ];
    }

    /**
     * @param  array<string, mixed>  $sub
     * @return array{success: bool, event: string, message: string}
     */
    private function handleSubscriptionUpdated(array $sub): array
    {
        $subId = (string) ($sub['id'] ?? '');
        $status = (string) ($sub['status'] ?? 'active');

        $local = $this->localSubscription($subId);
        $workspace = $this->workspaceForSubscription($sub, $local);

        if ($workspace === null) {
            return [
                'success' => true,
                'event' => 'customer.subscription.updated',
                'message' => "Subscription {$subId} does not belong to a known workspace.",
            ];
        }

        $planTier = $this->planTierForSubscription($sub, $local);
        $isRunnrAddon = $planTier === 'runnr_addon' || $local?->type === 'runnr_addon';
        $cancelAt = data_get($sub, 'cancel_at');

        if ($local !== null || $planTier !== null) {
            WorkspaceSubscription::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'stripe_id' => $subId,
                ],
                [
                    'type' => $isRunnrAddon ? 'runnr_addon' : 'default',
                    'stripe_status' => $status,
                    'stripe_price' => $planTier ?? $local->stripe_price,
                    'quantity' => 1,
                    'ends_at' => is_numeric($cancelAt) ? Carbon::createFromTimestamp((int) $cancelAt) : null,
                ]
            );
        }

        if ($isRunnrAddon) {
            if (in_array($status, self::ENTITLED_STATUSES, true) && ! $workspace->has_runnr_addon) {
                $workspace->update(['has_runnr_addon' => true]);
            }

            return [
                'success' => true,
                'event' => 'customer.subscription.updated',
                'message' => "Runnr add-on subscription {$subId} updated to {$status}.",
            ];
        }

        $workspace->update([
            'stripe_subscription_id' => $subId,
            'subscription_status' => $status,
            'current_period_end' => $this->currentPeriodEnd($sub),
        ]);

        // A plan switch in the customer portal changes the subscription's
        // price, so the price decides the plan. Losing access is left to
        // customer.subscription.deleted.
        if (in_array($planTier, ['pro', 'agency'], true)
            && in_array($status, self::ENTITLED_STATUSES, true)
            && $workspace->plan_tier !== $planTier) {
            $workspace->applyPlanTier($planTier);
            Log::info("Workspace {$workspace->id} switched to plan {$planTier} via Stripe subscription {$subId}.");
        }

        return [
            'success' => true,
            'event' => 'customer.subscription.updated',
            'message' => "Subscription {$subId} updated to {$status}.",
        ];
    }

    /**
     * @param  array<string, mixed>  $sub
     * @return array{success: bool, event: string, message: string}
     */
    private function handleSubscriptionDeleted(array $sub): array
    {
        $subId = (string) ($sub['id'] ?? '');

        $local = $this->localSubscription($subId);
        $workspace = $this->workspaceForSubscription($sub, $local);

        if ($workspace === null) {
            return [
                'success' => true,
                'event' => 'customer.subscription.deleted',
                'message' => "Subscription {$subId} does not belong to a known workspace.",
            ];
        }

        WorkspaceSubscription::query()->where('stripe_id', $subId)->update([
            'stripe_status' => 'canceled',
            'ends_at' => Carbon::now(),
        ]);

        $planTier = $this->planTierForSubscription($sub, $local);
        $keepsRunnrAddon = $this->hasActiveSubscription($workspace, 'runnr_addon');

        if ($planTier === 'runnr_addon' || $local?->type === 'runnr_addon') {
            // Only the add-on ended; the base plan is untouched.
            $workspace->update(['has_runnr_addon' => $keepsRunnrAddon]);

            Log::info("Runnr add-on subscription {$subId} for workspace {$workspace->id} cancelled.");

            return [
                'success' => true,
                'event' => 'customer.subscription.deleted',
                'message' => "Runnr add-on subscription {$subId} cancelled.",
            ];
        }

        if ($this->hasActiveSubscription($workspace, 'default')) {
            return [
                'success' => true,
                'event' => 'customer.subscription.deleted',
                'message' => "Subscription {$subId} cancelled; another plan subscription is still active.",
            ];
        }

        $workspace->applyPlanTier('free');
        $workspace->update([
            'subscription_status' => 'canceled',
            'has_runnr_addon' => $keepsRunnrAddon,
        ]);

        Log::info("Workspace {$workspace->id} downgraded to Free tier after subscription cancellation.");

        return [
            'success' => true,
            'event' => 'customer.subscription.deleted',
            'message' => "Subscription {$subId} cancelled; workspace reset to Free.",
        ];
    }

    private function localSubscription(string $stripeSubscriptionId): ?WorkspaceSubscription
    {
        if ($stripeSubscriptionId === '') {
            return null;
        }

        return WorkspaceSubscription::query()->where('stripe_id', $stripeSubscriptionId)->first();
    }

    /**
     * @param  array<string, mixed>  $sub
     */
    private function workspaceForSubscription(array $sub, ?WorkspaceSubscription $local): ?Workspace
    {
        $workspace = $local?->workspace;
        if ($workspace !== null) {
            return $workspace;
        }

        $subId = (string) ($sub['id'] ?? '');
        $customerId = (string) ($sub['customer'] ?? '');
        $metaWorkspaceId = data_get($sub, 'metadata.workspace_id');

        if ($subId !== '') {
            $workspace = Workspace::query()->where('stripe_subscription_id', $subId)->first();
            if ($workspace !== null) {
                return $workspace;
            }
        }

        if ($customerId === '') {
            return null;
        }

        $byCustomer = Workspace::query()->where('stripe_id', $customerId);

        if (is_numeric($metaWorkspaceId)) {
            $byCustomer->whereKey((int) $metaWorkspaceId);
        }

        return $byCustomer->first();
    }

    /**
     * Which workspace an invoice belongs to.
     *
     * The first invoice of a new subscription usually arrives before
     * checkout.session.completed has stored the Stripe customer on the
     * workspace, so the customer ID alone misses it. The subscription
     * metadata written at checkout carries the workspace ID.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function workspaceForInvoice(array $invoice): ?Workspace
    {
        // Newer API versions nest the subscription under parent.subscription_details.
        $subId = data_get($invoice, 'parent.subscription_details.subscription') ?? data_get($invoice, 'subscription');
        $subId = is_string($subId) ? $subId : '';

        $workspace = $this->localSubscription($subId)?->workspace;
        if ($workspace !== null) {
            return $workspace;
        }

        if ($subId !== '') {
            $workspace = Workspace::query()->where('stripe_subscription_id', $subId)->first();
            if ($workspace !== null) {
                return $workspace;
            }
        }

        $customerId = (string) ($invoice['customer'] ?? '');
        if ($customerId !== '') {
            $workspace = Workspace::query()->where('stripe_id', $customerId)->first();
            if ($workspace !== null) {
                return $workspace;
            }
        }

        $metaWorkspaceId = data_get($invoice, 'parent.subscription_details.metadata.workspace_id')
            ?? data_get($invoice, 'subscription_details.metadata.workspace_id');
        if (! is_numeric($metaWorkspaceId)) {
            return null;
        }

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->find((int) $metaWorkspaceId);

        // A workspace already tied to a different Stripe customer is not this one.
        if ($workspace === null || ($workspace->stripe_id !== null && $workspace->stripe_id !== '' && $workspace->stripe_id !== $customerId)) {
            return null;
        }

        return $workspace;
    }

    /**
     * Which plan a Stripe subscription is for. The configured price IDs win,
     * because a portal plan switch changes the price but not the metadata.
     *
     * @param  array<string, mixed>  $sub
     */
    private function planTierForSubscription(array $sub, ?WorkspaceSubscription $local): ?string
    {
        $tiersByPrice = [];
        foreach ((array) config('services.stripe.prices', []) as $tier => $priceId) {
            if (is_string($priceId) && $priceId !== '') {
                $tiersByPrice[$priceId] = (string) $tier;
            }
        }

        $items = data_get($sub, 'items.data');
        if (is_array($items)) {
            foreach ($items as $item) {
                $priceId = data_get($item, 'price.id');
                if (is_string($priceId) && isset($tiersByPrice[$priceId])) {
                    return $tiersByPrice[$priceId];
                }
            }
        }

        $paidTiers = ['pro', 'agency', 'runnr_addon'];

        $metaTier = data_get($sub, 'metadata.plan_tier');
        if (is_string($metaTier) && in_array($metaTier, $paidTiers, true)) {
            return $metaTier;
        }

        $localTier = $local?->stripe_price;

        return in_array($localTier, $paidTiers, true) ? $localTier : null;
    }

    /**
     * Newer Stripe API versions moved current_period_end onto the items.
     *
     * @param  array<string, mixed>  $sub
     */
    private function currentPeriodEnd(array $sub): ?Carbon
    {
        $timestamp = data_get($sub, 'current_period_end') ?? data_get($sub, 'items.data.0.current_period_end');

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    private function hasActiveSubscription(Workspace $workspace, string $type): bool
    {
        return WorkspaceSubscription::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', $type)
            ->whereIn('stripe_status', self::ENTITLED_STATUSES)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{success: bool, event: string, message: string}
     */
    private function handleInvoicePaymentSucceeded(array $invoice): array
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        $customerId = (string) ($invoice['customer'] ?? '');
        $amountPaid = isset($invoice['amount_paid']) && is_numeric($invoice['amount_paid'])
            ? (float) ($invoice['amount_paid'] / 100)
            : 0.0;
        $currency = (string) ($invoice['currency'] ?? 'usd');
        $pdfUrl = isset($invoice['invoice_pdf']) ? (string) $invoice['invoice_pdf'] : null;
        // Stripe's own payment time, so a resent or late event keeps the right date.
        $paidAt = data_get($invoice, 'status_transitions.paid_at');

        $workspace = $this->workspaceForInvoice($invoice);
        if ($workspace !== null) {
            BillingInvoice::query()->updateOrCreate(
                ['stripe_invoice_id' => $invoiceId],
                [
                    'workspace_id' => $workspace->id,
                    'amount_paid_usd' => $amountPaid,
                    'currency' => $currency,
                    'status' => 'paid',
                    'invoice_pdf_url' => $pdfUrl,
                    'paid_at' => is_numeric($paidAt) ? Carbon::createFromTimestamp((int) $paidAt) : Carbon::now(),
                ]
            );
        } else {
            Log::warning("Paid Stripe invoice {$invoiceId} did not match any workspace.", ['customer' => $customerId]);
        }

        return [
            'success' => true,
            'event' => 'invoice.payment_succeeded',
            'message' => "Invoice {$invoiceId} recorded as paid.",
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{success: bool, event: string, message: string}
     */
    private function handleInvoicePaymentFailed(array $invoice): array
    {
        $workspace = $this->workspaceForInvoice($invoice);

        if ($workspace !== null) {
            $workspace->update([
                'subscription_status' => 'past_due',
            ]);
            Log::warning("Invoice payment failed for workspace {$workspace->id}; status set to past_due.");
        }

        return [
            'success' => true,
            'event' => 'invoice.payment_failed',
            'message' => 'Invoice payment failure logged.',
        ];
    }
}
