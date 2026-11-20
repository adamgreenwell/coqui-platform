<?php

declare(strict_types=1);

namespace App\Domains\Serp\RankFallback;

use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\RankFallbackCheck;
use App\Domains\Serp\SpendRefusedMessages;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spend limits for backup rank checks. They have their own platform caps and
 * share the workspace's monthly live data allowance with the primary provider.
 */
class RankFallbackSpendGuard
{
    public function enabled(): bool
    {
        return (bool) config('coqui.serphouse.fallback_enabled')
            && (string) config('coqui.serphouse.api_key') !== '';
    }

    /**
     * @throws RankFallbackBudgetException
     */
    public function assertCanSpend(float $estimatedCostUsd, Workspace $workspace): void
    {
        if (! $this->enabled()) {
            throw new RankFallbackBudgetException('Rank fallback is off (SERPHOUSE_FALLBACK_ENABLED or SERPHOUSE_API_KEY not set).');
        }

        $dailyCap = (float) config('coqui.serphouse.daily_cap_usd', 2.0);
        $todaySpend = self::spendSince(now()->startOfDay());
        if (($todaySpend + $estimatedCostUsd) > $dailyCap) {
            throw new RankFallbackBudgetException(sprintf(
                'Daily SERPHouse fallback cap ($%.2f) would be exceeded (current: $%.4f, requested: $%.4f).',
                $dailyCap,
                $todaySpend,
                $estimatedCostUsd,
            ), SpendRefusedMessages::DAILY_LIMIT);
        }

        $monthlyCap = (float) config('coqui.serphouse.monthly_cap_usd', 25.0);
        $monthSpend = self::spendSince(now()->startOfMonth());
        if (($monthSpend + $estimatedCostUsd) > $monthlyCap) {
            throw new RankFallbackBudgetException(sprintf(
                'Monthly SERPHouse fallback cap ($%.2f) would be exceeded (current: $%.4f, requested: $%.4f).',
                $monthlyCap,
                $monthSpend,
                $estimatedCostUsd,
            ));
        }

        $budget = (float) $workspace->monthly_dfs_budget_usd;
        if (($workspace->currentMonthDfsSpend() + $estimatedCostUsd) > $budget) {
            throw new RankFallbackBudgetException(
                sprintf('Workspace monthly live data budget ($%.2f) exceeded by rank fallback.', $budget),
                SpendRefusedMessages::workspaceAllowance($budget),
            );
        }
    }

    /**
     * Check the caps and hold the worst-case cost on the check in one step,
     * under a lock, so checks running at the same time see each other's
     * holds. The hold is the cost already paid on earlier attempts plus
     * $estimatedCostUsd, and it is released when the check finishes.
     *
     * @throws RankFallbackBudgetException
     */
    public function reserve(RankFallbackCheck $check, float $estimatedCostUsd, Workspace $workspace): void
    {
        Cache::lock('rank-fallback:spend', 30)->block(15, function () use ($check, $estimatedCostUsd, $workspace): void {
            // Drop this check's own hold first so it isn't counted twice.
            $check->update(['reserved_usd' => 0]);

            $this->assertCanSpend($estimatedCostUsd, $workspace);

            $check->update(['reserved_usd' => (float) $check->cost_usd + $estimatedCostUsd]);
        });
    }

    /**
     * Fallback spend since a time: what each check paid, or its hold while
     * it is still running, whichever is larger.
     */
    public static function spendSince(DateTimeInterface $since, ?int $workspaceId = null): float
    {
        $query = DB::table('rank_fallback_checks')->where('created_at', '>=', $since);

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        return (float) $query->sum(DB::raw('CASE WHEN reserved_usd > cost_usd THEN reserved_usd ELSE cost_usd END'));
    }
}
