<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Backlinks\Services\BacklinkDigestService;
use App\Domains\Backlinks\Services\BacklinkProfileService;
use App\Domains\Backlinks\Services\BacklinkTimelineService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BacklinkController extends Controller
{
    public function __construct(
        private readonly BacklinkProfileService $backlinkService,
        private readonly BacklinkTimelineService $timelineService,
    ) {}

    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        Gate::authorize('view', $project);

        $days = (int) $request->input('days', 90);
        if ($days <= 0) {
            $days = 90;
        }

        /** @var User $user */
        $user = $request->user();

        $profile = $this->backlinkService->getBacklinkProfile($project, false);
        // Read-only members see stored history only.
        $timelineData = $this->timelineService->getTimelineData($project, $days, false, $user->can('spend', $project));

        return Inertia::render('Backlinks/Index', [
            'workspace' => [
                'id' => $workspace->uuid,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'project' => [
                'id' => $project->uuid,
                'name' => $project->name,
                'domain' => $project->domain,
                'canonicalUrl' => $project->canonical_url,
            ],
            'summary' => $profile['summary'],
            'referringDomains' => $profile['referring_domains'],
            'anchors' => $profile['anchors'],
            'topPages' => $profile['top_pages'],
            'brokenPages' => $profile['broken_pages_needing_redirects'],
            'crawlRun' => $profile['crawl_run'],
            'timeline' => $timelineData['timeline'],
            'newDomains' => $timelineData['new_domains'],
            'lostDomains' => $timelineData['lost_domains'],
            'velocity' => $timelineData['velocity'],
            'alerts' => $timelineData['alerts'],
            'selectedDays' => $days,
            'digest' => [
                'subscribed' => $project->backlinkDigestSubscribers()->where('users.id', $user->id)->exists(),
                'slackConfigured' => $project->backlink_digest_slack_webhook !== null,
                'canManageSlack' => $user->can('update', $project),
                'lastSentAt' => $project->backlink_digest_sent_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Subscribe or unsubscribe the current member from the weekly email digest.
     */
    public function updateDigestSubscription(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $data = $request->validate(['subscribed' => ['required', 'boolean']]);

        /** @var User $user */
        $user = $request->user();

        if ((bool) $data['subscribed']) {
            $project->backlinkDigestSubscribers()->syncWithoutDetaching([$user->id]);
        } else {
            $project->backlinkDigestSubscribers()->detach($user->id);
        }

        return back()->with('success', (bool) $data['subscribed']
            ? 'You will get the weekly backlink digest by email.'
            : 'You will no longer get the weekly backlink digest.');
    }

    /**
     * Set or clear the project's Slack incoming webhook for the digest.
     */
    public function updateDigestSlack(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'webhook_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && trim($value) !== '' && ! BacklinkDigestService::isSlackWebhook(trim($value))) {
                        $fail('Enter a Slack incoming webhook URL (https://hooks.slack.com/services/...).');
                    }
                },
            ],
        ]);

        $url = trim((string) ($data['webhook_url'] ?? ''));
        $project->forceFill(['backlink_digest_slack_webhook' => $url === '' ? null : $url])->save();

        return back()->with('success', $url === ''
            ? 'Slack digest turned off.'
            : 'The weekly backlink digest will be posted to Slack.');
    }

    public function refresh(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        ProviderNotConfiguredException::throwUnlessSeoDataConfigured();

        $days = (int) $request->input('days', 90);
        if ($days <= 0) {
            $days = 90;
        }

        $profile = $this->backlinkService->getBacklinkProfile($project, true);
        $this->timelineService->syncHistorySnapshots($project, $days);

        ProjectActivity::log(
            project: $project,
            eventType: 'backlinks.refreshed',
            title: 'Backlink profile refreshed',
            description: sprintf(
                'Refreshed backlink intelligence for %s (%d backlinks, %d referring domains).',
                $project->domain,
                $profile['summary']['backlinks'] ?? 0,
                $profile['summary']['referring_domains'] ?? 0
            ),
            properties: [
                'domain' => $project->domain,
                'backlinks' => $profile['summary']['backlinks'] ?? 0,
                'referring_domains' => $profile['summary']['referring_domains'] ?? 0,
                'rank' => $profile['summary']['rank'] ?? 0,
            ],
            user: $request->user(),
        );

        $redirectParams = [$workspace, $project];
        if ($request->has('days')) {
            $redirectParams['days'] = $days;
        }

        return redirect()->route('workspaces.projects.backlinks.index', $redirectParams)
            ->with('success', sprintf('Backlink profile and history timeline for %s successfully refreshed.', $project->domain));
    }
}
