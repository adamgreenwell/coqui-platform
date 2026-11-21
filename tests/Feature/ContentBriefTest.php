<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\Events\ContentBriefUpdated;
use App\Domains\Serp\Jobs\GenerateContentBriefJob;
use App\Domains\Serp\Models\ContentBrief;
use App\Domains\Serp\Services\HtmlPageOutlineExtractor;
use App\Domains\Serp\SpendRefusedException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ContentBriefTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coqui.dataforseo.login', 'test-login');
        config()->set('coqui.dataforseo.password', 'test-password');
        config()->set('coqui.dataforseo.base_url', 'https://sandbox.dataforseo.com');

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => 'SEO Agency',
            'slug' => 'seo-agency',
            'owner_id' => $this->user->id,
            'monthly_dfs_budget_usd' => 100.00,
        ]);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui Analytics',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    public function test_content_brief_page_renders_with_inertia(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/brief");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Keywords/ContentBrief')
            ->has('workspace')
            ->has('project')
            ->has('recentBriefs')
        );
    }

    public function test_unauthorized_user_cannot_view_or_generate_brief(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/brief");

        $response->assertForbidden();

        $postResponse = $this->actingAs($otherUser)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/brief", [
                'keyword' => 'technical seo audit',
            ]);

        $postResponse->assertForbidden();
    }

    public function test_generate_content_brief_success(): void
    {
        $serpUrl = 'https://sandbox.dataforseo.com/v3/serp/google/organic/live/advanced';

        $competitor1Html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Ultimate Technical SEO Guide | Competitor One</title>
    <meta name="description" content="A comprehensive technical SEO guide covering crawl budgets and indexation." />
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": "Technical SEO Guide"
    }
    </script>
</head>
<body>
    <header><nav><a href="/about">About</a><a href="https://google.com">Google</a></nav></header>
    <h1>The Comprehensive Technical SEO Guide</h1>
    <p>Technical SEO is the foundation of high organic search visibility. Every site needs rigorous indexing.</p>
    <h2>Understanding Crawl Budget</h2>
    <p>Search engines allocate bandwidth carefully across web domains. Optimize your robots.txt and sitemap.</p>
    <h3>Robots.txt Optimization</h3>
    <p>Ensure sensitive admin endpoints are blocked from automated web crawlers.</p>
    <h2>Fixing Core Web Vitals</h2>
    <p>Page speed and user interaction metrics dictate modern mobile rankings.</p>
    <a href="/pricing">View Plans</a>
</body>
</html>
HTML;

        $competitor2Html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Technical SEO Checklist 2026 | Competitor Two</title>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            { "@type": "FAQPage" },
            { "@type": "BreadcrumbList" }
        ]
    }
    </script>
</head>
<body>
    <h1>Top 10 Technical SEO Tips</h1>
    <h2>Crawl Budget Analysis</h2>
    <p>Learn how to analyze crawl budget with log files.</p>
    <h2>Fixing Core Web Vitals</h2>
    <p>LCP and CLS tips for high scores.</p>
    <h2>Frequently Asked Questions</h2>
    <h3>What is canonicalization?</h3>
    <p>Canonical tags tell Google which URL represents the master copy.</p>
