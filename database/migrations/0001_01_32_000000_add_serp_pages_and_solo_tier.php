<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Solo rename, SERP credit accounting, and the agreed plan allowances.
 *
 * - Free becomes a strict subset of Solo: it had a larger data allowance
 *   than the $19 plan, which could not have been intended.
 * - Paid plans move to $29 / $79 / $199 with $8 / $22 / $58 data
 *   allowances, each about 28% of the price.
 * - Reads one page of 10 SERP results = one credit, so a depth-100 check is
 *   ten credits. The column lets the workspace see what it has spent without
 *   parsing the request archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dataforseo_spend_ledger', function (Blueprint $table): void {
            if (! Schema::hasColumn('dataforseo_spend_ledger', 'serp_pages')) {
                $table->unsignedSmallInteger('serp_pages')->nullable()->after('mode');
            }
        });

        DB::table('workspaces')->where('plan_tier', 'personal')->update(['plan_tier' => 'solo']);

        foreach (self::PLANS as $tier => $limits) {
            DB::table('workspaces')->where('plan_tier', $tier)->update($limits);
        }
    }

    public function down(): void
    {
        foreach (self::PREVIOUS as $tier => $limits) {
            DB::table('workspaces')->where('plan_tier', $tier)->update($limits);
        }

        DB::table('workspaces')->where('plan_tier', 'solo')->update(['plan_tier' => 'personal']);

        Schema::table('dataforseo_spend_ledger', function (Blueprint $table): void {
            if (Schema::hasColumn('dataforseo_spend_ledger', 'serp_pages')) {
                $table->dropColumn('serp_pages');
            }
        });
    }

    /** @var array<string, array{tracked_keyword_limit: int, cloud_crawl_url_limit: int, monthly_dfs_budget_usd: float}> */
    private const PLANS = [
        'free' => ['tracked_keyword_limit' => 20, 'cloud_crawl_url_limit' => 1000, 'monthly_dfs_budget_usd' => 0.50],
        'solo' => ['tracked_keyword_limit' => 300, 'cloud_crawl_url_limit' => 5000, 'monthly_dfs_budget_usd' => 8.00],
        'pro' => ['tracked_keyword_limit' => 1000, 'cloud_crawl_url_limit' => 25000, 'monthly_dfs_budget_usd' => 22.00],
        'agency' => ['tracked_keyword_limit' => 5000, 'cloud_crawl_url_limit' => 250000, 'monthly_dfs_budget_usd' => 58.00],
    ];

    /** @var array<string, array{tracked_keyword_limit: int, cloud_crawl_url_limit: int, monthly_dfs_budget_usd: float}> */
    private const PREVIOUS = [
        'free' => ['tracked_keyword_limit' => 100, 'cloud_crawl_url_limit' => 1000, 'monthly_dfs_budget_usd' => 0.50],
        'personal' => ['tracked_keyword_limit' => 300, 'cloud_crawl_url_limit' => 5000, 'monthly_dfs_budget_usd' => 6.00],
        'pro' => ['tracked_keyword_limit' => 1000, 'cloud_crawl_url_limit' => 25000, 'monthly_dfs_budget_usd' => 15.00],
        'agency' => ['tracked_keyword_limit' => 10000, 'cloud_crawl_url_limit' => 250000, 'monthly_dfs_budget_usd' => 60.00],
    ];
};
