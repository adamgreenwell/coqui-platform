<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VerifyStripePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.secret', 'sk_test_prices');
        config()->set('services.stripe.base_url', 'https://api.stripe.com');
        config()->set('services.stripe.prices', [
            'solo' => 'price_solo',
            'pro' => 'price_pro',
            'agency' => 'price_agency',
            'runnr_addon' => 'price_runnr',
        ]);
    }

    /**
     * @param  array<string, int>  $amounts
     */
    private function fakePrices(array $amounts): void
    {
        Http::fake(function ($request) use ($amounts) {
            $id = basename(parse_url($request->url(), PHP_URL_PATH) ?? '');

            if (! array_key_exists($id, $amounts)) {
                return Http::response(['error' => ['message' => 'No such price']], 404);
            }

            return Http::response([
                'id' => $id,
                'object' => 'price',
                'active' => true,
                'currency' => 'usd',
                'unit_amount' => $amounts[$id],
                'recurring' => ['interval' => 'month'],
            ], 200);
        });
    }

    public function test_it_passes_when_every_price_matches_the_catalog(): void
    {
        $this->fakePrices([
            'price_solo' => 2900,
            'price_pro' => 7900,
            'price_agency' => 19900,
            'price_runnr' => 2900,
        ]);

        $this->artisan('billing:verify-prices')
            ->expectsOutputToContain('Every configured Stripe price matches')
            ->assertSuccessful();
    }

    public function test_it_fails_when_a_plan_sells_the_old_amount(): void
    {
        $this->fakePrices([
            'price_solo' => 2900,
            'price_pro' => 4900, // still the retired Personal-era Pro price
            'price_agency' => 19900,
            'price_runnr' => 2900,
        ]);

        $this->artisan('billing:verify-prices')
            ->expectsOutputToContain('amount')
            ->assertFailed();
    }

    public function test_it_fails_when_a_price_is_unset(): void
    {
        config()->set('services.stripe.prices.solo', '');
        $this->fakePrices(['price_pro' => 7900]);

        $this->artisan('billing:verify-prices')
            ->expectsOutputToContain('not configured')
            ->assertFailed();
    }

    public function test_it_fails_when_stripe_rejects_the_price(): void
    {
        $this->fakePrices(['price_pro' => 7900, 'price_agency' => 19900, 'price_runnr' => 2900]);

        $this->artisan('billing:verify-prices')
            ->expectsOutputToContain('Stripe returned 404')
            ->assertFailed();
    }

    public function test_it_refuses_to_run_without_a_secret(): void
    {
        config()->set('services.stripe.secret', '');

        $this->artisan('billing:verify-prices')
            ->expectsOutputToContain('STRIPE_SECRET is not set')
            ->assertFailed();
    }
}
