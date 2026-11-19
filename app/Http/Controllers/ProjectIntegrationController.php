<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Integrations\Jobs\SyncBingWebmasterJob;
use App\Domains\Integrations\Jobs\SyncGoogleSearchConsoleJob;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\GoogleSearchConsoleClient;
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

        $state = Str::random(40);
        $request->session()->put('gsc_oauth_state_'.$project->id, $state);

        $redirectUri = route('workspaces.projects.integrations.google.callback', [$workspace, $project]);
        $authUrl = $gscClient->getAuthorizationUrl($project, $redirectUri, $state);

        return redirect()->away($authUrl);
    }

    public function callbackGoogle(
        Request $request,
        Workspace $workspace,
        Project $project,
        GoogleSearchConsoleClient $gscClient
    ): RedirectResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $state = $request->query('state');
        $expectedState = $request->session()->pull('gsc_oauth_state_'.$project->id);

        if (! is_string($state) || $state === '' || $state !== $expectedState) {
            return redirect()
                ->route('workspaces.projects.keywords.index', [$workspace, $project])
                ->with('error', 'Invalid Google OAuth state. Please try reconnecting.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            $errorDesc = $request->query('error_description') ?? $request->query('error') ?? 'Google authorization was denied.';

            return redirect()
                ->route('workspaces.projects.keywords.index', [$workspace, $project])
                ->with('error', (string) $errorDesc);
        }

        $redirectUri = route('workspaces.projects.integrations.google.callback', [$workspace, $project]);

        try {
            $tokens = $gscClient->exchangeCodeForTokens($code, $redirectUri);

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

    public function sync(Workspace $workspace, Project $project, ProjectIntegration $integration): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        abort_if($integration->project_id !== $project->id, 404);
        Gate::authorize('update', $project);

        if ($integration->provider === 'gsc') {
            SyncGoogleSearchConsoleJob::dispatch($integration);
        } elseif ($integration->provider === 'bing') {
            SyncBingWebmasterJob::dispatch($integration);
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

        $providerName = $integration->provider === 'gsc' ? 'Google Search Console' : 'Bing Webmaster Tools';
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
}
