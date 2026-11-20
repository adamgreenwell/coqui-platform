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
     * holds. The hold shrinks as pages are paid and is released when the
     * check finishes.
     *
     * @throws RankFallbackBudgetException
     */
    public function reserve(RankFallbackCheck $check, float $estimatedCostUsd, Workspace $workspace): void
    {
        Cache::lock('rank-fallback:spend', 30)->block(15, function () use ($check, $estimatedCostUsd, $workspace): void {
            // Drop this check's own hold first so it isn't counted twice.
            $check->update(['reserved_usd' => 0]);

            $this->assertCanSpend($estimatedCostUsd, $workspace);

            $check->update(['reserved_usd' => $estimatedCostUsd]);
        });
    }

    /**
     * Record one paid request: a ledger row dated now, moved out of the
     * check's hold so it isn't counted twice.
     */
    public function recordCharge(RankFallbackCheck $check, float $costUsd): void
    {
        DB::transaction(function () use ($check, $costUsd): void {
            DB::table('rank_fallback_spend')->insert([
                'rank_fallback_check_id' => $check->id,
                'workspace_id' => $check->workspace_id,
                'cost_usd' => $costUsd,
                'recorded_at' => now(),
            ]);

            $check->update([
                'pages_fetched' => $check->pages_fetched + 1,
                'cost_usd' => (float) $check->cost_usd + $costUsd,
                'reserved_usd' => max(0.0, (float) $check->reserved_usd - $costUsd),
            ]);
        });
    }

    /**
     * Fallback spend since a time: paid requests recorded since then, plus
     * every hold on a check that is still running. Holds count toward any
     * period, so a check that crosses midnight is never left out.
     */
    public static function spendSince(DateTimeInterface $since, ?int $workspaceId = null): float
    {
        $paid = DB::table('rank_fallback_spend')->where('recorded_at', '>=', $since);
        $held = DB::table('rank_fallback_checks')->where('reserved_usd', '>', 0);

        if ($workspaceId !== null) {
            $paid->where('workspace_id', $workspaceId);
            $held->where('workspace_id', $workspaceId);
        }

        return (float) $paid->sum('cost_usd') + (float) $held->sum('reserved_usd');
    }
}
