<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Serp\DataForSeo\DataForSeoBudgetException;
use App\Domains\Serp\DataForSeo\DataForSeoSpendGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataForSeoSpendGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_spend_is_blocked_when_kill_switch_is_disabled(): void
    {
        config()->set('coqui.dataforseo.scheduled_spend', false);

        $guard = new DataForSeoSpendGuard;

        $this->expectException(DataForSeoBudgetException::class);
        $guard->assertCanSpend(0.01, isScheduled: true);
    }

    public function test_daily_cap_blocks_excess_production_spend(): void
    {
        config()->set('coqui.dataforseo.base_url', 'https://api.dataforseo.com');
        config()->set('coqui.dataforseo.daily_cap_usd', 1.00);

        $guard = new DataForSeoSpendGuard;
        $guard->recordSpend('/v3/serp/google/organic/task_post', 'standard', 0.90, 0.90);

        $this->expectException(DataForSeoBudgetException::class);
        $guard->assertCanSpend(0.25);
    }
}
