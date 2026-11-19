<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Events\BacklinkAlertTriggered;
use App\Domains\Backlinks\Models\BacklinkHistorySnapshot;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacklinkTimelineTest extends TestCase
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

    public function test_timeline_page_renders_with_timeseries_and_velocity_props(): void
    {
        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'target' => 'coquiseo.com',
                                'rank' => 420,
                                'backlinks' => 1500,
                                'referring_pages' => 900,
                                'referring_domains' => 150,
                                'referring_main_domains' => 140,
                                'dofollow' => 1200,
                                'nofollow' => 300,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/referring_domains/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'domain' => 'techcrunch.com',
                                        'rank' => 850,
                                        'backlinks' => 20,
                                        'dofollow' => 20,
                                        'nofollow' => 0,
                                        'spam_score' => 2,
                                        'first_seen' => now()->subDays(5)->toIso8601String(),
                                        'last_seen' => now()->toIso8601String(),
                                    ],
                                    [
                                        'domain' => 'old-partner.org',
                                        'rank' => 410,
                                        'backlinks' => 5,
                                        'dofollow' => 5,
                                        'nofollow' => 0,
                                        'spam_score' => 5,
                                        'first_seen' => now()->subYears(2)->toIso8601String(),
                                        'last_seen' => now()->subDays(120)->toIso8601String(),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/anchors/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/history/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'date' => now()->subDays(10)->toDateString(),
                                        'backlinks' => 1400,
                                        'referring_domains' => 140,
                                        'new_backlinks' => 30,
                                        'lost_backlinks' => 5,
                                        'new_referring_domains' => 4,
                                        'lost_referring_domains' => 1,
                                        'rank' => 410,
                                    ],
                                    [
                                        'date' => now()->toDateString(),
                                        'backlinks' => 1500,
                                        'referring_domains' => 150,
                                        'new_backlinks' => 105,
                                        'lost_backlinks' => 5,
                                        'new_referring_domains' => 11,
                                        'lost_referring_domains' => 1,
                                        'rank' => 420,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks?days=90");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timeline', 2)
            ->where('selectedDays', 90)
            ->where('timeline.1.backlinks', 1500)
            ->where('timeline.1.new_backlinks', 105)
            ->has('newDomains', 1)
            ->where('newDomains.0.domain', 'techcrunch.com')
            ->has('lostDomains', 1)
            ->where('lostDomains.0.domain', 'old-partner.org')
            ->where('velocity.total_new', 1)
            ->where('velocity.total_lost', 1)
            ->where('velocity.net_velocity', 0)
        );

        // Verify snapshots are persisted in database
        $this->assertDatabaseHas('backlink_history_snapshots', [
            'project_id' => $this->project->id,
            'snapshot_date' => now()->toDateString(),
            'backlinks_count' => 1500,
            'referring_domains_count' => 150,
        ]);
    }

    public function test_timeline_serves_from_database_snapshots_without_calling_history_api(): void
    {
        // Pre-seed snapshots
        BacklinkHistorySnapshot::query()->create([
            'project_id' => $this->project->id,
            'snapshot_date' => now()->subDays(5)->toDateString(),
            'backlinks_count' => 800,
            'referring_domains_count' => 90,
            'new_backlinks' => 10,
            'lost_backlinks' => 2,
            'new_referring_domains' => 2,
            'lost_referring_domains' => 0,
            'rank' => 380,
        ]);

        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['backlinks' => 800, 'referring_domains' => 90, 'rank' => 380]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/referring_domains/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/anchors/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            // History endpoint should NOT be hit because snapshots already exist in DB
            'https://sandbox.dataforseo.com/v3/backlinks/history/live' => fn () => throw new \RuntimeException('History API should not be called!'),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks?days=30");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('timeline', 1)
            ->where('timeline.0.backlinks', 800)
            ->where('timeline.0.referring_domains', 90)
        );
    }

    public function test_sudden_backlink_drop_triggers_alert_and_event(): void
    {
        Event::fake([BacklinkAlertTriggered::class]);

        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['backlinks' => 100, 'referring_domains' => 20, 'rank' => 200]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/referring_domains/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/anchors/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/history/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'date' => now()->toDateString(),
                                        // Total 100 backlinks, lost 30 (30% drop, > 25 lost)
                                        'backlinks' => 100,
                                        'referring_domains' => 20,
                                        'new_backlinks' => 0,
                                        'lost_backlinks' => 30,
                                        'new_referring_domains' => 0,
                                        'lost_referring_domains' => 5,
                                        'rank' => 200,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('alerts', 1)
            ->where('alerts.0.type', 'backlink_drop')
            ->where('alerts.0.severity', 'warning')
        );

        Event::assertDispatched(BacklinkAlertTriggered::class, function (BacklinkAlertTriggered $event) {
            return $event->projectId === $this->project->id
                && $event->alertType === 'backlink_drop'
                && $event->severity === 'warning';
        });
    }

    public function test_spam_spike_triggers_critical_alert_and_event(): void
    {
        Event::fake([BacklinkAlertTriggered::class]);

        Http::fake([
            'https://sandbox.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['backlinks' => 500, 'referring_domains' => 50, 'rank' => 300]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/referring_domains/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'domain' => 'spam-site-1.xyz',
                                        'rank' => 10,
                                        'backlinks' => 10,
                                        'dofollow' => 10,
                                        'nofollow' => 0,
                                        'spam_score' => 75,
                                        'first_seen' => now()->subDays(2)->toIso8601String(),
                                        'last_seen' => now()->toIso8601String(),
                                    ],
                                    [
                                        'domain' => 'spam-site-2.top',
                                        'rank' => 8,
                                        'backlinks' => 5,
                                        'dofollow' => 5,
                                        'nofollow' => 0,
                                        'spam_score' => 65,
                                        'first_seen' => now()->subDays(1)->toIso8601String(),
                                        'last_seen' => now()->toIso8601String(),
                                    ],
                                    [
                                        'domain' => 'spam-site-3.click',
                                        'rank' => 5,
                                        'backlinks' => 20,
                                        'dofollow' => 20,
                                        'nofollow' => 0,
                                        'spam_score' => 80,
                                        'first_seen' => now()->subHours(6)->toIso8601String(),
                                        'last_seen' => now()->toIso8601String(),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/anchors/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/pages/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [['result' => [['items' => []]]]],
            ], 200),
            'https://sandbox.dataforseo.com/v3/backlinks/history/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'date' => now()->toDateString(),
                                        'backlinks' => 500,
                                        'referring_domains' => 50,
                                        'new_backlinks' => 35,
                                        'lost_backlinks' => 0,
                                        'new_referring_domains' => 3,
                                        'lost_referring_domains' => 0,
                                        'rank' => 300,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->id}/projects/{$this->project->id}/backlinks");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('alerts', 1)
            ->where('alerts.0.type', 'spam_spike')
            ->where('alerts.0.severity', 'critical')
        );

        Event::assertDispatched(BacklinkAlertTriggered::class, function (BacklinkAlertTriggered $event) {
            return $event->projectId === $this->project->id
                && $event->alertType === 'spam_spike'
                && $event->severity === 'critical';
        });
    }
}
