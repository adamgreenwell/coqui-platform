<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Serp\SeRanking\SeRankingAcceptanceRule;
use App\Domains\Serp\Services\SeoProviderRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeRankingProviderRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_acceptance_rule_partitions_ascii_vs_non_ascii_and_numeric_queries(): void
    {
        $rule = new SeRankingAcceptanceRule;

        $partition = $rule->partition([
            'technical seo audit',
            '180 e-cut™ hybrid',
            '2026',
            'site:coquiseo.com',
            'Technical SEO Audit',
        ]);

        $this->assertSame(['technical seo audit'], $partition['accepted']);
        $this->assertSame([
            '180 e-cut™ hybrid',
            '2026',
            'site:coquiseo.com',
        ], $partition['rejected']);
    }

    public function test_router_splits_keywords_between_seranking_and_dataforseo_and_records_spend(): void
    {
        config()->set('coqui.seranking.api_key', 'test-seranking-token');
        config()->set('coqui.seranking.base_url', 'https://api.seranking.com');
        config()->set('coqui.dataforseo.login', 'dfs-login');
        config()->set('coqui.dataforseo.password', 'dfs-pass');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');

        Http::fake([
            'api.seranking.com/v1/keywords/export*' => Http::response([
                ['keyword' => 'seo crawler', 'volume' => 1900, 'is_data_found' => true],
            ], 200),
            'sandbox.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live' => Http::response([
                'cost' => 0.01224,
                'tasks' => [
                    [
                        'id' => 'dfs-task-1',
                        'result' => [
                            ['items' => [['keyword' => '2026']]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        /** @var SeoProviderRouter $router */
        $router = app(SeoProviderRouter::class);

        $result = $router->lookupKeywords([
            'seo crawler',
            '2026',
            '180 e-cut™ hybrid',
        ]);

        $this->assertSame(['seo crawler'], $result['partition']['accepted']);
        $this->assertSame(['2026', '180 e-cut™ hybrid'], $result['partition']['rejected']);
        $this->assertCount(1, $result['providers']['seranking']);
        $this->assertSame('seo crawler', $result['providers']['seranking'][0]['keyword']);
        $this->assertSame('dfs-task-1', $result['providers']['dataforseo']['tasks'][0]['id']);

        $this->assertSame(100, (int) DB::table('seranking_spend_ledger')->sum('actual_credits'));
        $this->assertSame(1, DB::table('dataforseo_spend_ledger')->count());
    }
}
