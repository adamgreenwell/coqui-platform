<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Billing\PlanFeature;
use App\Domains\Integrations\Jobs\SyncBingWebmasterJob;
use App\Domains\Integrations\Jobs\SyncGoogleSearchConsoleJob;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\GoogleSearchConsoleClient;
use App\Domains\Integrations\Services\TrafficAttributionService;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProjectIntegrationController extends Controller
{
    private const GOOGLE_OAUTH_SESSION_PREFIX = 'google_oauth.';

    private const GOOGLE_OAUTH_STATE_TTL_SECONDS = 900;

    /**
     * The single redirect URI registered in Google Cloud Console.
     */
    private function googleRedirectUri(): string
    {
        $configured = trim((string) config('services.google.redirect_uri'));

        return $configured !== '' ? $configured : route('integrations.google.callback');
    }

    public function index(Workspace $workspace, Project $project): JsonResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('view', $project);

        $integrations = $project->integrations()
            ->select(['id', 'project_id', 'provider', 'site_url', 'external_identifier', 'status', 'last_synced_at', 'created_at'])
            ->get();

        return response()->json([
            'integrations' => $integrations,
        ]);
    }

    public function connectGoogle(
        Request $request,
        Workspace $workspace,
        Project $project,
        GoogleSearchConsoleClient $gscClient
    ): RedirectResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        // The callback URL is the same for every project, so the project travels
        // in server-side session state keyed by the unguessable OAuth state value.
        $state = Str::random(40);
        $request->session()->put(self::GOOGLE_OAUTH_SESSION_PREFIX.$state, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'issued_at' => now()->getTimestamp(),
        ]);

        $authUrl = $gscClient->getAuthorizationUrl($project, $this->googleRedirectUri(), $state);

        return redirect()->away($authUrl);
    }

    public function callbackGoogle(
        Request $request,
        GoogleSearchConsoleClient $gscClient
    ): RedirectResponse {
        $state = $request->query('state');
        $pending = is_string($state) && preg_match('/^[A-Za-z0-9]{40}$/', $state) === 1
            ? $request->session()->pull(self::GOOGLE_OAUTH_SESSION_PREFIX.$state)
            : null;

        $issuedAt = is_array($pending) ? (int) ($pending['issued_at'] ?? 0) : 0;
        if (! is_array($pending) || now()->getTimestamp() - $issuedAt > self::GOOGLE_OAUTH_STATE_TTL_SECONDS) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Invalid or expired Google OAuth state. Please try reconnecting.');
        }

        $workspace = Workspace::query()->findOrFail((int) ($pending['workspace_id'] ?? 0));
        $project = Project::query()->findOrFail((int) ($pending['project_id'] ?? 0));
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);
        // The callback has no workspace in its URL, so the route middleware can't
        // see it; check here so a plan change mid-OAuth can't connect anyway.
        $workspace->ensureFeature(PlanFeature::TrafficAttribution);

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            $errorDesc = $request->query('error_description') ?? $request->query('error') ?? 'Google authorization was denied.';

            return redirect()
                ->route('workspaces.projects.keywords.index', [$workspace, $project])
                ->with('error', (string) $errorDesc);
        }

        try {
            $tokens = $gscClient->exchangeCodeForTokens($code, $this->googleRedirectUri());

            /** @var ProjectIntegration $integration */
            $integration = ProjectIntegration::query()->firstOrNew([
                'project_id' => $project->id,
                'provider' => 'gsc',
            ]);

            $existingRefreshToken = $integration->getRefreshToken();
            $refreshToken = $tokens['refresh_token'] !== '' ? $tokens['refresh_token'] : ($existingRefreshToken ?? '');

            $integration->status = 'connected';
            $integration->credentials_encrypted = [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $refreshToken,
                'token_type' => $tokens['token_type'],
                'expires_at' => Carbon::now()->addSeconds($tokens['expires_in'])->toIso8601String(),
            ];
            $integration->save();

            // Auto-detect matching Search Console site property
            try {
                $matchedSite = $gscClient->findMatchingSiteProperty($integration, $project->domain);
                if ($matchedSite !== null) {
                    $integration->update([
                        'site_url' => $matchedSite,
                        'external_identifier' => $matchedSite,
                    ]);
                } else {
                    $defaultUrl = 'sc-domain:'.$project->domain;
                    $integration->update([
                        'site_url' => $defaultUrl,
                        'external_identifier' => $defaultUrl,
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('Failed to detect Search Console property for project '.$project->id.': '.$e->getMessage());
            }

            ProjectActivity::log(
                project: $project,
                eventType: 'integration.connected',
                title: 'Google Search Console Connected',
                description: 'Google Search Console integration connected successfully.',
                properties: [
                    'provider' => 'gsc',
                    'site_url' => $integration->site_url,
                ],
            );

            // Dispatch background initial sync
            SyncGoogleSearchConsoleJob::dispatch($integration);

            return redirect()
                ->route('workspaces.projects.keywords.index', [$workspace, $project])
                ->with('success', 'Google Search Console connected successfully. Data sync has started in the background.');
        } catch (Throwable $e) {
            Log::error('GSC OAuth callback error: '.$e->getMessage());

            return redirect()
                ->route('workspaces.projects.keywords.index', [$workspace, $project])
                ->with('error', 'Failed to connect Google Search Console: '.$e->getMessage());
        }
    }

    public function connectBing(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'api_key' => ['required', 'string', 'max:255'],
            'site_url' => ['nullable', 'string', 'max:255'],
        ]);

        $siteUrl = ! empty($validated['site_url'])
            ? trim($validated['site_url'])
            : ($project->canonical_url ?: "https://{$project->domain}/");

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'provider' => 'bing',
            ],
            [
                'site_url' => $siteUrl,
                'external_identifier' => $siteUrl,
                'status' => 'connected',
                'credentials_encrypted' => [
                    'api_key' => trim($validated['api_key']),
                ],
            ]
        );

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.connected',
            title: 'Bing Webmaster Tools Connected',
            description: "Bing Webmaster Tools connected for site {$siteUrl}.",
            properties: [
                'provider' => 'bing',
                'site_url' => $siteUrl,
            ],
        );

        SyncBingWebmasterJob::dispatch($integration);

        return redirect()
            ->route('workspaces.projects.keywords.index', [$workspace, $project])
            ->with('success', 'Bing Webmaster Tools connected successfully. Data sync has started in the background.');
    }

    public function connectGa4(
        Request $request,
        Workspace $workspace,
        Project $project,
        TrafficAttributionService $trafficService
    ): RedirectResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'property_id' => ['required', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $propertyId = trim((string) $validated['property_id']);
        $creds = [];
        if (isset($validated['api_key']) && (string) $validated['api_key'] !== '') {
            $creds['api_key'] = trim((string) $validated['api_key']);
        }

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'provider' => 'ga4',
            ],
            [
                'site_url' => $propertyId,
                'external_identifier' => $propertyId,
                'status' => 'connected',
                'credentials_encrypted' => $creds,
            ]
        );

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.connected',
            title: 'Google Analytics 4 Connected',
            description: "GA4 Property {$propertyId} connected for landing-page organic and AI traffic attribution.",
            properties: [
                'provider' => 'ga4',
                'property_id' => $propertyId,
            ],
        );

        try {
            $trafficService->syncGa4($integration);
        } catch (Throwable $e) {
            Log::warning('Initial GA4 sync warning: '.$e->getMessage());
        }

        return redirect()
            ->back()
            ->with('success', 'Google Analytics 4 connected successfully.');
    }

    public function connectPlausible(
        Request $request,
        Workspace $workspace,
        Project $project,
        TrafficAttributionService $trafficService
    ): RedirectResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'site_id' => ['nullable', 'string', 'max:150'],
            'api_key' => ['required', 'string', 'max:255'],
        ]);

        $siteId = isset($validated['site_id']) && (string) $validated['site_id'] !== ''
            ? trim((string) $validated['site_id'])
            : $project->domain;

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'provider' => 'plausible',
            ],
            [
                'site_url' => $siteId,
                'external_identifier' => $siteId,
                'status' => 'connected',
                'credentials_encrypted' => [
                    'api_key' => trim((string) $validated['api_key']),
                ],
            ]
        );

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.connected',
            title: 'Plausible Analytics Connected',
            description: "Plausible site {$siteId} connected for privacy-friendly organic and AI referral tracking.",
            properties: [
                'provider' => 'plausible',
                'site_id' => $siteId,
            ],
        );

        try {
            $trafficService->syncPlausible($integration);
        } catch (Throwable $e) {
            Log::warning('Initial Plausible sync warning: '.$e->getMessage());
        }

        return redirect()
            ->back()
            ->with('success', 'Plausible Analytics connected successfully.');
    }

    public function sync(
        Workspace $workspace,
        Project $project,
        ProjectIntegration $integration,
        TrafficAttributionService $trafficService
    ): RedirectResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        abort_if($integration->project_id !== $project->id, 404);
        Gate::authorize('update', $project);

        if ($integration->provider === 'gsc') {
            SyncGoogleSearchConsoleJob::dispatch($integration);
        } elseif ($integration->provider === 'bing') {
            SyncBingWebmasterJob::dispatch($integration);
        } elseif ($integration->provider === 'ga4') {
            $trafficService->syncGa4($integration);
        } elseif ($integration->provider === 'plausible') {
            $trafficService->syncPlausible($integration);
        }

        return redirect()
            ->back()
            ->with('success', "Synchronization triggered for {$integration->provider}.");
    }

    public function destroy(Workspace $workspace, Project $project, ProjectIntegration $integration): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        abort_if($integration->project_id !== $project->id, 404);
        Gate::authorize('update', $project);

        $providerName = match ($integration->provider) {
            'gsc' => 'Google Search Console',
            'bing' => 'Bing Webmaster Tools',
            'runnr' => 'Runnr Hosting',
            'ga4' => 'Google Analytics 4',
            'plausible' => 'Plausible Analytics',
            default => ucfirst($integration->provider),
        };
        $integration->delete();

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.disconnected',
            title: "{$providerName} Disconnected",
            description: "Disconnected {$providerName} integration.",
            properties: [
                'provider' => $integration->provider,
            ],
        );

        return redirect()
            ->back()
            ->with('success', "{$providerName} disconnected.");
    }

    public function connectRunnr(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'runnr_site_id' => ['required', 'string', 'max:120'],
        ]);

        $siteId = (string) $validated['runnr_site_id'];

        $project->update([
            'runnr_site_id' => $siteId,
        ]);

        ProjectIntegration::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'provider' => 'runnr',
            ],
            [
                'external_identifier' => $siteId,
                'status' => 'connected',
                'last_synced_at' => now(),
            ],
        );

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.connected',
            title: 'Runnr Hosting Connected',
            description: "Runnr site {$siteId} connected for automated post-deploy SEO smoke testing.",
            properties: [
                'provider' => 'runnr',
                'runnr_site_id' => $siteId,
            ],
        );

        return redirect()
            ->back()
            ->with('success', 'Runnr Hosting connected. Post-deploy smoke tests are now active.');
    }

    public function disconnectRunnr(Workspace $workspace, Project $project): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $oldSiteId = $project->runnr_site_id;
        $project->update(['runnr_site_id' => null]);

        ProjectIntegration::query()
            ->where('project_id', $project->id)
            ->where('provider', 'runnr')
            ->delete();

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.disconnected',
            title: 'Runnr Hosting Disconnected',
            description: 'Runnr hosting integration disconnected.',
            properties: [
                'provider' => 'runnr',
                'previous_site_id' => $oldSiteId,
            ],
        );

        return redirect()
            ->back()
            ->with('success', 'Runnr Hosting disconnected.');
    }
}
