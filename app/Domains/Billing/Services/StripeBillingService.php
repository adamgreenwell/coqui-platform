<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\BillingInvoice;
use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Projects\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

    /**
     * Create Stripe Checkout session for plan upgrade.
     */
    public function createCheckoutSession(
        Workspace $workspace,
        string $planTier,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $secret = (string) config('services.stripe.secret');
        $baseUrl = (string) config('services.stripe.base_url', 'https://api.stripe.com');

        $isMock = $secret === '' || str_starts_with($secret, 'mock_') || app()->environment('testing');
        if ($isMock) {
            return $successUrl.'?session_id=cs_mock_'.bin2hex(random_bytes(8)).'&plan='.$planTier;
        }

        $priceId = (string) config("services.stripe.plans.{$planTier}.price_id", "price_{$planTier}_monthly");

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

        $response = Http::asForm()
            ->withToken($secret)
            ->post(rtrim($baseUrl, '/').'/v1/checkout/sessions', $params);

        if (! $response->successful()) {
            throw new RuntimeException('Stripe checkout session creation failed: '.$response->body());
        }

        $data = $response->json();
        $url = $data['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Stripe did not return a valid checkout URL.');
        }

        return $url;
    }

    /**
     * Create Stripe Customer Billing Portal session for subscription self-management.
     */
    public function createCustomerPortalSession(Workspace $workspace, string $returnUrl): string
    {
        $secret = (string) config('services.stripe.secret');
        $baseUrl = (string) config('services.stripe.base_url', 'https://api.stripe.com');

        $isMock = $secret === '' || str_starts_with($secret, 'mock_') || app()->environment('testing');
        if ($isMock || $workspace->stripe_id === null || $workspace->stripe_id === '') {
            return $returnUrl.'?portal_mock=1';
        }

        $response = Http::asForm()
            ->withToken($secret)
            ->post(rtrim($baseUrl, '/').'/v1/billing_portal/sessions', [
                'customer' => $workspace->stripe_id,
                'return_url' => $returnUrl,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Stripe customer portal session creation failed: '.$response->body());
        }

        $data = $response->json();
        $url = $data['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Stripe did not return a valid portal URL.');
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
