<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Serp\SeRanking\SeRankingAcceptanceRule;
use App\Domains\Serp\SeRanking\SeRankingClient;
use App\Domains\Serp\Services\SeoProviderRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
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

        $this->assertSame([], $result['fallback']);
        $this->assertSame(100, $result['cost']['seranking_credits']);
        $this->assertSame(0.01224, $result['cost']['dataforseo_usd']);
        $this->assertSame(['seo crawler' => 'seranking', '2026' => 'dataforseo'], array_column($result['keywords'], 'provider', 'keyword'));
        $this->assertSame(1900, $result['keywords'][0]['search_volume']);
    }

    private function configureProviders(bool $dataForSeo = true): void
    {
        config()->set('coqui.seranking.api_key', 'test-seranking-token');
        config()->set('coqui.seranking.base_url', 'https://api.seranking.com');
        config()->set('coqui.seranking.batch_size', 100);
        config()->set('coqui.seranking.min_split_batch_size', 50);
        config()->set('coqui.dataforseo.login', $dataForSeo ? 'dfs-login' : '');
        config()->set('coqui.dataforseo.password', $dataForSeo ? 'dfs-pass' : '');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');
    }

    /**
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    private function dataForSeoOverview(array $keywords, float $cost = 0.02): array
    {
        return [
            'cost' => $cost,
            'tasks' => [[
                'id' => 'dfs-task-fallback',
                'status_code' => 20000,
                'result' => [[
                    'items' => array_map(static fn (string $keyword): array => [
                        'keyword' => $keyword,
                        'keyword_info' => ['search_volume' => 320, 'cpc' => 1.5, 'competition' => 0.4],
                        'keyword_properties' => ['keyword_difficulty' => 22],
                    ], $keywords),
                ]],
            ]],
        ];
    }

    public function test_a_failed_domain_overview_records_no_credits(): void
    {
        $this->configureProviders();
        Http::fake(['api.seranking.com/v1/domain/overview/db*' => Http::response(['message' => 'Too many requests'], 429)]);

        try {
            app(SeRankingClient::class)->domainOverview('example.com');
            $this->fail('A throttled overview should still throw.');
        } catch (RequestException) {
            // expected
        }

        // Retried up to five times, none of them billed.
        Http::assertSentCount(5);
        $this->assertSame(1, DB::table('seranking_spend_ledger')->count());
        $this->assertSame(0, (int) DB::table('seranking_spend_ledger')->sum('actual_credits'));
        $this->assertSame(100, (int) DB::table('seranking_spend_ledger')->value('estimated_credits'));
    }

    public function test_export_retries_a_throttled_batch_and_bills_it_once(): void
    {
        $this->configureProviders();
        Http::fake([
            'api.seranking.com/v1/keywords/export*' => Http::sequence()
                ->push(['message' => 'Too many requests'], 429, ['Retry-After' => '1'])
                ->push(['message' => 'Too many requests'], 429)
                ->push([['keyword' => 'seo crawler', 'volume' => 1900]], 200),
        ]);

        $result = app(SeRankingClient::class)->exportKeywordsDetailed(['seo crawler']);

        Http::assertSentCount(3);
        $this->assertSame('seo crawler', $result['rows'][0]['keyword']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(100, $result['credits']);
        $this->assertSame(100, (int) DB::table('seranking_spend_ledger')->sum('actual_credits'));
    }

    public function test_a_batch_rejected_at_the_split_floor_keeps_the_rest_of_the_export(): void
    {
        $this->configureProviders();
        $keywords = array_map(static fn (int $i): string => "keyword {$i}", range(1, 100));
        $keywords[73] = 'bad keyword';

        // SE Ranking rejects any batch containing the bad keyword.
        Http::fake([
            'api.seranking.com/v1/keywords/export*' => function (Request $request) {
                /** @var list<string> $batch */
                $batch = $request['keywords'];
                if (in_array('bad keyword', $batch, true)) {
                    return Http::response(['message' => 'Invalid keyword'], 422);
                }

                return Http::response(array_map(static fn (string $k): array => ['keyword' => $k, 'volume' => 10], $batch), 200);
            },
        ]);

        $result = app(SeRankingClient::class)->exportKeywordsDetailed($keywords);

        // 100 -> 422, split into 50 + 50; the half with the bad keyword fails
        // again at the floor and comes back as failed instead of throwing.
        Http::assertSentCount(3);
        $this->assertCount(50, $result['rows']);
        $this->assertSame('keyword 1', $result['rows'][0]['keyword']);
        $this->assertCount(50, $result['failed']);
        $this->assertContains('bad keyword', $result['failed']);
        $this->assertSame(100, $result['credits']);
        $this->assertSame(100, (int) DB::table('seranking_spend_ledger')->sum('actual_credits'));

        // A partial result isn't cached, so the next lookup tries again.
        app(SeRankingClient::class)->exportKeywordsDetailed($keywords);
        Http::assertSentCount(6);
    }

    public function test_router_falls_back_to_dataforseo_when_seranking_errors(): void
    {
        $this->configureProviders();
        Http::fake([
            'api.seranking.com/v1/keywords/export*' => Http::response(['message' => 'Server error'], 500),
            'sandbox.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live' => Http::response(
                $this->dataForSeoOverview(['seo crawler', 'site audit']),
                200,
            ),
        ]);

        $result = app(SeoProviderRouter::class)->lookupKeywords(['seo crawler', 'site audit']);

        $this->assertSame(['seo crawler', 'site audit'], $result['fallback']);
        $this->assertSame([], $result['unavailable']);
        $this->assertSame(['dataforseo', 'dataforseo'], array_column($result['keywords'], 'provider'));
        $this->assertSame(320, $result['keywords'][0]['search_volume']);
        $this->assertSame(22, $result['keywords'][0]['keyword_difficulty']);
        $this->assertSame(0, $result['cost']['seranking_credits']);
        $this->assertSame(0.02, $result['cost']['dataforseo_usd']);
        $this->assertSame(0, (int) DB::table('seranking_spend_ledger')->sum('actual_credits'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'keyword_overview')
            && $request[0]['keywords'] === ['seo crawler', 'site audit']);
    }

    public function test_router_falls_back_to_dataforseo_when_the_seranking_cap_is_reached(): void
    {
        $this->configureProviders();
        config()->set('coqui.seranking.daily_credit_cap', 50);
        Http::fake([
            'sandbox.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live' => Http::response(
                $this->dataForSeoOverview(['seo crawler']),
                200,
            ),
        ]);

        $result = app(SeoProviderRouter::class)->lookupKeywords(['seo crawler']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'seranking'));
        $this->assertSame(['seo crawler'], $result['fallback']);
        $this->assertSame('dataforseo', $result['keywords'][0]['provider']);

        // A cached answer costs nothing the second time.
        $again = app(SeoProviderRouter::class)->lookupKeywords(['seo crawler']);
        $this->assertSame(0.0, $again['cost']['dataforseo_usd']);
    }

    public function test_keywords_seranking_failed_on_are_unavailable_without_dataforseo(): void
    {
        $this->configureProviders(dataForSeo: false);
        Http::fake(['api.seranking.com/v1/keywords/export*' => Http::response(['message' => 'Server error'], 503)]);

        $result = app(SeoProviderRouter::class)->lookupKeywords(['seo crawler']);

        $this->assertSame([], $result['keywords']);
        $this->assertSame(['seo crawler'], $result['unavailable']);
        $this->assertSame(['seo crawler'], $result['fallback']);
    }

    public function test_seranking_rows_survive_a_failed_fallback(): void
    {
        $this->configureProviders();
        $keywords = array_map(static fn (int $i): string => "keyword {$i}", range(1, 100));
        $keywords[73] = 'bad keyword';

        Http::fake([
            'api.seranking.com/v1/keywords/export*' => function (Request $request) {
                /** @var list<string> $batch */
                $batch = $request['keywords'];
                if (in_array('bad keyword', $batch, true)) {
                    return Http::response(['message' => 'Invalid keyword'], 422);
                }

                return Http::response(array_map(static fn (string $k): array => ['keyword' => $k, 'volume' => 10], $batch), 200);
            },
            'sandbox.dataforseo.com/*' => Http::response(['status_code' => 50000, 'status_message' => 'Internal error'], 500),
        ]);

        $result = app(SeoProviderRouter::class)->lookupKeywords($keywords);

        $this->assertCount(50, $result['keywords']);
        $this->assertCount(50, $result['unavailable']);
        $this->assertContains('bad keyword', $result['unavailable']);
        $this->assertSame(100, $result['cost']['seranking_credits']);
    }
}
