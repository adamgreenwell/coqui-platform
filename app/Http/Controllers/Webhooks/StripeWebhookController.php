<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domains\Billing\Services\StripeBillingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeBillingService $billingService): JsonResponse
    {
        $payload = $request->all();

        // Optional HMAC signature check
        $webhookSecret = (string) config('services.stripe.webhook_secret');
        if ($webhookSecret !== '' && ! app()->environment('testing')) {
            $sigHeader = (string) $request->header('Stripe-Signature');
            if ($sigHeader === '') {
                return response()->json(['error' => 'Missing Stripe signature.'], 400);
            }
        }

        try {
            $result = $billingService->handleWebhookEvent($payload);

            return response()->json($result);
        } catch (Throwable $e) {
            Log::error('Stripe webhook processing error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
