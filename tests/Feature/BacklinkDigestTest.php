<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Models\BacklinkAlert;
use App\Domains\Backlinks\Models\BacklinkHistorySnapshot;
use App\Domains\Backlinks\Notifications\BacklinkDigestNotification;
use App\Domains\Backlinks\Services\BacklinkDigestService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BacklinkDigestTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.slack.com/services/T000/B000/abc123XYZ';

    private User $owner;

    private User $viewer;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'plan_tier' => 'pro',
            'name' => 'SEO Agency',
            'slug' => 'seo-agency',
            'owner_id' => $this->owner->id,
            'monthly_dfs_budget_usd' => 100.00,
        ]);
        $this->workspace->members()->attach($this->viewer->id, ['role' => 'viewer']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui Analytics',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/backlinks{$path}";
    }

    public function test_any_member_can_subscribe_and_unsubscribe_themselves(): void
    {
        $this->actingAs($this->viewer)->post($this->url('/digest/subscription'), ['subscribed' => true])->assertRedirect();
        $this->actingAs($this->viewer)->post($this->url('/digest/subscription'), ['subscribed' => true])->assertRedirect();
        $this->assertSame([$this->viewer->id], $this->project->backlinkDigestSubscribers()->pluck('users.id')->all());

        $this->actingAs($this->viewer)->post($this->url('/digest/subscription'), ['subscribed' => false])->assertRedirect();
        $this->assertSame(0, $this->project->backlinkDigestSubscribers()->count());
    }

    public function test_outsiders_cannot_subscribe(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post($this->url('/digest/subscription'), ['subscribed' => true])->assertForbidden();
        $this->assertSame(0, $this->project->backlinkDigestSubscribers()->count());
    }

    public function test_only_editors_can_set_the_slack_webhook(): void
    {
        $this->actingAs($this->viewer)->put($this->url('/digest/slack'), ['webhook_url' => self::WEBHOOK])->assertForbidden();
        $this->assertNull($this->project->fresh()?->backlink_digest_slack_webhook);

        $this->actingAs($this->owner)->put($this->url('/digest/slack'), ['webhook_url' => self::WEBHOOK])->assertSessionHasNoErrors();
        $this->assertSame(self::WEBHOOK, $this->project->fresh()?->backlink_digest_slack_webhook);

        // Stored encrypted.
        $raw = (string) $this->project->newQuery()->whereKey($this->project->id)->toBase()->value('backlink_digest_slack_webhook');
        $this->assertStringNotContainsString('hooks.slack.com', $raw);

        $this->actingAs($this->owner)->put($this->url('/digest/slack'), ['webhook_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->project->fresh()?->backlink_digest_slack_webhook);
    }

    public function test_only_slack_incoming_webhook_urls_are_accepted(): void
    {
        foreach ([
            'http://hooks.slack.com/services/T000/B000/abc',
            'https://hooks.slack.com.evil.example/services/T000/B000/abc',
            'https://evil.example/?u=https://hooks.slack.com/services/T000',
            'https://hooks.slack.com/api/chat.postMessage',
            'https://hooks.slack.com/services/T000/../../admin',
            'https://user@hooks.slack.com/services/T000/B000/abc',
            'https://169.254.169.254/latest/meta-data',
        ] as $bad) {
            $this->assertFalse(BacklinkDigestService::isSlackWebhook($bad), $bad);
            $this->actingAs($this->owner)->put($this->url('/digest/slack'), ['webhook_url' => $bad])->assertSessionHasErrors('webhook_url');
        }

        $this->assertNull($this->project->fresh()?->backlink_digest_slack_webhook);
        $this->assertTrue(BacklinkDigestService::isSlackWebhook(self::WEBHOOK));
    }

    public function test_the_page_shows_digest_settings_without_exposing_the_webhook(): void
    {
        Http::fake(['*' => Http::response(['status_code' => 20000, 'tasks' => [['result' => [['items' => []]]]]])]);
        $this->project->forceFill(['backlink_digest_slack_webhook' => self::WEBHOOK])->save();
        $this->project->backlinkDigestSubscribers()->attach($this->viewer->id);

        $response = $this->actingAs($this->viewer)->get($this->url());
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('digest.subscribed', true)
            ->where('digest.slackConfigured', true)
            ->where('digest.canManageSlack', false)
        );
        $this->assertStringNotContainsString('abc123XYZ', (string) $response->getContent());

        $this->actingAs($this->owner)->get($this->url())->assertOk()->assertInertia(fn ($page) => $page
            ->where('digest.subscribed', false)
            ->where('digest.canManageSlack', true)
        );
    }

    public function test_the_weekly_digest_goes_to_subscribers_and_slack_once_a_week(): void
    {
        Notification::fake();
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);

        $former = User::factory()->create();
        $this->project->backlinkDigestSubscribers()->attach([$this->viewer->id, $this->owner->id, $former->id]);
        $this->project->forceFill(['backlink_digest_slack_webhook' => self::WEBHOOK])->save();

        BacklinkHistorySnapshot::query()->create([
            'project_id' => $this->project->id,
            'snapshot_date' => now()->subDays(2)->toDateString(),
            'backlinks_count' => 100,
            'referring_domains_count' => 20,
            'new_backlinks' => 7,
            'lost_backlinks' => 3,
            'new_referring_domains' => 4,
            'lost_referring_domains' => 1,
            'rank' => 200,
        ]);
        BacklinkAlert::query()->create([
            'project_id' => $this->project->id,
            'type' => 'backlink_drop',
            'severity' => 'warning',
            'dedupe_key' => 'drop:x',
            'message' => 'Sudden backlink drop detected for coquiseo.com: lost 30 links <today>.',
            'meta' => [],
            'detected_at' => now()->subDay(),
        ]);
        ProjectActivity::log(
            project: $this->project,
            eventType: 'backlink.state_changed',
            title: 'Backlink state changed',
            description: 'Link from partner.example is now missing.',
            properties: ['new_state' => 'missing'],
        );

        $this->artisan('backlinks:send-digests')->assertSuccessful()->expectsOutputToContain('Sent 1 backlink digests');

        // The former member (not in the workspace) gets nothing.
        Notification::assertSentTo([$this->viewer, $this->owner], BacklinkDigestNotification::class, function (BacklinkDigestNotification $n): bool {
            return $n->digest['new_referring_domains'] === 4
                && $n->digest['lost_referring_domains'] === 1
                && count($n->digest['alerts']) === 1
                && $n->digest['link_changes_total'] === 1;
        });
        Notification::assertNotSentTo($former, BacklinkDigestNotification::class);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r): bool {
            $text = (string) ($r->data()['text'] ?? '');

            return $r->url() === self::WEBHOOK
                && str_contains($text, 'coquiseo.com')
                && str_contains($text, '+4 new, -1 lost')
                && str_contains($text, '&lt;today&gt;')
                && str_contains($text, 'partner.example');
        });
        $this->assertNotNull($this->project->fresh()?->backlink_digest_sent_at);

        // A repeated run in the same week sends nothing.
        $this->artisan('backlinks:send-digests')->assertSuccessful()->expectsOutputToContain('Sent 0 backlink digests');
        Http::assertSentCount(1);
        Notification::assertSentTimes(BacklinkDigestNotification::class, 2);

        $this->travel(7)->days();
        $this->artisan('backlinks:send-digests')->assertSuccessful()->expectsOutputToContain('Sent 1 backlink digests');
        Http::assertSentCount(2);
    }

    public function test_the_digest_email_renders(): void
    {
        $digest = app(BacklinkDigestService::class)->build($this->project, now()->subDays(7));
        $mail = (new BacklinkDigestNotification($this->project, $digest))->toMail($this->viewer);

        $this->assertSame('Weekly backlink digest for coquiseo.com', $mail->subject);
        $this->assertStringContainsString('/backlinks', (string) $mail->actionUrl);
        $this->assertContains('No alerts this week.', $mail->introLines);
    }

    public function test_a_slack_failure_does_not_stop_the_email(): void
    {
        Notification::fake();
        Http::fake(['hooks.slack.com/*' => Http::response('no_service', 404)]);
        $this->project->backlinkDigestSubscribers()->attach($this->viewer->id);
        $this->project->forceFill(['backlink_digest_slack_webhook' => self::WEBHOOK])->save();

        $result = app(BacklinkDigestService::class)->send($this->project);

        $this->assertSame(['emailed' => 1, 'slack' => false], $result);
        Notification::assertSentTo($this->viewer, BacklinkDigestNotification::class);
    }

    public function test_digests_skip_workspaces_without_backlink_intelligence(): void
    {
        Notification::fake();
        $this->workspace->forceFill(['plan_tier' => 'free'])->save();
        $this->project->backlinkDigestSubscribers()->attach($this->viewer->id);

        $this->artisan('backlinks:send-digests')->assertSuccessful()->expectsOutputToContain('Sent 0 backlink digests');

        Notification::assertNothingSent();
    }
}
