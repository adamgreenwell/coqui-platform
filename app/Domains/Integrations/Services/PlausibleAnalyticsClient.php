<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProjectIntegration;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PlausibleAnalyticsClient
{
    /**
     * Fetch landing page visitor breakdown from Plausible Stats API.
     *
     * @return list<array{
     *     page: string,
     *     visitors: int,
     *     pageviews: int,
     *     bounce_rate: float,
     *     visit_duration: int
     * }>
     */
    public function fetchPageMetrics(
        ProjectIntegration $integration,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        int $limit = 1000,
    ): array {
        $siteId = $this->resolveSiteId($integration);
        $baseUrl = (string) config('services.plausible.base_url', 'https://plausible.io');
        $endpoint = rtrim($baseUrl, '/').'/api/v1/stats/breakdown';

        $apiKey = $this->resolveApiKey($integration);
        $dateParam = $startDate->format('Y-m-d').','.$endDate->format('Y-m-d');

        $response = Http::withToken($apiKey)->get($endpoint, [
            'site_id' => $siteId,
            'period' => 'custom',
            'date' => $dateParam,
            'property' => 'event:page',
            'metrics' => 'visitors,pageviews,bounce_rate,visit_duration',
            'limit' => $limit,
        ]);

        if ($response->status() === 401 || $response->status() === 403) {
            $integration->markNeedsReconnect('Plausible API key unauthorized.');
            throw new SearchConsoleAuthException('Plausible API key invalid or unauthorized.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch Plausible page metrics: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();
        /** @var list<array{page?: string, visitors?: int, pageviews?: int, bounce_rate?: float|int, visit_duration?: int}> $results */
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];

        $formatted = [];
        foreach ($results as $item) {
            $page = isset($item['page']) ? (string) $item['page'] : '';
            if ($page === '') {
                continue;
            }

            $formatted[] = [
                'page' => $page,
                'visitors' => (int) ($item['visitors'] ?? 0),
                'pageviews' => (int) ($item['pageviews'] ?? 0),
                'bounce_rate' => (float) ($item['bounce_rate'] ?? 0.0),
                'visit_duration' => (int) ($item['visit_duration'] ?? 0),
            ];
        }

        return $formatted;
    }

    /**
     * Fetch referral and search source breakdown from Plausible Stats API.
     *
     * @return list<array{source: string, visitors: int}>
     */
    public function fetchSourceMetrics(
        ProjectIntegration $integration,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        ?string $pageFilter = null,
        int $limit = 500,
    ): array {
        $siteId = $this->resolveSiteId($integration);
        $baseUrl = (string) config('services.plausible.base_url', 'https://plausible.io');
        $endpoint = rtrim($baseUrl, '/').'/api/v1/stats/breakdown';

        $apiKey = $this->resolveApiKey($integration);
        $dateParam = $startDate->format('Y-m-d').','.$endDate->format('Y-m-d');

        $query = [
            'site_id' => $siteId,
            'period' => 'custom',
            'date' => $dateParam,
            'property' => 'visit:source',
            'metrics' => 'visitors',
            'limit' => $limit,
        ];

        if ($pageFilter !== null && $pageFilter !== '') {
            $query['filters'] = 'event:page=='.$pageFilter;
        }

        $response = Http::withToken($apiKey)->get($endpoint, $query);

        if ($response->status() === 401 || $response->status() === 403) {
            $integration->markNeedsReconnect('Plausible API key unauthorized.');
            throw new SearchConsoleAuthException('Plausible API key invalid or unauthorized.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch Plausible source metrics: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();
        /** @var list<array{source?: string, visitors?: int}> $results */
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];

        $formatted = [];
        foreach ($results as $item) {
            $source = isset($item['source']) ? (string) $item['source'] : '';
            if ($source === '') {
                continue;
            }

            $formatted[] = [
                'source' => $source,
                'visitors' => (int) ($item['visitors'] ?? 0),
            ];
        }

        return $formatted;
    }

    public function resolveSiteId(ProjectIntegration $integration): string
    {
        $identifier = $integration->external_identifier;
        if (! is_string($identifier) || trim($identifier) === '') {
            $identifier = $integration->site_url;
        }

        if (! is_string($identifier) || trim($identifier) === '') {
            $project = $integration->project;
            if ($project !== null && $project->domain !== '') {
                return $project->domain;
            }

            throw new RuntimeException('Plausible Site ID or domain is not configured.');
        }

        return trim($identifier);
    }

    public function resolveApiKey(ProjectIntegration $integration): string
    {
        /** @var array<string, mixed>|null $creds */
        $creds = $integration->credentials_encrypted;

        if (isset($creds['api_key']) && is_string($creds['api_key']) && $creds['api_key'] !== '') {
            return $creds['api_key'];
        }

        $token = $integration->getAccessToken();
        if ($token !== null && $token !== '') {
            return $token;
        }

        throw new RuntimeException('Plausible API token is not configured.');
    }
}
