<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domains\Billing\Services\StripeBillingService;
use App\Domains\Billing\Services\StripeWebhookSignature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeBillingService $billingService): JsonResponse
    {
        $rawPayload = $request->getContent();
        $webhookSecret = (string) config('services.stripe.webhook_secret');

        if ($webhookSecret === '') {
            // Fail closed everywhere except local development, where an
            // unsigned payload is accepted (with a warning) for manual testing.
            if (! app()->environment('local')) {
                Log::error('Stripe webhook rejected: STRIPE_WEBHOOK_SECRET is not configured.');

                return response()->json(['error' => 'Stripe webhooks are not configured.'], 503);
            }

            Log::warning('Accepting unsigned Stripe webhook because STRIPE_WEBHOOK_SECRET is unset (local only).');
        } elseif (! StripeWebhookSignature::isValid(
            $rawPayload,
            (string) $request->header('Stripe-Signature'),
            $webhookSecret,
            (int) config('services.stripe.webhook_tolerance', StripeWebhookSignature::DEFAULT_TOLERANCE_SECONDS),
        )) {
            return response()->json(['error' => 'Invalid Stripe signature.'], 400);
        }

        $payload = json_decode($rawPayload, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'Invalid JSON payload.'], 400);
        }

        try {
            $result = $billingService->handleWebhookEvent($payload);

            return response()->json($result);
        } catch (Throwable $e) {
            Log::error('Stripe webhook processing error: '.$e->getMessage());

            return response()->json(['error' => 'Webhook processing failed.'], 500);
        }
    }
}
