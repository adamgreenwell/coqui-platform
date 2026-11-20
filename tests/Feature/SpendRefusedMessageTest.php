<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoSpendGuard;
use App\Domains\Serp\SeRanking\SeRankingSpendGuard;
use App\Domains\Serp\SpendRefusedException;
use App\Domains\Serp\SpendRefusedMessages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refused lookups keep provider names and platform spend in the log message
 * and show users wording that names neither.
 */
class SpendRefusedMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.base_url', 'https://api.dataforseo.com');
    }

    public function test_platform_daily_cap_shows_a_generic_daily_limit(): void
    {
        config()->set('coqui.dataforseo.daily_cap_usd', 0.001);

        $e = $this->refusal(fn () => app(DataForSeoSpendGuard::class)->assertCanSpend(0.01));

        $this->assertStringContainsString('DataForSEO', $e->getMessage());
        $this->assertSame(SpendRefusedMessages::DAILY_LIMIT, $e->publicMessage());
    }

    public function test_platform_monthly_cap_shows_the_default_pause_message(): void
    {
        config()->set('coqui.dataforseo.daily_cap_usd', 100.0);
        config()->set('coqui.dataforseo.monthly_cap_usd', 0.001);

        $e = $this->refusal(fn () => app(DataForSeoSpendGuard::class)->assertCanSpend(0.01));

        $this->assertSame(SpendRefusedException::DEFAULT_PUBLIC_MESSAGE, $e->publicMessage());
    }

    public function test_workspace_allowance_names_the_allowance_but_not_the_provider(): void
    {
        config()->set('coqui.dataforseo.daily_cap_usd', 100.0);
        config()->set('coqui.dataforseo.monthly_cap_usd', 100.0);

        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Allowance Workspace',
            'slug' => 'allowance',
            'owner_id' => $user->id,
            'monthly_dfs_budget_usd' => 8.00,
        ]);

        $e = $this->refusal(fn () => app(DataForSeoSpendGuard::class)->assertCanSpend(9.0, $workspace));

        $this->assertSame(SpendRefusedMessages::workspaceAllowance(8.0), $e->publicMessage());
        $this->assertStringContainsString('$8.00', $e->publicMessage());
    }

    public function test_bulk_keyword_daily_cap_shows_the_same_daily_limit(): void
    {
        config()->set('coqui.seranking.daily_credit_cap', 10);

        $e = $this->refusal(fn () => app(SeRankingSpendGuard::class)->assertCanSpend(100));

        $this->assertStringContainsString('SE Ranking', $e->getMessage());
        $this->assertSame(SpendRefusedMessages::DAILY_LIMIT, $e->publicMessage());
    }

    public function test_public_messages_never_name_a_provider(): void
    {
        foreach ([SpendRefusedMessages::DAILY_LIMIT, SpendRefusedMessages::workspaceAllowance(25.0), SpendRefusedException::DEFAULT_PUBLIC_MESSAGE] as $message) {
            $this->assertDoesNotMatchRegularExpression('/data\s*for\s*seo|se\s*ranking|serp\s*house/i', $message);
        }
    }

    private function refusal(callable $spend): SpendRefusedException
    {
        try {
            $spend();
        } catch (SpendRefusedException $e) {
            return $e;
        }

        $this->fail('Expected the spend to be refused.');
    }
}
