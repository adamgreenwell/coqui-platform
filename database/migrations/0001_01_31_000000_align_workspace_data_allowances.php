<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Align existing workspaces with the plan catalog.
 *
 * The old defaults were $25 (Free), $8 (Personal), $150 (Pro) and $750
 * (Agency). Free was larger than Personal, and the paid tiers granted more
 * vendor data than the plan price could carry once a rank check was costed
 * per 10-result SERP page instead of per request.
 *
 * Only rows still carrying an old default are changed, so a workspace whose
 * allowance was set deliberately is left alone.
 */
return new class extends Migration
{
    /** @var array<string, array{old: float, new: float}> */
    private const ALLOWANCES = [
        'free' => ['old' => 25.00, 'new' => 0.50],
        'personal' => ['old' => 8.00, 'new' => 6.00],
        'pro' => ['old' => 150.00, 'new' => 15.00],
        'agency' => ['old' => 750.00, 'new' => 60.00],
    ];

    public function up(): void
    {
        foreach (self::ALLOWANCES as $tier => $allowance) {
            DB::table('workspaces')
                ->where('plan_tier', $tier)
                ->where('monthly_dfs_budget_usd', $allowance['old'])
                ->update(['monthly_dfs_budget_usd' => $allowance['new']]);
        }
    }

    public function down(): void
    {
        foreach (self::ALLOWANCES as $tier => $allowance) {
            DB::table('workspaces')
                ->where('plan_tier', $tier)
                ->where('monthly_dfs_budget_usd', $allowance['new'])
                ->update(['monthly_dfs_budget_usd' => $allowance['old']]);
        }
    }
};
