<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\PlanCatalog;
use App\Domains\Billing\Services\StripeBillingService;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_free_tier_no_longer_gets_a_larger_allowance_than_a_paid_one(): void
    {
        $plans = PlanCatalog::all();

        $this->assertTrue(
            $plans[PlanCatalog::FREE]['monthly_dfs_budget_usd'] < $plans[PlanCatalog::PERSONAL]['monthly_dfs_budget_usd'],
            'The free plan must not out-spend the cheapest paid plan on vendor data.'
        );
    }

    public function test_paid_allowances_grow_with_the_price(): void
    {
        $allowances = array_map(
            static fn (array $plan): float => $plan['monthly_dfs_budget_usd'],
            PlanCatalog::all(),
        );

        $this->assertTrue($allowances[PlanCatalog::PERSONAL] < $allowances[PlanCatalog::PRO]);
        $this->assertTrue($allowances[PlanCatalog::PRO] < $allowances[PlanCatalog::AGENCY]);
    }

    public function test_no_paid_plan_spends_more_than_a_third_of_its_price_on_vendor_data(): void
    {
        foreach ([PlanCatalog::PERSONAL, PlanCatalog::PRO, PlanCatalog::AGENCY] as $tier) {
            $plan = PlanCatalog::get($tier);
            $share = $plan['monthly_dfs_budget_usd'] / $plan['price_usd'];

            $this->assertLessThanOrEqual(
                PlanCatalog::ALLOWANCE_RATIO + 0.02,
                $share,
                "The {$tier} plan spends too much of its price on vendor data."
            );
        }
    }

    public function test_the_free_plan_gets_the_minimum_allowance(): void
    {
        $this->assertSame(PlanCatalog::MIN_ALLOWANCE_USD, PlanCatalog::get(PlanCatalog::FREE)['monthly_dfs_budget_usd']);
    }

    public function test_the_runnr_add_on_sells_no_vendor_data(): void
    {
        $this->assertSame(0.0, PlanCatalog::get(PlanCatalog::RUNNR_ADDON)['monthly_dfs_budget_usd']);
    }

    public function test_the_pricing_copy_quotes_the_allowance_the_spend_guard_enforces(): void
    {
        foreach ([PlanCatalog::FREE, PlanCatalog::PERSONAL, PlanCatalog::PRO, PlanCatalog::AGENCY] as $tier) {
            $plan = PlanCatalog::get($tier);
            $expected = '$'.PlanCatalog::money($plan['monthly_dfs_budget_usd']).'/mo Live SEO Data Allowance';

            $this->assertContains($expected, $plan['features'], "The {$tier} plan must advertise the allowance it enforces.");
        }
    }

    public function test_applying_a_tier_uses_the_catalog(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Catalog Workspace',
            'slug' => 'catalog-workspace',
            'owner_id' => $user->id,
        ]);

        $workspace->applyPlanTier('agency');
        $workspace->refresh();

        $agency = PlanCatalog::get(PlanCatalog::AGENCY);
        $this->assertSame('agency', $workspace->plan_tier);
        $this->assertSame($agency['tracked_keyword_limit'], $workspace->tracked_keyword_limit);
        $this->assertSame($agency['cloud_crawl_url_limit'], $workspace->cloud_crawl_url_limit);
        $this->assertSame($agency['monthly_dfs_budget_usd'], (float) $workspace->monthly_dfs_budget_usd);
    }

    public function test_an_unknown_tier_falls_back_to_free_limits(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Fallback Workspace',
            'slug' => 'fallback-workspace',
            'owner_id' => $user->id,
        ]);

        $workspace->applyPlanTier('platinum');
        $workspace->refresh();

        $free = PlanCatalog::get(PlanCatalog::FREE);
        $this->assertSame('free', $workspace->plan_tier);
        $this->assertSame($free['monthly_dfs_budget_usd'], (float) $workspace->monthly_dfs_budget_usd);
    }

    public function test_the_billing_page_and_the_workspace_limits_share_one_source(): void
    {
        $fromService = app(StripeBillingService::class)->getPlans();
        $fromCatalog = PlanCatalog::all();

        $this->assertSame($fromCatalog, $fromService);
    }
}
