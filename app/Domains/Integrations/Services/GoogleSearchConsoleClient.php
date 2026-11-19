<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSearchConsoleClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /**
     * Generate the OAuth redirect URL for connecting Google Search Console.
     */
    public function getAuthorizationUrl(Project $project, string $redirectUri, string $state): string
    {
        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            throw new RuntimeException('Google OAuth client ID is not configured.');
        }

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'include_granted_scopes' => 'true',
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    /**
     * Exchange authorization code for access and refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $clientId = (string) config('services.google.client_id');
        $clientSecret = (string) config('services.google.client_secret');

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? $response->body();
            throw new SearchConsoleAuthException('Failed to exchange Google authorization code: '.$error);
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        $accessToken = isset($data['access_token']) && is_string($data['access_token']) ? $data['access_token'] : '';
        $refreshToken = isset($data['refresh_token']) && is_string($data['refresh_token']) ? $data['refresh_token'] : '';
        $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $tokenType = isset($data['token_type']) && is_string($data['token_type']) ? $data['token_type'] : 'Bearer';

        if ($accessToken === '') {
            throw new SearchConsoleAuthException('Google OAuth response did not contain an access token.');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
            'token_type' => $tokenType,
        ];
    }

    /**
     * Refresh the access token using the stored refresh token.
     */
    public function refreshToken(ProjectIntegration $integration): string
    {
        $refreshToken = $integration->getRefreshToken();
        if ($refreshToken === null || $refreshToken === '') {
            $integration->markNeedsReconnect('No refresh token available.');
            throw new SearchConsoleAuthException('No refresh token available for Google Search Console integration.');
        }

        $clientId = (string) config('services.google.client_id');
        $clientSecret = (string) config('services.google.client_secret');

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? $response->body();
            $integration->markNeedsReconnect('Token refresh failed: '.$error);
            throw new SearchConsoleAuthException('Failed to refresh Google Search Console token: '.$error);
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();
        $accessToken = (string) ($data['access_token'] ?? '');
        $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : 3600;

        if ($accessToken === '') {
            $integration->markNeedsReconnect('No access token in refresh response.');
            throw new SearchConsoleAuthException('Google OAuth refresh did not return a valid access token.');
        }

        $integration->updateTokens(
            accessToken: $accessToken,
            refreshToken: null, // Keep existing refresh token if not replaced
            expiresInSeconds: $expiresIn,
        );

        return $accessToken;
    }

    /**
     * Retrieve a valid access token, automatically refreshing if expired or expiring soon.
     */
    public function getValidAccessToken(ProjectIntegration $integration): string
    {
        if ($integration->isTokenExpired() || $integration->getAccessToken() === null) {
            return $this->refreshToken($integration);
        }

        return (string) $integration->getAccessToken();
    }

    /**
     * List verified sites for the connected user.
     *
     * @return list<array{siteUrl: string, permissionLevel: string}>
     */
    public function listSites(ProjectIntegration $integration): array
    {
        $token = $this->getValidAccessToken($integration);
        $baseUrl = (string) config('services.google.search_console_base_url', 'https://searchconsole.googleapis.com');

        $response = Http::withToken($token)
            ->get($baseUrl.'/webmasters/v3/sites');

        if ($response->status() === 401 || $response->status() === 403) {
            $integration->markNeedsReconnect('Unauthorized to access Search Console sites.');
            throw new SearchConsoleAuthException('Google Search Console authorization expired or revoked.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to list Search Console sites: '.$response->body());
        }

        $siteEntries = $response->json('siteEntry') ?? [];

        /** @var list<array{siteUrl: string, permissionLevel: string}> */
        return is_array($siteEntries) ? $siteEntries : [];
    }

    /**
     * Find best matching site URL property for the project domain.
     */
    public function findMatchingSiteProperty(ProjectIntegration $integration, string $projectDomain): ?string
    {
        $sites = $this->listSites($integration);
        $normalizedDomain = strtolower(trim($projectDomain));

        // 1. Check for Domain Property: sc-domain:domain.com
        foreach ($sites as $site) {
            if ($site['siteUrl'] === 'sc-domain:'.$normalizedDomain) {
                return $site['siteUrl'];
            }
        }

        // 2. Check for URL prefix property: https://domain.com/ or http://domain.com/
        foreach ($sites as $site) {
            $siteUrl = strtolower($site['siteUrl']);
            $host = parse_url($siteUrl, PHP_URL_HOST);
            if ($host === $normalizedDomain || $host === 'www.'.$normalizedDomain) {
                return $site['siteUrl'];
            }
        }

        return null;
    }

    /**
     * Query search analytics for keywords or pages.
     * Respects Google Search Console 4-day data finalization lag.
     *
     * @param  list<string>  $dimensions  e.g. ['query', 'date'] or ['page', 'date']
     * @return list<array{keys: list<string>, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public function querySearchAnalytics(
        ProjectIntegration $integration,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        array $dimensions = ['query', 'date'],
        int $startRow = 0,
        int $rowLimit = 5000,
    ): array {
        $token = $this->getValidAccessToken($integration);
        $siteUrl = $integration->external_identifier;
        if (! is_string($siteUrl) || trim($siteUrl) === '') {
            throw new RuntimeException('Google Search Console property URL is not configured.');
        }

        $baseUrl = (string) config('services.google.search_console_base_url', 'https://searchconsole.googleapis.com');
        $endpoint = sprintf('%s/webmasters/v3/sites/%s/searchAnalytics/query', $baseUrl, urlencode($siteUrl));

        $payload = [
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'dimensions' => $dimensions,
            'startRow' => $startRow,
            'rowLimit' => $rowLimit,
            'dataState' => 'final', // Respect 4-day finalization lag
        ];

        $response = Http::withToken($token)->post($endpoint, $payload);

        if ($response->status() === 401 || $response->status() === 403) {
            // Attempt one token refresh on 401
            try {
                $newToken = $this->refreshToken($integration);
                $response = Http::withToken($newToken)->post($endpoint, $payload);
            } catch (\Throwable) {
                $integration->markNeedsReconnect('Authentication failed when querying search analytics.');
                throw new SearchConsoleAuthException('Google Search Console authentication failed: access revoked or expired.');
            }
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $integration->markNeedsReconnect('Authentication failed after refresh.');
            throw new SearchConsoleAuthException('Google Search Console authorization rejected.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Google Search Console query failed: '.$response->body());
        }

        $rows = $response->json('rows') ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $results[] = [
                'keys' => is_array($row['keys'] ?? null) ? array_values($row['keys']) : [],
                'clicks' => isset($row['clicks']) && is_numeric($row['clicks']) ? (int) $row['clicks'] : 0,
                'impressions' => isset($row['impressions']) && is_numeric($row['impressions']) ? (int) $row['impressions'] : 0,
                'ctr' => isset($row['ctr']) && is_numeric($row['ctr']) ? (float) $row['ctr'] : 0.0,
                'position' => isset($row['position']) && is_numeric($row['position']) ? (float) $row['position'] : 0.0,
            ];
        }

        return $results;
    }
}
