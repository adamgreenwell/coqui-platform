<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\PlanCatalog;
use App\Domains\Billing\Services\StripeBillingService;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_free_tier_never_gets_more_than_a_paid_one(): void
    {
        $plans = PlanCatalog::all();

        $this->assertTrue(
            $plans[PlanCatalog::FREE]['monthly_dfs_budget_usd'] < $plans[PlanCatalog::SOLO]['monthly_dfs_budget_usd'],
            'The free plan must not out-spend the cheapest paid plan on vendor data.'
        );
        $this->assertTrue($plans[PlanCatalog::FREE]['monthly_serp_credits'] < $plans[PlanCatalog::SOLO]['monthly_serp_credits']);
    }

    public function test_paid_allowances_and_credits_grow_with_the_price(): void
    {
        $allowances = [];
        $credits = [];

        foreach ([PlanCatalog::SOLO, PlanCatalog::PRO, PlanCatalog::AGENCY] as $tier) {
            $plan = PlanCatalog::get($tier);
            $allowances[] = $plan['monthly_dfs_budget_usd'];
            $credits[] = $plan['monthly_serp_credits'];
        }

        $this->assertSame([8.0, 22.0, 58.0], $allowances);
        $this->assertSame([3000, 10000, 45000], $credits);
    }

    public function test_no_paid_plan_spends_more_than_a_third_of_its_price_on_vendor_data(): void
    {
        foreach ([PlanCatalog::SOLO, PlanCatalog::PRO, PlanCatalog::AGENCY] as $tier) {
            $plan = PlanCatalog::get($tier);
            $share = $plan['monthly_dfs_budget_usd'] / $plan['price_usd'];

            $this->assertLessThanOrEqual(
                PlanCatalog::ALLOWANCE_RATIO,
                $share,
                "The {$tier} plan spends too much of its price on vendor data."
            );
        }
    }

    public function test_a_plan_can_always_fund_the_credits_it_sells(): void
    {
        // One credit is one 10-result page, so the vendor cost of a plan's
        // full credit allowance must fit under its own spend cap.
        foreach ([PlanCatalog::FREE, PlanCatalog::SOLO, PlanCatalog::PRO, PlanCatalog::AGENCY] as $tier) {
            $plan = PlanCatalog::get($tier);
            $creditCost = $plan['monthly_serp_credits'] * (float) config('coqui.dataforseo.prices.serp_standard_task', 0.0006);

            $this->assertLessThanOrEqual(
                $plan['monthly_dfs_budget_usd'],
                $creditCost,
                "The {$tier} plan sells more credits than its data allowance can pay for."
            );
        }
    }

    public function test_credits_are_priced_per_page_so_depth_is_not_a_permission(): void
    {
        $this->assertSame(1, PlanCatalog::creditsForDepth(10));
        $this->assertSame(1, PlanCatalog::creditsForDepth(1));
        $this->assertSame(2, PlanCatalog::creditsForDepth(20));
        $this->assertSame(10, PlanCatalog::creditsForDepth(100));
        $this->assertSame(11, PlanCatalog::creditsForDepth(101));
    }

    public function test_the_free_plan_gets_the_minimum_allowance(): void
    {
        $this->assertSame(PlanCatalog::MIN_ALLOWANCE_USD, PlanCatalog::get(PlanCatalog::FREE)['monthly_dfs_budget_usd']);
    }

    public function test_the_runnr_add_on_sells_no_vendor_data(): void
    {
        $addon = PlanCatalog::get(PlanCatalog::RUNNR_ADDON);

        $this->assertSame(0.0, $addon['monthly_dfs_budget_usd']);
        $this->assertSame(0, $addon['monthly_serp_credits']);
    }

    public function test_applying_a_tier_uses_the_catalog(): void
    {
        $workspace = $this->workspace();

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
        $workspace = $this->workspace();

        $workspace->applyPlanTier('platinum');
        $workspace->refresh();

        $free = PlanCatalog::get(PlanCatalog::FREE);
        $this->assertSame('free', $workspace->plan_tier);
        $this->assertSame($free['monthly_dfs_budget_usd'], (float) $workspace->monthly_dfs_budget_usd);
    }

    public function test_the_billing_page_and_the_workspace_limits_share_one_source(): void
    {
        $this->assertSame(PlanCatalog::all(), app(StripeBillingService::class)->getPlans());
    }

    public function test_credit_usage_counts_only_this_month_and_only_serp_pages(): void
    {
        $workspace = $this->workspace();

        $this->seedLedger($workspace, pages: 10, at: now());
        $this->seedLedger($workspace, pages: 3, at: now()->subMonth());
        $this->seedLedger($workspace, pages: null, at: now());

        $this->assertSame(10, $workspace->serpCreditsUsedThisMonth());
        $this->assertSame($workspace->serpCreditLimit() - 10, $workspace->remainingSerpCredits());
    }

    private function workspace(): Workspace
    {
        $user = User::factory()->create();

        return Workspace::query()->create([
            'name' => 'Catalog Workspace',
            'slug' => 'catalog-workspace-'.str()->random(6),
            'owner_id' => $user->id,
        ]);
    }

    private function seedLedger(Workspace $workspace, ?int $pages, \DateTimeInterface $at): void
    {
        DB::table('dataforseo_spend_ledger')->insert([
            'workspace_id' => $workspace->id,
            'endpoint' => '/v3/serp/google/organic/task_post',
            'mode' => 'standard',
            'serp_pages' => $pages,
            'estimated_cost_usd' => 0.0006,
            'actual_cost_usd' => 0.0006,
            'status' => 'settled',
            'is_sandbox' => false,
            'recorded_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
