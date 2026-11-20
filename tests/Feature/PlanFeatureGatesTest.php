<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\PlanFeature;
use App\Domains\Crawls\Jobs\DispatchCloudCrawlJob;
use App\Domains\Crawls\Models\CrawlDiffReport;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Integrations\Jobs\SyncGoogleSearchConsoleJob;
use App\Domains\Integrations\Models\BotCrawlLog;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Models\SearchPerformanceQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\ContentBrief;
use App\Features\RunnrIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Each Pennant plan feature gates its surfaces server-side: Free is blocked, a
 * paying tier is allowed, and downgrading takes access away again.
 */
class PlanFeatureGatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Gated Workspace',
            'slug' => 'gated-workspace',
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Gated Site',
            'domain' => 'gated-site.com',
            'canonical_url' => 'https://gated-site.com/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    public function test_every_pennant_feature_is_named_by_the_plan_feature_enum(): void
    {
        $defined = Feature::defined();
        sort($defined);

        $named = array_map(fn (PlanFeature $feature): string => $feature->value, PlanFeature::cases());
        sort($named);

        $this->assertSame($defined, $named);
    }

    public function test_feature_values_follow_plan_changes_without_a_manual_flush(): void
    {
        $this->assertFalse($this->workspace->hasFeature(PlanFeature::ContentBriefs));

        $this->workspace->applyPlanTier('pro');
        $this->assertTrue($this->workspace->hasFeature(PlanFeature::ContentBriefs));

        $this->workspace->applyPlanTier('free');
        $this->assertFalse($this->workspace->hasFeature(PlanFeature::ContentBriefs));

        $this->workspace->update(['has_runnr_addon' => true]);
        $this->assertTrue($this->workspace->hasFeature(PlanFeature::RunnrSmokeTests));
    }

    public function test_content_briefs_are_gated(): void
    {
        $this->assertWebGate('GET', $this->projectUrl('/keywords/brief'), 'pro', 'Content briefs');
    }

    public function test_free_plan_cannot_generate_a_paid_content_brief(): void
    {
        Http::fake();

        $this->assertSentToBilling(
            $this->actingAs($this->owner)->post($this->projectUrl('/keywords/brief'), ['keyword' => 'seo audit']),
            'Content briefs',
        );

        Http::assertNothingSent();
        $this->assertSame(0, ContentBrief::query()->count());
    }

    public function test_the_personal_plan_unlocks_the_pro_feature_pages(): void
    {
        $this->workspace->applyPlanTier('personal');

        foreach (['/keywords/brief', '/backlinks/watchlist', '/bot-logs'] as $path) {
            $this->actingAs($this->owner)->get($this->projectUrl($path))->assertOk();
        }
    }

    public function test_backlink_intelligence_is_gated(): void
    {
        $this->assertWebGate('GET', $this->projectUrl('/backlinks/watchlist'), 'pro', 'Backlink intelligence');
    }

    public function test_free_plan_cannot_refresh_or_import_backlinks(): void
    {
        Http::fake();

        foreach (['/backlinks/refresh', '/backlinks/intersect/refresh', '/backlinks/watchlist/import-dfs', '/backlinks/watchlist/verify'] as $path) {
            $this->assertSentToBilling($this->actingAs($this->owner)->post($this->projectUrl($path)), 'Backlink intelligence');
        }

        Http::assertNothingSent();
    }

    public function test_free_gets_traffic_attribution_and_nothing_else_paid(): void
    {
        $this->workspace->applyPlanTier('free');

        $this->assertSame(
            [
                'content-briefs' => false,
                'backlink-intelligence' => false,
                'traffic-attribution' => true,
                'white-label-reports' => false,
                'priority-queue' => false,
                'runnr-smoke-tests' => false,
                'bot-telemetry' => false,
            ],
            PlanFeature::flagsFor($this->workspace),
        );

        $this->actingAs($this->owner)->get($this->projectUrl('/analytics/performance'))->assertOk();
    }

    public function test_free_plan_can_connect_search_and_analytics_integrations(): void
    {
        Queue::fake();
        Http::fake([
            'plausible.io/*' => Http::response(['results' => []]),
            '*' => Http::response([]),
        ]);

        $this->actingAs($this->owner)->post($this->projectUrl('/integrations/plausible/connect'), ['site_id' => $this->project->domain, 'api_key' => 'k'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(ProjectIntegration::query()->where('provider', 'plausible')->exists());
    }

    public function test_keyword_page_shows_the_search_performance_overlay_on_every_plan(): void
    {
        $this->project->trackedKeywords()->create([
            'keyword' => 'cloud rank tracking',
            'device' => 'desktop',
            'location_code' => 2840,
            'language_code' => 'en',
            'cadence' => 'daily',
            'is_active' => true,
        ]);
        SearchPerformanceQuery::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'query' => 'cloud rank tracking',
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'clicks' => 12,
            'impressions' => 120,
            'ctr' => 0.10,
            'position' => 3.0,
        ]);

        $this->actingAs($this->owner)->get($this->projectUrl('/keywords'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('keywords.0.gsc_performance.clicks', 12)->etc());
    }

    public function test_scheduled_search_console_sync_includes_free_workspaces(): void
    {
        Queue::fake();
        $integration = $this->gscIntegration();

        Artisan::call('search-console:sync');

        Queue::assertPushed(SyncGoogleSearchConsoleJob::class, fn (SyncGoogleSearchConsoleJob $job): bool => $job->integration->is($integration));
    }

    public function test_bot_telemetry_is_gated(): void
    {
        $this->assertWebGate('GET', $this->projectUrl('/bot-logs'), 'pro', 'Bot crawler telemetry');
    }

    public function test_bot_telemetry_api_returns_a_json_upgrade_error(): void
    {
        Sanctum::actingAs($this->owner, ['projects:read']);
        $url = "/api/v1/projects/{$this->project->uuid}/telemetry/bot-logs";

        $this->assertJsonUpgradeError($this->getJson($url), PlanFeature::BotTelemetry);

        $this->workspace->applyPlanTier('pro');
        $this->getJson($url)->assertOk();

        $this->workspace->applyPlanTier('free');
        $this->assertJsonUpgradeError($this->getJson($url), PlanFeature::BotTelemetry);
    }

    public function test_runnr_telemetry_is_stored_even_when_the_plan_lacks_bot_telemetry(): void
    {
        // Runnr routes 404 until the global launch flag is on; these tests
        // check the plan gate behind it.
        RunnrIntegration::activate();
        config()->set('services.runnr.telemetry_token', 'runnr-platform-token');
        $this->project->update(['runnr_site_id' => 'site_gated_1']);
        $payload = [
            'siteId' => 'site_gated_1',
            'entries' => [[
                'botFamily' => 'googlebot',
                'statusBucket' => 200,
                'hitCount' => 9,
                'bucketHour' => now()->startOfHour()->toIso8601String(),
            ]],
        ];

        $this->withToken('runnr-platform-token')
            ->postJson('/api/v1/telemetry/bot-logs', $payload)
            ->assertStatus(202);
        $this->assertSame(1, BotCrawlLog::query()->count());

        // A person pushing telemetry themselves is refused instead.
        $this->withoutToken()->actingAs($this->owner);
        $this->assertJsonUpgradeError(
            $this->postJson('/api/v1/telemetry/bot-logs', $payload),
            PlanFeature::BotTelemetry,
        );
        $this->assertSame(1, BotCrawlLog::query()->count());
    }

    public function test_white_label_share_links_are_gated(): void
    {
        [$baseline, $target] = $this->crawlRuns();
        $share = fn (): TestResponse => $this->actingAs($this->owner)->postJson($this->projectUrl('/crawls/compare/share'), [
            'baseline_id' => $baseline->external_crawl_id,
            'target_id' => $target->external_crawl_id,
        ]);

        $this->workspace->applyPlanTier('pro');
        $this->assertJsonUpgradeError($share(), PlanFeature::WhiteLabelReports);

        $this->workspace->applyPlanTier('agency');
        $token = $share()->assertCreated()->json('shareToken');
        $this->get("/reports/crawls/diff/{$token}")->assertOk();

        $this->workspace->applyPlanTier('free');
        $this->assertJsonUpgradeError($share(), PlanFeature::WhiteLabelReports);
        // Links shared before the downgrade read as expired.
        $this->get("/reports/crawls/diff/{$token}")->assertNotFound();
        $this->assertSame(1, CrawlDiffReport::query()->count());
    }

    public function test_runnr_smoke_test_api_is_gated(): void
    {
        RunnrIntegration::activate();
        Sanctum::actingAs($this->owner, ['projects:read']);
        $url = "/api/v1/projects/{$this->project->uuid}/deploy-smoke/latest";

        $this->assertJsonUpgradeError($this->getJson($url), PlanFeature::RunnrSmokeTests);

        $this->workspace->update(['has_runnr_addon' => true]);
        $this->getJson($url)->assertOk();

        $this->workspace->update(['has_runnr_addon' => false]);
        $this->assertJsonUpgradeError($this->getJson($url), PlanFeature::RunnrSmokeTests);
    }

    public function test_smoke_badge_reports_inactive_without_runnr_smoke_tests(): void
    {
        RunnrIntegration::activate();
        $badge = '/api/v1/deploy-smoke/badge/'.$this->project->deployBadgeToken();

        $this->get($badge)->assertOk()->assertSee('inactive', false);

        $this->workspace->update(['has_runnr_addon' => true]);
        $this->get($badge)->assertOk()->assertSee('runnr deploy: none', false);
    }

    public function test_deploy_webhook_skips_smoke_crawls_for_workspaces_without_the_feature(): void
    {
        RunnrIntegration::activate();
        Http::fake();
        config()->set('services.runnr.webhook_secret', 'webhook-secret');
        $this->project->update(['runnr_site_id' => 'site_gated_1']);
        $body = json_encode(['site_id' => 'site_gated_1'], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/webhooks/runnr/deploy', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RUNNR_SIGNATURE' => hash_hmac('sha256', $body, 'webhook-secret'),
        ], $body)
            ->assertStatus(202)
            ->assertJson(['status' => 'ignored', 'reason' => 'feature_not_in_plan']);

        $this->assertSame(0, CrawlRun::query()->count());
        Http::assertNothingSent();
    }

    public function test_linking_a_runnr_site_does_not_unlock_runnr_features(): void
    {
        RunnrIntegration::activate();
        $this->actingAs($this->owner)
            ->post($this->projectUrl('/integrations/runnr/connect'), ['runnr_site_id' => 'site_typed_in'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('site_typed_in', $this->project->refresh()->runnr_site_id);
        $this->assertFalse($this->workspace->hasFeature(PlanFeature::RunnrSmokeTests));
        $this->assertFalse($this->workspace->hasFeature(PlanFeature::BotTelemetry));
        $this->assertSentToBilling($this->actingAs($this->owner)->get($this->projectUrl('/bot-logs')), 'Bot crawler telemetry');

        // Only billing state unlocks them.
        $this->workspace->update(['has_runnr_addon' => true]);
        $this->assertTrue($this->workspace->hasFeature(PlanFeature::RunnrSmokeTests));
        $this->assertTrue($this->workspace->hasFeature(PlanFeature::BotTelemetry));
    }

    public function test_a_runnr_site_linked_to_another_project_cannot_be_claimed(): void
    {
        RunnrIntegration::activate();
        $victim = User::factory()->create();
        $victimWorkspace = Workspace::query()->create([
            'name' => 'Victim Workspace',
            'slug' => 'victim-workspace',
            'owner_id' => $victim->id,
            'has_runnr_addon' => true,
        ]);
        Project::query()->create([
            'workspace_id' => $victimWorkspace->id,
            'name' => 'Victim Site',
            'domain' => 'victim-site.com',
            'canonical_url' => 'https://victim-site.com/',
            'runnr_site_id' => 'site_victim',
        ]);

        $this->actingAs($this->owner)
            ->from($this->projectUrl(''))
            ->post($this->projectUrl('/integrations/runnr/connect'), ['runnr_site_id' => 'site_victim'])
            ->assertSessionHasErrors('runnr_site_id');

        $this->assertNull($this->project->refresh()->runnr_site_id);

        // Re-saving a project's own link is still fine.
        $this->project->update(['runnr_site_id' => 'site_mine']);
        $this->actingAs($this->owner)
            ->post($this->projectUrl('/integrations/runnr/connect'), ['runnr_site_id' => 'site_mine'])
            ->assertSessionHasNoErrors();
    }

    public function test_priority_queue_routes_crawls_by_plan(): void
    {
        Queue::fake();
        $dispatch = function (): void {
            CrawlRun::query()->update(['status' => 'completed']);
            $this->actingAs($this->owner)->post($this->projectUrl('/crawls/dispatch'))->assertRedirect();
        };

        $this->workspace->applyPlanTier('pro');
        $dispatch();

        $this->workspace->applyPlanTier('agency');
        $dispatch();

        // Downgrading drops back to the shared queue.
        $this->workspace->applyPlanTier('pro');
        $dispatch();

        $this->assertSame(
            [DispatchCloudCrawlJob::QUEUE, DispatchCloudCrawlJob::PRIORITY_QUEUE, DispatchCloudCrawlJob::QUEUE],
            Queue::pushed(DispatchCloudCrawlJob::class)->map(fn (DispatchCloudCrawlJob $job): ?string => $job->queue)->values()->all(),
        );
    }

    public function test_a_paid_workspace_in_the_url_cannot_unlock_a_free_workspaces_project(): void
    {
        $paid = Workspace::query()->create([
            'name' => 'Paid Workspace',
            'slug' => 'paid-workspace',
            'owner_id' => $this->owner->id,
            'plan_tier' => 'agency',
        ]);
        $paid->members()->attach($this->owner, ['role' => 'owner']);

        $this->actingAs($this->owner)
            ->get("/workspaces/{$paid->uuid}/projects/{$this->project->uuid}/keywords/brief")
            ->assertNotFound();
    }

    public function test_people_outside_the_workspace_get_a_403_not_the_billing_page(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get($this->projectUrl('/keywords/brief'))->assertForbidden();
        $this->actingAs($outsider)->postJson($this->projectUrl('/crawls/compare/share'), [])->assertForbidden();
    }

    public function test_pages_receive_the_workspace_plan_features(): void
    {
        $this->actingAs($this->owner)->get($this->projectUrl(''))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan.tier', 'free')
                ->where('plan.features.content-briefs', false)
                ->where('plan.features.white-label-reports', false)
                ->where('plan.billingUrl', route('workspaces.billing.index', $this->workspace))
                ->etc());

        $this->workspace->applyPlanTier('agency');

        $this->actingAs($this->owner)->get($this->projectUrl(''))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan.tier', 'agency')
                ->where('plan.features.content-briefs', true)
                ->where('plan.features.white-label-reports', true)
                ->etc());
    }

    /**
     * Free is sent to billing, the paying tier gets in, and a downgrade sends
     * the workspace back to billing.
     */
    private function assertWebGate(string $method, string $uri, string $paidTier, string $label): void
    {
        $this->assertSentToBilling($this->actingAs($this->owner)->call($method, $uri), $label);

        $this->workspace->applyPlanTier($paidTier);
        $this->actingAs($this->owner)->call($method, $uri)->assertOk();

        $this->workspace->applyPlanTier('free');
        $this->assertSentToBilling($this->actingAs($this->owner)->call($method, $uri), $label);
    }

    private function assertSentToBilling(TestResponse $response, ?string $label = null): void
    {
        $response->assertRedirect(route('workspaces.billing.index', $this->workspace));

        $message = (string) session('status');
        $this->assertStringContainsString('not included in the Free plan', $message);
        if ($label !== null) {
            $this->assertStringStartsWith($label, $message);
        }
    }

    private function assertJsonUpgradeError(TestResponse $response, PlanFeature $feature): void
    {
        $response->assertForbidden()->assertJson([
            'feature' => $feature->value,
            'upgrade_url' => route('workspaces.billing.index', $this->workspace),
        ]);
    }

    private function projectUrl(string $suffix): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}{$suffix}";
    }

    private function gscIntegration(): ProjectIntegration
    {
        return ProjectIntegration::query()->create([
            'project_id' => $this->project->id,
            'provider' => 'gsc',
            'site_url' => 'https://gated-site.com/',
            'status' => 'connected',
            'credentials_encrypted' => ['access_token' => 'tok', 'refresh_token' => 'ref'],
        ]);
    }

    /**
     * @return array{CrawlRun, CrawlRun}
     */
    private function crawlRuns(): array
    {
        $make = fn (int $urls): CrawlRun => CrawlRun::query()->create([
            'project_id' => $this->project->id,
            'external_crawl_id' => (string) Str::uuid(),
            'seed' => 'https://gated-site.com/',
            'seed_kind' => 'url',
            'source' => 'desktop',
            'status' => 'completed',
            'config' => ['maxDepth' => 1],
            'total_urls' => $urls,
        ]);

        return [$make(1), $make(2)];
    }
}
