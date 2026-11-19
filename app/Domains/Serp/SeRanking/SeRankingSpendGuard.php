<?php

declare(strict_types=1);

namespace App\Domains\Serp\SeRanking;

use App\Domains\Serp\ReadOnlySpendScope;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class SeRankingSpendGuard
{
    public function assertCanSpend(int $estimatedCredits, bool $isScheduled = false): void
    {
        ReadOnlySpendScope::assertSpendAllowed();

        if ($isScheduled && ! config('coqui.seranking.scheduled_spend')) {
            throw new SeRankingBudgetException(
                'Scheduled SE Ranking spend is disabled (SERANKING_SCHEDULED_SPEND=false).'
            );
        }

        $dailyCap = (int) config('coqui.seranking.daily_credit_cap', 5000);
        $monthlyCap = (int) config('coqui.seranking.monthly_credit_cap', 100000);

        $todayCredits = $this->creditsSince(now()->startOfDay());
        if (($todayCredits + $estimatedCredits) > $dailyCap) {
            throw new SeRankingBudgetException(sprintf(
                'Daily SE Ranking credit cap (%d) would be exceeded (current: %d, requested: %d).',
                $dailyCap,
                $todayCredits,
                $estimatedCredits,
            ));
        }

        $monthCredits = $this->creditsSince(now()->startOfMonth());
        if (($monthCredits + $estimatedCredits) > $monthlyCap) {
            throw new SeRankingBudgetException(sprintf(
                'Monthly SE Ranking credit cap (%d) would be exceeded (current: %d, requested: %d).',
                $monthlyCap,
                $monthCredits,
                $estimatedCredits,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function recordSpend(
        string $endpoint,
        int $estimatedCredits,
        int $actualCredits,
        ?int $workspaceId = null,
        ?int $projectId = null,
        ?int $httpStatus = null,
        array $meta = [],
    ): void {
        $now = now();

        DB::table('seranking_spend_ledger')->insert([
            'workspace_id' => $workspaceId,
            'project_id' => $projectId,
            'endpoint' => $endpoint,
            'estimated_credits' => $estimatedCredits,
            'actual_credits' => $actualCredits,
            'http_status' => $httpStatus,
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'recorded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function creditsSince(DateTimeInterface $since): int
    {
        return (int) DB::table('seranking_spend_ledger')
            ->where('recorded_at', '>=', $since)
            ->sum('actual_credits');
    }
}
