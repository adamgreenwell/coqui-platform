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

        if ($planTier !== 'runnr_addon' && $this->hasActiveBaseSubscription($workspace)) {
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

    private function hasActiveBaseSubscription(Workspace $workspace): bool
    {
        return WorkspaceSubscription::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'default')
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
            ->exists();
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

        $workspace->stripe_id = $stripeCustomerId;
        $workspace->stripe_subscription_id = $stripeSubId;
        $workspace->subscription_status = 'active';

        $isRunnrAddon = $planTier === 'runnr_addon';
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
        $periodEnd = isset($sub['current_period_end']) && is_numeric($sub['current_period_end'])
            ? Carbon::createFromTimestamp((int) $sub['current_period_end'])
            : null;

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()
            ->where('stripe_subscription_id', $subId)
            ->orWhere('stripe_id', (string) ($sub['customer'] ?? ''))
            ->first();

        if ($workspace !== null) {
            $workspace->update([
                'subscription_status' => $status,
                'current_period_end' => $periodEnd,
            ]);

            WorkspaceSubscription::query()->where('stripe_id', $subId)->update([
                'stripe_status' => $status,
            ]);
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

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()
            ->where('stripe_subscription_id', $subId)
            ->orWhere('stripe_id', (string) ($sub['customer'] ?? ''))
            ->first();

        if ($workspace !== null) {
            $workspace->applyPlanTier('free');
            $workspace->update([
                'subscription_status' => 'canceled',
                'has_runnr_addon' => false,
            ]);

            WorkspaceSubscription::query()->where('stripe_id', $subId)->update([
                'stripe_status' => 'canceled',
                'ends_at' => Carbon::now(),
            ]);

            Log::info("Workspace {$workspace->id} downgraded to Free tier after subscription cancellation.");
        }

        return [
            'success' => true,
            'event' => 'customer.subscription.deleted',
            'message' => "Subscription {$subId} cancelled; workspace reset to Free.",
        ];
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

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->where('stripe_id', $customerId)->first();
        if ($workspace !== null) {
            BillingInvoice::query()->updateOrCreate(
                ['stripe_invoice_id' => $invoiceId],
                [
                    'workspace_id' => $workspace->id,
                    'amount_paid_usd' => $amountPaid,
                    'currency' => $currency,
                    'status' => 'paid',
                    'invoice_pdf_url' => $pdfUrl,
                    'paid_at' => Carbon::now(),
                ]
            );
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
        $customerId = (string) ($invoice['customer'] ?? '');
        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->where('stripe_id', $customerId)->first();

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
