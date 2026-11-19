<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

/**
 * Verifies the `Stripe-Signature` header the way Stripe's SDKs do:
 * HMAC-SHA256 over "{timestamp}.{raw body}" with the endpoint secret,
 * compared timing-safely against every v1 signature, within a tolerance.
 *
 * @see https://docs.stripe.com/webhooks#verify-manually
 */
final class StripeWebhookSignature
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public static function isValid(
        string $payload,
        string $header,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): bool {
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a valid header for a payload (used by tests and local tooling).
     */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
