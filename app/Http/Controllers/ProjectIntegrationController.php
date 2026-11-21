<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Billing\PlanFeature;
use App\Domains\Integrations\Jobs\SyncBingWebmasterJob;
use App\Domains\Integrations\Jobs\SyncGoogleSearchConsoleJob;
use App\Domains\Integrations\Jobs\SyncTrafficAnalyticsJob;
use App\Domains\Integrations\Models\PageTrafficSnapshot;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\Ga4AnalyticsClient;
use App\Domains\Integrations\Services\GoogleSearchConsoleClient;
use App\Domains\Integrations\Services\SearchConsoleAuthException;
use App\Domains\Integrations\Services\TrafficAttributionService;
use App\Domains\Integrations\Services\UmamiAnalyticsClient;
use App\Domains\Integrations\TrafficProvider;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Projects\Models\Workspace;
use App\Features\RunnrIntegration;
use App\Http\Middleware\EnsureRunnrIntegrationEnabled;
use App\Support\Http\UnsafeUrlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class ProjectIntegrationController extends Controller implements HasMiddleware
{
    /**
     * Runnr actions stay hidden (404) until the runnr-integration feature is on.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware(EnsureRunnrIntegrationEnabled::class, only: ['connectRunnr', 'disconnectRunnr']),
        ];
    }

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
            ->when(! RunnrIntegration::enabled(), fn ($query) => $query->where('provider', '!=', 'runnr'))
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
            'provider' => 'gsc',
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

        $isGa4 = ($pending['provider'] ?? 'gsc') === 'ga4';
        // GA4 is connected from the project page; Search Console from keywords.
        $returnTo = $isGa4
            ? route('workspaces.projects.show', [$workspace, $project])
            : route('workspaces.projects.keywords.index', [$workspace, $project]);

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            $errorDesc = $request->query('error_description') ?? $request->query('error') ?? 'Google authorization was denied.';

            return redirect($returnTo)->with('error', (string) $errorDesc);
        }

        if ($isGa4) {
            return $this->finishGa4Connect($project, $gscClient, app(Ga4AnalyticsClient::class), $code, $returnTo);
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

    /**
     * Start connecting GA4: send the user to Google to grant read-only
     * Analytics access. The fixed Google callback finishes the connection and
     * picks the property (or asks the user to choose one).
     */
    public function connectGa4(
        Request $request,
        Workspace $workspace,
        Project $project,
        GoogleSearchConsoleClient $google,
    ): SymfonyResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $state = Str::random(40);

        try {
            $authUrl = $google->getAuthorizationUrl($project, $this->googleRedirectUri(), $state, GoogleSearchConsoleClient::ANALYTICS_SCOPE);
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $request->session()->put(self::GOOGLE_OAUTH_SESSION_PREFIX.$state, [
            'provider' => 'ga4',
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'issued_at' => now()->getTimestamp(),
        ]);

        // The button posts through Inertia, which can't follow a redirect to
        // another site; Inertia::location makes the browser navigate there.
        return Inertia::location($authUrl);
    }

    /**
     * GA4 properties the connected Google account can read, for the picker.
     * Properties whose website matches the project's domain come first.
     */
    public function ga4Properties(Workspace $workspace, Project $project, Ga4AnalyticsClient $ga4): JsonResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        /** @var ProjectIntegration|null $integration */
        $integration = $project->integrations()->where('provider', 'ga4')->first();
        abort_if($integration === null, 404);

        // Saved before Google sign-in (API key only): sign in first.
        if ($integration->getRefreshToken() === null || $integration->getRefreshToken() === '') {
            return response()->json(['message' => 'Reconnect GA4 with Google to choose a property.', 'reconnect' => true], 409);
        }

        try {
            $properties = $this->rankGa4Properties($ga4->listProperties($integration), $project);
        } catch (SearchConsoleAuthException $e) {
            return response()->json(['message' => $e->getMessage(), 'reconnect' => true], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'properties' => $properties,
            'current' => $integration->external_identifier,
        ]);
    }

    /**
     * Use the GA4 property the user picked.
     */
    public function selectGa4Property(Request $request, Workspace $workspace, Project $project, Ga4AnalyticsClient $ga4): RedirectResponse
    {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'property_id' => ['required', 'string', 'regex:/^properties\/\d{1,20}$/'],
        ]);

        $integration = $this->ga4Integration($project);

        try {
            $properties = $ga4->listProperties($integration, withWebsites: false);
        } catch (Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        // Only properties this Google account can actually read.
        $chosen = collect($properties)->firstWhere('id', $validated['property_id']);
        if ($chosen === null) {
            throw ValidationException::withMessages([
                'property_id' => 'That GA4 property isn\'t available to the connected Google account.',
            ]);
        }

        $this->useGa4Property($integration, $chosen);

        return redirect()->back()->with('success', "Using GA4 property {$chosen['name']}. Traffic sync has started in the background.");
    }

    /**
     * Second half of the GA4 connection, after Google redirects back: store
     * the tokens, then pick the property. A reconnect keeps the property it
     * had if this account can still read it; otherwise the property whose
     * website matches the project's
     * domain, or the account's only property, is used. Anything else waits
     * for the user to choose in the picker.
     */
    private function finishGa4Connect(
        Project $project,
        GoogleSearchConsoleClient $google,
        Ga4AnalyticsClient $ga4,
        string $code,
        string $returnTo,
    ): RedirectResponse {
        try {
            $tokens = $google->exchangeCodeForTokens($code, $this->googleRedirectUri());
        } catch (Throwable $e) {
            Log::error('GA4 OAuth callback error: '.$e->getMessage());

            return redirect($returnTo)->with('error', 'Failed to connect Google Analytics 4: '.$e->getMessage());
        }

        // Google lets the user untick a scope on the consent screen.
        $granted = preg_split('/\s+/', $tokens['scope'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($granted !== [] && ! in_array(GoogleSearchConsoleClient::ANALYTICS_SCOPE, $granted, true)) {
            return redirect($returnTo)->with('error', 'Google Analytics access wasn\'t granted. Connect again and allow access to view your Analytics data.');
        }

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->firstOrNew([
            'project_id' => $project->id,
            'provider' => 'ga4',
        ]);

        // Google only sends a refresh token on the first consent; keep the old
        // one when reconnecting.
        $refreshToken = $tokens['refresh_token'] !== '' ? $tokens['refresh_token'] : ($integration->getRefreshToken() ?? '');

        $integration->credentials_encrypted = [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $refreshToken,
            'token_type' => $tokens['token_type'],
            'expires_at' => Carbon::now()->addSeconds($tokens['expires_in'])->toIso8601String(),
        ];

        $currentProperty = (string) $integration->external_identifier;
        $hasProperty = preg_match('/^properties\/\d+$/', $currentProperty) === 1;

        $integration->status = $hasProperty ? 'connected' : 'needs_property';
        $integration->save();

        $pickerUrl = $returnTo.(str_contains($returnTo, '?') ? '&' : '?').'ga4_picker=1';

        try {
            $properties = $this->rankGa4Properties($ga4->listProperties($integration), $project);
        } catch (Throwable $e) {
            Log::warning("Listing GA4 properties failed for project {$project->id}: {$e->getMessage()}");

            if ($hasProperty) {
                // Can't check the property, so keep it; the sync will say if
                // it's unreadable.
                SyncTrafficAnalyticsJob::dispatch($integration);

                return redirect($returnTo)->with('success', 'Google Analytics 4 reconnected. Traffic sync has started in the background.');
            }

            return redirect($returnTo)->with('error', 'Signed in to Google, but '.lcfirst($e->getMessage()));
        }

        // A reconnect keeps its property while this account can still read it.
        if ($hasProperty && in_array($currentProperty, array_column($properties, 'id'), true)) {
            SyncTrafficAnalyticsJob::dispatch($integration);

            return redirect($returnTo)->with('success', 'Google Analytics 4 reconnected. Traffic sync has started in the background.');
        }

        if ($hasProperty) {
            // This account can't read the old property: choose another.
            $integration->update(['status' => 'needs_property']);
        }

        if ($properties === []) {
            return redirect($returnTo)->with('error', 'This Google account can\'t see any GA4 properties. Connect again with an account that can view the property.');
        }

        $matches = array_values(array_filter($properties, fn (array $property): bool => $property['matches']));
        $chosen = count($matches) === 1 ? $matches[0] : (count($properties) === 1 ? $properties[0] : null);

        if ($chosen === null) {
            return redirect($pickerUrl)->with('success', 'Signed in to Google. Choose the GA4 property for this project.');
        }

        $this->useGa4Property($integration, $chosen);

        return redirect($returnTo)->with('success', "Google Analytics 4 connected to {$chosen['name']}. Traffic sync has started in the background.");
    }

    /**
     * The project's GA4 integration, once Google sign-in has stored a token.
     */
    private function ga4Integration(Project $project): ProjectIntegration
    {
        /** @var ProjectIntegration|null $integration */
        $integration = $project->integrations()->where('provider', 'ga4')->first();
        abort_if($integration === null || $integration->getRefreshToken() === null || $integration->getRefreshToken() === '', 404);

        return $integration;
    }

    /**
     * Mark which properties belong to the project's site and list them first.
     *
     * A property matches when any of its web streams is the project's site,
     * and then shows that stream's URL.
     *
     * @param  list<array{id: string, name: string, account: string, website: string|null, websites: list<string>}>  $properties
     * @return list<array{id: string, name: string, account: string, website: string|null, websites: list<string>, matches: bool}>
     */
    private function rankGa4Properties(array $properties, Project $project): array
    {
        $ranked = array_map(function (array $property) use ($project): array {
            $matching = array_values(array_filter(
                $property['websites'],
                fn (string $website): bool => Ga4AnalyticsClient::websiteMatchesDomain($website, $project->domain),
            ));

            return array_merge($property, [
                'website' => $matching[0] ?? $property['website'],
                'matches' => $matching !== [],
            ]);
        }, $properties);

        usort($ranked, fn (array $a, array $b): int => [$b['matches'], strtolower($a['name'])] <=> [$a['matches'], strtolower($b['name'])]);

        return $ranked;
    }

    /**
     * Point the integration at a property and start syncing it. Snapshots
     * from a different property are removed so two sites' traffic isn't mixed.
     *
     * @param  array{id: string, name: string}  $property
     */
    private function useGa4Property(ProjectIntegration $integration, array $property): void
    {
        // Locks the integration row, as a GA4 sync does before storing its
        // report, so a sync of the old property can't write after the delete.
        DB::transaction(function () use ($integration, $property): void {
            $previous = (string) ProjectIntegration::query()->whereKey($integration->id)->lockForUpdate()->value('external_identifier');
            if ($previous !== '' && $previous !== $property['id']) {
                PageTrafficSnapshot::query()
                    ->where('project_id', $integration->project_id)
                    ->where('provider', 'ga4')
                    ->delete();
            }

            $integration->update([
                'site_url' => $property['name'],
                'external_identifier' => $property['id'],
                'status' => 'connected',
            ]);
        });

        $project = $integration->project;
        if ($project !== null) {
            ProjectActivity::log(
                project: $project,
                eventType: 'integration.connected',
                title: 'Google Analytics 4 Connected',
                description: "GA4 property {$property['name']} ({$property['id']}) connected for landing-page organic and AI traffic attribution.",
                properties: [
                    'provider' => 'ga4',
                    'property_id' => $property['id'],
                ],
            );
        }

        SyncTrafficAnalyticsJob::dispatch($integration);
    }

    /**
     * Connect a self-hosted or cloud Umami instance. The website is matched to
     * the project's domain unless a website ID is given, and the first sync
     * runs in the background.
     */
    public function connectUmami(
        Request $request,
        Workspace $workspace,
        Project $project,
        UmamiAnalyticsClient $umami,
    ): RedirectResponse {
        abort_if($project->workspace_id !== $workspace->id, 404);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'base_url' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'max:255'],
            'website_id' => ['nullable', 'uuid'],
        ]);

        $baseUrl = rtrim(trim((string) $validated['base_url']), '/');
        $apiKey = trim((string) $validated['api_key']);

        try {
            $websites = $umami->listWebsites($baseUrl, $apiKey);
        } catch (UnsafeUrlException $e) {
            throw ValidationException::withMessages(['base_url' => $e->getMessage()]);
        } catch (SearchConsoleAuthException) {
            throw ValidationException::withMessages(['api_key' => 'Umami rejected this API key.']);
        } catch (Throwable $e) {
            Log::warning("Umami connect failed for project {$project->id}: {$e->getMessage()}");

            throw ValidationException::withMessages(['base_url' => 'Couldn\'t reach Umami at that address. Check the URL and that the instance is online.']);
        }

        $website = $this->matchUmamiWebsite($websites, $project->domain, $validated['website_id'] ?? null);

        if ($website === null) {
            $available = collect($websites)
                ->take(10)
                ->map(fn (array $site): string => "{$site['name']} ({$site['domain']}): {$site['id']}")
                ->implode('; ');

            throw ValidationException::withMessages(['website_id' => $websites === []
                ? 'This API key can\'t see any Umami websites.'
                : "No Umami website matches {$project->domain}. Enter its website ID. Available: {$available}"]);
        }

        /** @var ProjectIntegration $integration */
        $integration = ProjectIntegration::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'provider' => 'umami',
            ],
            [
                'site_url' => $website['domain'] !== '' ? $website['domain'] : $website['name'],
                'external_identifier' => $website['id'],
                'status' => 'connected',
                'credentials_encrypted' => [
                    'base_url' => $baseUrl,
                    'api_key' => $apiKey,
                ],
                // A new or changed website starts with a full sync.
                'last_synced_at' => null,
            ]
        );

        ProjectActivity::log(
            project: $project,
            eventType: 'integration.connected',
            title: 'Umami Analytics Connected',
            description: "Umami website {$integration->site_url} connected for per-page organic and AI referral tracking.",
            properties: [
                'provider' => 'umami',
                'website_id' => $website['id'],
            ],
        );

        SyncTrafficAnalyticsJob::dispatch($integration);

        return redirect()
            ->back()
            ->with('success', 'Umami connected. The last 30 days of traffic are syncing in the background.');
    }

    /**
     * @param  list<array{id: string, name: string, domain: string}>  $websites
     * @return array{id: string, name: string, domain: string}|null
     */
    private function matchUmamiWebsite(array $websites, string $projectDomain, ?string $websiteId): ?array
    {
        if ($websiteId !== null && $websiteId !== '') {
            return collect($websites)->first(fn (array $site): bool => strcasecmp($site['id'], $websiteId) === 0);
        }

        $bare = fn (string $domain): string => preg_replace('/^www\./', '', strtolower(trim($domain))) ?? '';
        $target = $bare($projectDomain);

        return collect($websites)->first(fn (array $site): bool => $target !== '' && $bare($site['domain']) === $target);
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
        } elseif ($integration->provider === TrafficProvider::Umami->value) {
            // A backfill can take minutes (Umami Cloud is rate limited), longer
            // than a web request may run, so it goes to the queue in week-long jobs.
            SyncTrafficAnalyticsJob::dispatch($integration);

            return redirect()
                ->back()
                ->with('success', 'Umami sync queued. New traffic appears once it finishes.');
        } elseif (TrafficProvider::tryFrom($integration->provider) !== null) {
            try {
                $trafficService->syncIntegration($integration);
            } catch (Throwable $e) {
                Log::warning("Manual {$integration->provider} sync failed for project {$project->id}: {$e->getMessage()}");

                return redirect()
                    ->back()
                    ->with('error', TrafficProvider::from($integration->provider)->label().' sync failed: '.$e->getMessage());
            }
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
            default => TrafficProvider::tryFrom($integration->provider)?->label() ?? ucfirst($integration->provider),
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
            'runnr_site_id' => [
                'required',
                'string',
                'max:120',
                // Telemetry and deploy webhooks are routed by site ID, so a site
                // already linked to another project can't be claimed again.
                Rule::unique('projects', 'runnr_site_id')->ignore($project->id)->whereNull('deleted_at'),
            ],
        ], [
            'runnr_site_id.unique' => 'This Runnr site is already linked to another project.',
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
