<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\PlanCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Compare the configured Stripe Price IDs with what PlanCatalog says each
 * plan costs.
 *
 * Stripe prices are immutable: changing a plan's price means creating a new
 * Price and pointing the env at it. Forgetting one leaves checkout selling
 * the old amount while the application grants the new limits - the customer
 * pays $49 for a $79 plan, or the reverse.
 */
class VerifyStripePricesCommand extends Command
{
    protected $signature = 'billing:verify-prices';

    protected $description = 'Check every configured Stripe price against the plan catalog.';

    public function handle(): int
    {
        $secret = (string) config('services.stripe.secret');

        if (! str_starts_with($secret, 'sk_')) {
            $this->error('STRIPE_SECRET is not set, so the Stripe prices cannot be checked.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) config('services.stripe.base_url', 'https://api.stripe.com'), '/');
        $prices = (array) config('services.stripe.prices', []);

        /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows */
        $rows = [];
        $failures = 0;

        foreach ($prices as $tier => $configuredId) {
            $tier = (string) $tier;
            $priceId = is_string($configuredId) ? $configuredId : '';

            if (! str_starts_with($priceId, 'price_')) {
                $rows[] = [$tier, $priceId !== '' ? $priceId : '(unset)', '-', '-', 'not configured'];
                $failures++;

                continue;
            }

            $response = Http::withBasicAuth($secret, '')
                ->get($baseUrl.'/v1/prices/'.$priceId, ['expand' => ['product']]);

            if (! $response->successful()) {
                $rows[] = [$tier, $priceId, '-', '-', 'Stripe returned '.$response->status()];
                $failures++;

                continue;
            }

            $price = (array) $response->json();
            $plan = PlanCatalog::get($tier);
            $expectedCents = (int) round($plan['price_usd'] * 100);

            $actualCents = $price['unit_amount'] ?? null;
            $actualCents = is_int($actualCents) ? $actualCents : null;

            $problems = [];
            if ($actualCents !== $expectedCents) {
                $problems[] = 'amount';
            }
            if (($price['currency'] ?? '') !== 'usd') {
                $problems[] = 'currency';
            }
            if (data_get($price, 'recurring.interval') !== 'month') {
                $problems[] = 'interval';
            }
            if (! (bool) ($price['active'] ?? false)) {
                $problems[] = 'archived';
            }

            $rows[] = [
                $tier,
                $priceId,
                $actualCents === null ? '-' : '$'.number_format($actualCents / 100, 2),
                '$'.number_format($expectedCents / 100, 2),
                $problems === [] ? 'ok' : implode(', ', $problems),
            ];

            $failures += $problems === [] ? 0 : 1;
        }

        $this->table(['Plan', 'Price ID', 'Stripe', 'Catalog', 'Status'], $rows);

        $configured = array_values(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $prices),
        ));

        if (count($configured) !== count(array_unique($configured))) {
            $this->warn('Two plans point at the same Stripe price.');
            $failures++;
        }

        if ($failures > 0) {
            $this->error("{$failures} price problem(s): checkout will sell the wrong plan or refuse to open.");

            return self::FAILURE;
        }

        $this->info('Every configured Stripe price matches the plan catalog.');

        return self::SUCCESS;
    }
}
