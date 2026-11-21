<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProjectIntegration;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Ga4AnalyticsClient
{
    /**
     * GA4 connects through the same Google OAuth client as Search Console,
     * which owns token refresh.
     */
    public function __construct(
        private readonly GoogleSearchConsoleClient $google,
    ) {}

    /**
     * Fetch landing page metrics from GA4 Data API (v1beta).
     *
     * @return list<array{
     *     path: string,
     *     date: string,
     *     channel_group: string,
     *     source: string,
     *     sessions: int,
     *     bounce_rate: float,
     *     avg_duration_sec: int
     * }>
     */
    public function fetchLandingPageMetrics(
        ProjectIntegration $integration,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        int $limit = 10000,
    ): array {
        $propertyId = $this->resolvePropertyId($integration);
        $baseUrl = (string) config('services.google.ga4_base_url', 'https://analyticsdata.googleapis.com');
        $endpoint = rtrim($baseUrl, '/').'/v1beta/'.$propertyId.':runReport';

        $payload = [
            'dateRanges' => [
                [
                    'startDate' => $startDate->format('Y-m-d'),
                    'endDate' => $endDate->format('Y-m-d'),
                ],
            ],
            'dimensions' => [
                ['name' => 'pagePath'],
                ['name' => 'sessionDefaultChannelGroup'],
                ['name' => 'sessionSource'],
                ['name' => 'date'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
            ],
            'limit' => $limit,
        ];

        // The Data API takes OAuth tokens only; an API key can't read a
        // property. Integrations saved with just a key have no refresh token,
        // so this marks them as needing a reconnect.
        $response = $this->runReport($endpoint, $this->google->getValidAccessToken($integration), $payload);

        // Revoked early or expired between the check and the call: refresh
        // once and try again.
        if ($response->status() === 401) {
            $response = $this->runReport($endpoint, $this->google->refreshToken($integration), $payload);
        }

        if ($response->status() === 401) {
            $integration->markNeedsReconnect('GA4 API unauthorized or token expired.');
            throw new SearchConsoleAuthException('Google Analytics 4 authorization expired or was revoked.');
        }

        if ($response->status() === 403) {
            $integration->markNeedsReconnect('Google account cannot read this GA4 property.');
            throw new SearchConsoleAuthException("The connected Google account can't read GA4 {$propertyId}.");
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch GA4 metrics: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();
        /** @var list<array{dimensionValues?: list<array{value?: string}>, metricValues?: list<array{value?: string}>}> $rows */
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];

        $results = [];
        foreach ($rows as $row) {
            $dimVals = $row['dimensionValues'] ?? [];
            $metricVals = $row['metricValues'] ?? [];

            $path = isset($dimVals[0]['value']) ? (string) $dimVals[0]['value'] : '';
            $channel = isset($dimVals[1]['value']) ? (string) $dimVals[1]['value'] : '';
            $source = isset($dimVals[2]['value']) ? (string) $dimVals[2]['value'] : '';
            $dateStr = isset($dimVals[3]['value']) ? (string) $dimVals[3]['value'] : '';

            // GA4 date string format is YYYYMMDD
            if (strlen($dateStr) === 8 && is_numeric($dateStr)) {
                $formattedDate = substr($dateStr, 0, 4).'-'.substr($dateStr, 4, 2).'-'.substr($dateStr, 6, 2);
            } else {
                $formattedDate = $dateStr;
            }

            $sessions = isset($metricVals[0]['value']) && is_numeric($metricVals[0]['value'])
                ? (int) $metricVals[0]['value']
                : 0;

            $bounceRate = isset($metricVals[1]['value']) && is_numeric($metricVals[1]['value'])
                ? round((float) $metricVals[1]['value'] * 100, 2)
                : 0.0;

            $avgDuration = isset($metricVals[2]['value']) && is_numeric($metricVals[2]['value'])
                ? (int) round((float) $metricVals[2]['value'])
                : 0;

            $results[] = [
                'path' => $path,
                'date' => $formattedDate,
                'channel_group' => $channel,
                'source' => $source,
                'sessions' => $sessions,
                'bounce_rate' => $bounceRate,
                'avg_duration_sec' => $avgDuration,
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runReport(string $endpoint, string $accessToken, array $payload): Response
    {
        return Http::withToken($accessToken)->post($endpoint, $payload);
    }

    /**
     * Resolve property resource string (e.g. "properties/123456789").
     */
    public function resolvePropertyId(ProjectIntegration $integration): string
    {
        $identifier = $integration->external_identifier;
        if (! is_string($identifier) || trim($identifier) === '') {
            $identifier = $integration->site_url;
        }

        if (! is_string($identifier) || trim($identifier) === '') {
            throw new RuntimeException('Google Analytics 4 Property ID is not configured.');
        }

        $clean = trim($identifier);
        if (str_starts_with($clean, 'properties/')) {
            return $clean;
        }

        return 'properties/'.$clean;
    }
}