</body>
</html>
HTML;

        Http::fake([
            $serpUrl => Http::response([
                'version' => '0.1.20240101',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'id' => '0101-test-task',
                        'status_code' => 20000,
                        'result' => [
                            [
                                'keyword' => 'technical seo guide',
                                'type' => 'organic',
                                'items_count' => 2,
                                'items' => [
                                    [
                                        'type' => 'organic',
                                        'rank_group' => 1,
                                        'rank_absolute' => 1,
                                        'domain' => 'competitor1.com',
                                        'title' => 'Ultimate Technical SEO Guide | Competitor One',
                                        'url' => 'https://competitor1.com/seo-guide',
                                        'description' => 'A comprehensive technical SEO guide.',
                                    ],
                                    [
                                        'type' => 'organic',
                                        'rank_group' => 2,
                                        'rank_absolute' => 2,
                                        'domain' => 'competitor2.com',
                                        'title' => 'Technical SEO Checklist 2026 | Competitor Two',
                                        'url' => 'https://competitor2.com/checklist',
                                        'description' => 'Top technical SEO tips.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://competitor1.com/*' => Http::response($competitor1Html, 200),
            'https://competitor2.com/*' => Http::response($competitor2Html, 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/brief", [
                'keyword' => 'technical seo guide',
            ]);

        $this->assertDatabaseHas('content_briefs', [
            'project_id' => $this->project->id,
            'keyword' => 'technical seo guide',
        ]);

        /** @var ContentBrief $brief */
        $brief = ContentBrief::query()->where('project_id', $this->project->id)->firstOrFail();

        $response->assertRedirect(route('workspaces.projects.keywords.brief', [
            $this->workspace,
            $this->project,
            'keyword' => 'technical seo guide',
        ]));

        $competitors = $brief->data['competitors'] ?? [];
        $this->assertCount(2, $competitors);
        $this->assertEquals('competitor1.com', $competitors[0]['domain']);
        $this->assertEquals(200, $competitors[0]['status']);
        $this->assertGreaterThan(0, $competitors[0]['wordCount']);
        $this->assertContains('Article', $competitors[0]['schemaTypes']);

        $this->assertEquals('competitor2.com', $competitors[1]['domain']);
        $this->assertContains('FAQPage', $competitors[1]['schemaTypes']);
        $this->assertContains('BreadcrumbList', $competitors[1]['schemaTypes']);

        // Check recommendations in brief data
        $recommendations = $brief->data['recommendations'] ?? [];
        $this->assertNotEmpty($recommendations['suggestedWordCount']);
        $this->assertNotEmpty($recommendations['keyH2Themes']);

        // Check markdown compiled content
        $this->assertStringContainsString('# SEO Content Brief: technical seo guide', $brief->markdown);
        $this->assertStringContainsString('Recommended Word Count', $brief->markdown);
        $this->assertStringContainsString('competitor1.com', $brief->markdown);

        // Check ProjectActivity log
        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'event_type' => 'content_brief.generated',
        ]);
    }

    public function test_generate_content_brief_gracefully_degrades_when_competitors_fail(): void
    {
        $serpUrl = 'https://sandbox.dataforseo.com/v3/serp/google/organic/live/advanced';

        Http::fake([
            $serpUrl => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'type' => 'organic',
                                        'rank_group' => 1,
                                        'domain' => 'working.com',
                                        'title' => 'Working Competitor',
                                        'url' => 'https://working.com/page',
                                    ],
                                    [
                                        'type' => 'organic',
                                        'rank_group' => 2,
                                        'domain' => 'notfound.com',
                                        'title' => '404 Competitor',
                                        'url' => 'https://notfound.com/missing',
                                    ],
                                    [
                                        'type' => 'organic',
                                        'rank_group' => 3,
                                        'domain' => 'forbidden.com',
                                        'title' => '403 Competitor',
                                        'url' => 'https://forbidden.com/blocked',
                                    ],
                                    [
                                        'type' => 'organic',
                                        'rank_group' => 4,
                                        'domain' => 'timeout.com',
                                        'title' => 'Timeout Competitor',
                                        'url' => 'https://timeout.com/hang',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://working.com/*' => Http::response('<html><body><h1>Working Guide</h1><p>Content here.</p></body></html>', 200),
            'https://notfound.com/*' => Http::response('Not Found', 404),
            'https://forbidden.com/*' => Http::response('Forbidden', 403),
            'https://timeout.com/*' => fn () => throw new ConnectionException('Connection timed out after 4000ms'),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/brief", [
                'keyword' => 'resilience test keyword',
            ]);

        $response->assertRedirect();

        /** @var ContentBrief $brief */
        $brief = ContentBrief::query()->where('project_id', $this->project->id)->firstOrFail();

        $competitors = $brief->data['competitors'] ?? [];
        $this->assertCount(4, $competitors);

        // Competitor 1: ok (status 200)
        $this->assertEquals(200, $competitors[0]['status']);
        $this->assertNotEmpty($competitors[0]['headings']['h1']);

        // Competitor 2: 404
        $this->assertEquals(404, $competitors[1]['status']);
        $this->assertStringContainsString('404', (string) $competitors[1]['error']);

        // Competitor 3: 403
        $this->assertEquals(403, $competitors[2]['status']);
        $this->assertStringContainsString('403', (string) $competitors[2]['error']);

        // Competitor 4: timeout
        $this->assertNull($competitors[3]['status']);
        $this->assertStringContainsString('timed out', (string) $competitors[3]['error']);
    }

    public function test_generate_content_brief_blocks_ssrf_competitor_urls(): void
    {
        $serpUrl = 'https://sandbox.dataforseo.com/v3/serp/google/organic/live/advanced';

        Http::fake([
            $serpUrl => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'type' => 'organic',
                                        'rank_group' => 1,
                                        'domain' => '127.0.0.1',
                                        'title' => 'Internal Localhost Exploit',
                                        'url' => 'http://127.0.0.1:8000/internal-admin',
                                    ],
                                    [
                                        'type' => 'organic',
                                        'rank_group' => 2,
                                        'domain' => '169.254.169.254',
                                        'title' => 'AWS Metadata Exploit',
                                        'url' => 'http://169.254.169.254/latest/meta-data',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/brief", [
                'keyword' => 'ssrf security test',
            ]);

        $response->assertRedirect();

        /** @var ContentBrief $brief */
        $brief = ContentBrief::query()->where('project_id', $this->project->id)->firstOrFail();

        $competitors = $brief->data['competitors'] ?? [];
        $this->assertCount(2, $competitors);

        $this->assertNull($competitors[0]['status']);
        $this->assertStringContainsString('SSRF', (string) $competitors[0]['error']);

        $this->assertNull($competitors[1]['status']);
        $this->assertStringContainsString('SSRF', (string) $competitors[1]['error']);
    }

    private function briefUrl(): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/brief";
    }

    /**
     * @param  list<array{0: string, 1: string}>  $organic  [domain, url]
     * @return array<string, mixed>
     */
    private function serpResponse(array $organic): array
    {
        $items = [];
        foreach ($organic as $i => [$domain, $url]) {
            $items[] = ['type' => 'organic', 'rank_group' => $i + 1, 'domain' => $domain, 'title' => ucfirst($domain), 'url' => $url];
        }

        return ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]];
    }

    public function test_generating_a_brief_queues_it_and_returns_at_once(): void
    {
        Queue::fake();
        Http::fake();

        $this->actingAs($this->user)
            ->postJson($this->briefUrl(), ['keyword' => 'Technical SEO Audit'])
            ->assertStatus(202)
            ->assertJson(['keyword' => 'technical seo audit', 'status' => 'pending']);

        // Nothing paid or fetched inside the request.
        Http::assertNothingSent();
        Queue::assertPushedOn('serp', GenerateContentBriefJob::class);

        $brief = ContentBrief::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(ContentBrief::STATUS_PENDING, $brief->status);
        $this->assertNull($brief->recommended_words_min);

        // The page shows it as pending rather than as an empty brief.
        $this->actingAs($this->user)
            ->get($this->briefUrl().'?keyword=technical+seo+audit')
            ->assertInertia(fn ($page) => $page
                ->where('brief.status', 'pending')
                ->where('brief.data', null));

        // A second click while it runs doesn't queue (or pay for) another.
        $this->actingAs($this->user)
            ->postJson($this->briefUrl(), ['keyword' => 'technical seo audit'])
            ->assertStatus(202);
        Queue::assertPushed(GenerateContentBriefJob::class, 1);
    }

    public function test_a_fresh_request_skips_the_shared_cache_in_the_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson($this->briefUrl(), ['keyword' => 'crawl budget', 'fresh' => true])
            ->assertStatus(202);
        Queue::assertPushed(GenerateContentBriefJob::class, fn (GenerateContentBriefJob $job): bool => $job->bypassCache);

        $this->actingAs($this->user)
            ->postJson($this->briefUrl(), ['keyword' => 'log analysis'])
            ->assertStatus(202);
        Queue::assertPushed(GenerateContentBriefJob::class, fn (GenerateContentBriefJob $job): bool => $job->brief->keyword === 'log analysis' && ! $job->bypassCache);
    }

    public function test_the_job_completes_the_brief_and_broadcasts(): void
    {
        Event::fake([ContentBriefUpdated::class]);
        Http::fake([
            'https://sandbox.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response($this->serpResponse([
                ['working.com', 'https://working.com/page'],
            ]), 200),
            'https://working.com/*' => Http::response('<html><body><h1>Guide</h1><h2>Setup</h2><p>'.str_repeat('word ', 1500).'</p></body></html>', 200),
        ]);

        $this->actingAs($this->user)->post($this->briefUrl(), ['keyword' => 'job keyword'])->assertRedirect();

        $brief = ContentBrief::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(ContentBrief::STATUS_COMPLETED, $brief->status);
        $this->assertFalse($brief->data['incomplete']);
        $this->assertNotNull($brief->recommended_words_min);
        Event::assertDispatched(ContentBriefUpdated::class, fn (ContentBriefUpdated $e): bool => $e->briefId === $brief->id && $e->status === 'completed');
    }

    public function test_a_refresh_uses_the_projects_current_market_and_language(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response($this->serpResponse([
                ['working.com', 'https://working.com/page'],
            ]), 200),
            'https://working.com/*' => Http::response('<html><body><h1>Guide</h1><p>'.str_repeat('word ', 800).'</p></body></html>', 200),
        ]);

        $this->actingAs($this->user)->post($this->briefUrl(), ['keyword' => 'market keyword'])->assertRedirect();

        $this->project->update(['location_code' => 2826, 'language_code' => 'de']);

        $this->actingAs($this->user)->post($this->briefUrl(), ['keyword' => 'market keyword'])->assertRedirect();

        $brief = ContentBrief::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(2826, (int) $brief->location_code);
        $this->assertSame('de', $brief->language_code);
        Http::assertSent(function (Request $request): bool {
            $task = $request->data()[0] ?? [];

            return str_contains($request->url(), '/v3/serp/') && ($task['location_code'] ?? null) === 2826 && ($task['language_code'] ?? null) === 'de';
        });
    }

    public function test_a_brief_with_no_fetched_pages_says_so_instead_of_inventing_a_target(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response($this->serpResponse([
                ['notfound.com', 'https://notfound.com/missing'],
                ['timeout.com', 'https://timeout.com/hang'],
            ]), 200),
            'https://notfound.com/*' => Http::response('Not Found', 404),
            'https://timeout.com/*' => fn () => throw new ConnectionException('Connection timed out after 6000ms'),
        ]);

        $this->actingAs($this->user)->post($this->briefUrl(), ['keyword' => 'unreachable keyword'])->assertRedirect();

        $brief = ContentBrief::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(ContentBrief::STATUS_COMPLETED, $brief->status);
        $this->assertTrue($brief->data['incomplete']);
        $this->assertStringContainsString('could be fetched', (string) $brief->data['incompleteReason']);
        $this->assertNull($brief->recommended_words_min);
        $this->assertNull($brief->recommended_words_max);
        $this->assertNull($brief->data['metrics']['avgWordCountTop10']);
        $this->assertNull($brief->data['recommendations']['suggestedWordCount']);
        $this->assertStringNotContainsString('1850', $brief->markdown);
        $this->assertStringContainsString('Recommended Word Count:** Not available', $brief->markdown);
    }

    public function test_a_spend_refusal_fails_the_brief_with_its_message(): void
    {
        $this->mock(DataForSeoClient::class, fn (MockInterface $mock) => $mock->shouldIgnoreMissing(true)
            ->shouldReceive('serpLive')
            ->andThrow(new SpendRefusedException('This workspace has used its monthly SEO data budget.')));
        Http::fake();

        $this->actingAs($this->user)
            ->post($this->briefUrl(), ['keyword' => 'over budget keyword'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $brief = ContentBrief::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(ContentBrief::STATUS_FAILED, $brief->status);
        $this->assertSame('This workspace has used its monthly SEO data budget.', $brief->error);
        Http::assertNothingSent();
    }

    public function test_download_content_brief_markdown(): void
    {
        ContentBrief::query()->create([
            'project_id' => $this->project->id,
            'keyword' => 'coqui rank tracker',
            'location_code' => 2840,
            'language_code' => 'en',
            'search_volume' => 1200,
            'cpc' => 2.50,
            'recommended_words_min' => 1500,
            'recommended_words_max' => 2500,
            'data' => [
                'keyword' => 'coqui rank tracker',
                'searchVolume' => 1200,
                'cpc' => 2.50,
                'metrics' => [],
                'recommendations' => [],
                'competitors' => [],
            ],
            'markdown' => "# SEO Content Brief: coqui rank tracker\n\nTarget word count: 1500 words.",
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/keywords/brief/download?keyword=".urlencode('coqui rank tracker'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $this->assertStringContainsString('filename=content-brief-coqui-rank-tracker.md', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('# SEO Content Brief: coqui rank tracker', $response->streamedContent());
    }

    public function test_html_outline_extractor_handles_rich_markup(): void
    {
        $extractor = new HtmlPageOutlineExtractor;

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>SEO Tools Comparison</title>
    <meta name="description" content="Detailed comparison of top SEO tools in 2026." />
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            { "@type": "Product", "name": "Coqui Rank Tracker" },
            { "@type": "AggregateRating", "ratingValue": "4.9" }
        ]
    }
    </script>
</head>
<body>
    <header>
        <nav>
            <a href="/features">Features</a>
            <a href="https://external-partner.com/review">External Review</a>
        </nav>
    </header>
    <main>
        <h1>Best SEO Platforms 2026</h1>
        <p>Choosing an SEO platform requires evaluating rank tracking, crawling, and backlink features.</p>
        <h2>Enterprise Crawlers</h2>
        <p>Speed matters when auditing hundreds of thousands of URLs.</p>
        <h3>Headless Browser Crawling</h3>
        <p>Rendering JavaScript client-side requires worker infrastructure.</p>
    </main>
    <script>
        console.log("Ignore script text in word count");
    </script>
    <style>
        .hidden { display: none; }
    </style>
</body>
</html>
HTML;

        $outline = $extractor->extract($html, 'https://example.com/blog/seo-tools');

        $this->assertEquals('SEO Tools Comparison', $outline['title']);
        $this->assertEquals('Detailed comparison of top SEO tools in 2026.', $outline['description']);
        $this->assertGreaterThan(20, $outline['word_count']);
        $this->assertCount(1, $outline['headings']['h1']);
        $this->assertEquals('Best SEO Platforms 2026', $outline['headings']['h1'][0]);
        $this->assertCount(1, $outline['headings']['h2']);
        $this->assertEquals('Enterprise Crawlers', $outline['headings']['h2'][0]);
        $this->assertCount(1, $outline['headings']['h3']);
        $this->assertEquals('Headless Browser Crawling', $outline['headings']['h3'][0]);

        $this->assertContains('Product', $outline['schema_types']);
        $this->assertContains('AggregateRating', $outline['schema_types']);
        $this->assertEquals(1, $outline['internal_links_count']);
        $this->assertEquals(1, $outline['external_links_count']);
    }
}
