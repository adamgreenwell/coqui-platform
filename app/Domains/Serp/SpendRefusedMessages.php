<?php

declare(strict_types=1);

namespace App\Domains\Serp;

/**
 * User-facing wording for refused data lookups. It never names a data
 * provider or shows platform-wide spend.
 */
final class SpendRefusedMessages
{
    public const DAILY_LIMIT = 'Live data lookups have reached today\'s limit. Cached results are still available; try again tomorrow.';

    public static function workspaceAllowance(float $budgetUsd): string
    {
        return sprintf(
            'Your workspace has used this month\'s $%.2f live data allowance. It resets on the 1st, or you can upgrade your plan for more.',
            $budgetUsd,
        );
    }
}
