<?php

declare(strict_types=1);

namespace App\Domains\Serp\RankFallback;

use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\SpendRefusedMessages;
use DateTimeInterface;
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

    public static function spendSince(DateTimeInterface $since, ?int $workspaceId = null): float
    {
        $query = DB::table('rank_fallback_checks')->where('created_at', '>=', $since);

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        return (float) $query->sum('cost_usd');
    }
}
