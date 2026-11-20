<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProjectIntegration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PlausibleAnalyticsClient
{
    /**
     * Page and source breakdowns for each day in the range.
     *
     * The v1 breakdown API only returns totals for its whole period, so a
     * per-day series needs one page call and one source call per day. They
     * run concurrently, a few days at a time.
     *
     * @return array<string, array{
     *     pages: list<array{page: string, visitors: int, pageviews: int, bounce_rate: float, visit_duration: int}>,
     *     sources: list<array{source: string, visitors: int}>
     * }> keyed by Y-m-d
     */
    public function fetchDailyMetrics(
        ProjectIntegration $integration,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        int $daysPerBatch = 7,
    ): array {
        $siteId = $this->resolveSiteId($integration);
        $apiKey = $this->resolveApiKey($integration);
        $endpoint = $this->breakdownEndpoint();

        $dates = [];
        $last = CarbonImmutable::parse($endDate->format('Y-m-d'));
        for ($day = CarbonImmutable::parse($startDate->format('Y-m-d')); $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        $daily = [];
        foreach (array_chunk($dates, max(1, $daysPerBatch)) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $endpoint, $apiKey, $siteId): array {
                $requests = [];
                foreach ($batch as $date) {
                    $requests[] = $pool->as("pages:{$date}")->withToken($apiKey)
                        ->get($endpoint, $this->pageQuery($siteId, 'day', $date, 1000));
                    $requests[] = $pool->as("sources:{$date}")->withToken($apiKey)
                        ->get($endpoint, $this->sourceQuery($siteId, 'day', $date, 500));
                }

                return $requests;
            });

            foreach ($batch as $date) {
                $daily[$date] = [
                    'pages' => $this->parsePages($this->decode($integration, $responses["pages:{$date}"] ?? null, 'page')),
                    'sources' => $this->parseSources($this->decode($integration, $responses["sources:{$date}"] ?? null, 'source')),
                ];
            }
        }

        return $daily;
    }

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
        $response = Http::withToken($this->resolveApiKey($integration))->get(
            $this->breakdownEndpoint(),
            $this->pageQuery(
                $this->resolveSiteId($integration),
                'custom',
                $startDate->format('Y-m-d').','.$endDate->format('Y-m-d'),
                $limit,
            ),
        );

        return $this->parsePages($this->decode($integration, $response, 'page'));
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
        $query = $this->sourceQuery(
            $this->resolveSiteId($integration),
            'custom',
            $startDate->format('Y-m-d').','.$endDate->format('Y-m-d'),
            $limit,
        );

        if ($pageFilter !== null && $pageFilter !== '') {
            $query['filters'] = 'event:page=='.$pageFilter;
        }

        $response = Http::withToken($this->resolveApiKey($integration))->get($this->breakdownEndpoint(), $query);

        return $this->parseSources($this->decode($integration, $response, 'source'));
    }

    private function breakdownEndpoint(): string
    {
        $baseUrl = (string) config('services.plausible.base_url', 'https://plausible.io');

        return rtrim($baseUrl, '/').'/api/v1/stats/breakdown';
    }

    /**
     * @return array<string, int|string>
     */
    private function pageQuery(string $siteId, string $period, string $date, int $limit): array
    {
        return [
            'site_id' => $siteId,
            'period' => $period,
            'date' => $date,
            'property' => 'event:page',
            'metrics' => 'visitors,pageviews,bounce_rate,visit_duration',
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function sourceQuery(string $siteId, string $period, string $date, int $limit): array
    {
        return [
            'site_id' => $siteId,
            'period' => $period,
            'date' => $date,
            'property' => 'visit:source',
            'metrics' => 'visitors',
            'limit' => $limit,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function decode(ProjectIntegration $integration, mixed $response, string $kind): array
    {
        if (! $response instanceof Response) {
            $reason = $response instanceof Throwable ? $response->getMessage() : 'no response';

            throw new RuntimeException("Failed to fetch Plausible {$kind} metrics: {$reason}");
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $integration->markNeedsReconnect('Plausible API key unauthorized.');
            throw new SearchConsoleAuthException('Plausible API key invalid or unauthorized.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("Failed to fetch Plausible {$kind} metrics: ".$response->body());
        }

        $results = $response->json('results');

        return is_array($results) ? array_values($results) : [];
    }

    /**
     * @param  list<mixed>  $results
     * @return list<array{page: string, visitors: int, pageviews: int, bounce_rate: float, visit_duration: int}>
     */
    private function parsePages(array $results): array
    {
        $formatted = [];
        foreach ($results as $item) {
            if (! is_array($item) || ! isset($item['page']) || (string) $item['page'] === '') {
                continue;
            }

            $formatted[] = [
                'page' => (string) $item['page'],
                'visitors' => (int) ($item['visitors'] ?? 0),
                'pageviews' => (int) ($item['pageviews'] ?? 0),
                'bounce_rate' => (float) ($item['bounce_rate'] ?? 0.0),
                'visit_duration' => (int) ($item['visit_duration'] ?? 0),
            ];
        }

        return $formatted;
    }

    /**
     * @param  list<mixed>  $results
     * @return list<array{source: string, visitors: int}>
     */
    private function parseSources(array $results): array
    {
        $formatted = [];
        foreach ($results as $item) {
            if (! is_array($item) || ! isset($item['source']) || (string) $item['source'] === '') {
                continue;
            }

            $formatted[] = [
                'source' => (string) $item['source'],
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
