<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\ReadOnlySpendScope;
use App\Domains\Serp\SpendRefusedMessages;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DataForSeoSpendGuard
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_UNKNOWN = 'unknown';

    private const LOCK = 'dataforseo:spend-guard';

    /**
     * Check the caps and, in the same locked step, write a ledger row at the
     * estimated cost. The row counts against every cap straight away, so
     * concurrent jobs can't each pass the check and overshoot together.
     * Settle it with settle() once the response is in.
     *
     * @param  array<string, mixed>  $meta
     */
    public function reserve(
        string $endpoint,
        string $mode,
        float $estimatedCostUsd,
        ?Workspace $workspace = null,
        ?int $projectId = null,
        bool $isScheduled = false,
        array $meta = [],
    ): int {
        $wait = max(0, (int) config('coqui.dataforseo.spend_lock_wait_seconds', 10));

        try {
            return Cache::lock(self::LOCK, 30)->block($wait, function () use ($endpoint, $mode, $estimatedCostUsd, $workspace, $projectId, $isScheduled, $meta): int {
                $this->assertCanSpend($estimatedCostUsd, $workspace, $isScheduled);

                $now = now();

                return (int) DB::table('dataforseo_spend_ledger')->insertGetId([
                    'workspace_id' => $workspace?->id,
                    'project_id' => $projectId,
                    'endpoint' => $endpoint,
                    'mode' => $mode,
                    'estimated_cost_usd' => $estimatedCostUsd,
                    'actual_cost_usd' => $estimatedCostUsd,
                    'status' => self::STATUS_RESERVED,
                    'is_sandbox' => $this->isSandbox(),
                    'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                    'recorded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (LockTimeoutException) {
            throw new DataForSeoBudgetException('Timed out waiting for the DataForSEO spend lock.');
        }
    }

    /**
     * Record how a reserved call ended. `STATUS_UNKNOWN` keeps the estimate,
     * since a call that timed out may still have been billed.
     */
    public function settle(
        int $ledgerId,
        string $status,
        ?float $actualCostUsd = null,
        ?int $httpStatus = null,
        ?string $externalTaskId = null,
    ): void {
        $update = [
            'status' => $status,
            'http_status' => $httpStatus,
            'external_task_id' => $externalTaskId,
            'updated_at' => now(),
        ];
        if ($actualCostUsd !== null) {
            $update['actual_cost_usd'] = $actualCostUsd;
        }

        DB::table('dataforseo_spend_ledger')->where('id', $ledgerId)->update($update);
    }

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
            ), SpendRefusedMessages::DAILY_LIMIT);
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
            // Includes backup rank checks, which share the allowance.
            $workspaceMonthSpend = $workspace->currentMonthDfsSpend();
            $workspaceBudget = (float) $workspace->monthly_dfs_budget_usd;

            if (($workspaceMonthSpend + $estimatedCostUsd) > $workspaceBudget) {
                throw new DataForSeoBudgetException(
                    sprintf('Workspace monthly DataForSEO budget ($%.2f) exceeded.', $workspaceBudget),
                    SpendRefusedMessages::workspaceAllowance($workspaceBudget),
                );
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
