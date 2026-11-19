<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\ReadOnlySpendScope;
use Illuminate\Support\Facades\DB;

class DataForSeoSpendGuard
{
    public function assertCanSpend(
        float $estimatedCostUsd,
        ?Workspace $workspace = null,
        bool $isScheduled = false,
    ): void {
        ReadOnlySpendScope::assertSpendAllowed();

        if ($isScheduled && ! config('coqui.dataforseo.scheduled_spend')) {
            throw new DataForSeoBudgetException(
                'Scheduled DataForSEO spend is disabled (DATAFORSEO_SCHEDULED_SPEND=false).'
            );
        }

        if ($this->isSandbox()) {
            return;
        }

        $dailyCap = (float) config('coqui.dataforseo.daily_cap_usd', 5.0);
        $monthlyCap = (float) config('coqui.dataforseo.monthly_cap_usd', 100.0);

        $todaySpend = $this->spendSince(now()->startOfDay());
        if (($todaySpend + $estimatedCostUsd) > $dailyCap) {
            throw new DataForSeoBudgetException(sprintf(
                'Daily DataForSEO cap ($%.2f) would be exceeded (current: $%.4f, requested: $%.4f).',
                $dailyCap,
                $todaySpend,
                $estimatedCostUsd,
            ));
        }

        $monthSpend = $this->spendSince(now()->startOfMonth());
        if (($monthSpend + $estimatedCostUsd) > $monthlyCap) {
            throw new DataForSeoBudgetException(sprintf(
                'Monthly DataForSEO cap ($%.2f) would be exceeded (current: $%.4f, requested: $%.4f).',
                $monthlyCap,
                $monthSpend,
                $estimatedCostUsd,
            ));
        }

        if ($workspace !== null) {
            $workspaceMonthSpend = $this->spendSince(now()->startOfMonth(), $workspace->id);
            $workspaceBudget = (float) $workspace->monthly_dfs_budget_usd;

            if (($workspaceMonthSpend + $estimatedCostUsd) > $workspaceBudget) {
                throw new DataForSeoBudgetException(sprintf(
                    'Workspace monthly DataForSEO budget ($%.2f) exceeded.',
                    $workspaceBudget,
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function recordSpend(
        string $endpoint,
        string $mode,
        float $estimatedCostUsd,
        float $actualCostUsd,
        ?int $workspaceId = null,
        ?int $projectId = null,
        ?string $externalTaskId = null,
        ?int $httpStatus = null,
        array $meta = [],
    ): void {
        $now = now();

        DB::table('dataforseo_spend_ledger')->insert([
            'workspace_id' => $workspaceId,
            'project_id' => $projectId,
            'endpoint' => $endpoint,
            'mode' => $mode,
            'external_task_id' => $externalTaskId,
            'estimated_cost_usd' => $estimatedCostUsd,
            'actual_cost_usd' => $actualCostUsd,
            'http_status' => $httpStatus,
            'is_sandbox' => $this->isSandbox(),
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'recorded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function spendSince(\DateTimeInterface $since, ?int $workspaceId = null): float
    {
        $query = DB::table('dataforseo_spend_ledger')
            ->where('is_sandbox', false)
            ->where('recorded_at', '>=', $since);

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        return (float) $query->sum('actual_cost_usd');
    }

    public function isSandbox(): bool
    {
        return str_contains((string) config('coqui.dataforseo.base_url'), 'sandbox.dataforseo.com');
    }
}
