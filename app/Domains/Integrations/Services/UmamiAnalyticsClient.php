<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProjectIntegration;
use App\Support\Http\PublicUrlGuard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads page traffic from a self-hosted or cloud Umami (v3) instance.
 *
 * Umami's metrics endpoints only return totals for the requested range, so
 * each day is fetched separately. Umami can't filter by channel, but it can
 * filter pages by referrer, which is how search and AI visits are counted
 * per page.
 */
class UmamiAnalyticsClient
{
    public function __construct(
        private readonly PublicUrlGuard $guard,
    ) {}

    /**
     * Websites the API key can read.
     *
     * @return list<array{id: string, name: string, domain: string}>
     */
    public function listWebsites(string $baseUrl, string $apiKey): array
    {
        $target = $this->guard->check($baseUrl);

        $response = $this->request($target, $apiKey)->get($target['base'].'/api/websites', ['pageSize' => 200]);

        $body = $this->decode(null, $response, 'website list');
        $rows = is_array($body['data'] ?? null) ? $body['data'] : [];

        $websites = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $websites[] = [
                    'id' => $row['id'],
                    'name' => (string) ($row['name'] ?? ''),
                    'domain' => (string) ($row['domain'] ?? ''),
                ];
            }
        }

        return $websites;
    }

    /**
     * Pages and referrers for each day in the range, keyed by Y-m-d (UTC).
     *
     * @return array<string, array{
     *     pages: list<array{page: string, visitors: int, visits: int, pageviews: int, bounces: int, totaltime: int}>,
     *     referrers: list<array{referrer: string, visitors: int}>
     * }>
     */
    public function fetchDailyMetrics(
        ProjectIntegration $integration,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        int $daysPerBatch = 7,
    ): array {
        [$target, $apiKey, $websiteId] = $this->connection($integration);
        $endpoint = "{$target['base']}/api/websites/{$websiteId}";

        $dates = [];
        $last = CarbonImmutable::parse($endDate->format('Y-m-d'), 'UTC');
        for ($day = CarbonImmutable::parse($startDate->format('Y-m-d'), 'UTC'); $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        $daily = [];
        foreach (array_chunk($dates, max(1, $daysPerBatch)) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $endpoint, $target, $apiKey): array {
                $requests = [];
                foreach ($batch as $date) {
                    $requests[] = $this->pooled($pool->as("pages:{$date}"), $target, $apiKey)
                        ->get("{$endpoint}/metrics/expanded", $this->query($date, ['type' => 'path', 'limit' => 1000]));
                    $requests[] = $this->pooled($pool->as("referrers:{$date}"), $target, $apiKey)
                        ->get("{$endpoint}/metrics", $this->query($date, ['type' => 'referrer', 'limit' => 500]));
                }

                return $requests;
            });

            foreach ($batch as $date) {
                $daily[$date] = [
                    'pages' => $this->parsePages($this->decode($integration, $responses["pages:{$date}"] ?? null, 'page')),
                    'referrers' => $this->parseReferrers($this->decode($integration, $responses["referrers:{$date}"] ?? null, 'referrer')),
                ];
            }
        }

        return $daily;
    }

    /**
     * Visitors per page that arrived from each of the given referrers on one day.
     *
     * @param  list<string>  $referrers
     * @return array<string, list<array{page: string, visitors: int}>> keyed by referrer
     */
    public function fetchPagesByReferrer(ProjectIntegration $integration, string $date, array $referrers): array
    {
        if ($referrers === []) {
            return [];
        }

        [$target, $apiKey, $websiteId] = $this->connection($integration);
        $endpoint = "{$target['base']}/api/websites/{$websiteId}/metrics";

        $responses = Http::pool(function (Pool $pool) use ($referrers, $endpoint, $date, $target, $apiKey): array {
            $requests = [];
            foreach ($referrers as $i => $referrer) {
                $requests[] = $this->pooled($pool->as("ref:{$i}"), $target, $apiKey)
                    ->get($endpoint, $this->query($date, ['type' => 'path', 'limit' => 1000, 'referrer' => $referrer]));
            }

            return $requests;
        });

        $pages = [];
        foreach ($referrers as $i => $referrer) {
            $pages[$referrer] = $this->parseCounts($this->decode($integration, $responses["ref:{$i}"] ?? null, 'referrer page'));
        }

        /** @var array<string, list<array{page: string, visitors: int}>> $pages */
        return $pages;
    }

    /**
     * @return array{0: array{base: string, host: string, port: int, ip: string}, 1: string, 2: string}
     */
    private function connection(ProjectIntegration $integration): array
    {
        $credentials = $integration->credentials_encrypted;
        $baseUrl = is_array($credentials) ? (string) ($credentials['base_url'] ?? '') : '';
        $apiKey = is_array($credentials) ? (string) ($credentials['api_key'] ?? '') : '';
        $websiteId = (string) ($integration->external_identifier ?? '');

        if ($baseUrl === '' || $apiKey === '' || $websiteId === '') {
            throw new RuntimeException('Umami integration is missing its URL, API key or website ID.');
        }

        // Checked on every sync, not just on connect, in case the host's DNS
        // has since been pointed somewhere internal.
        return [$this->guard->check($baseUrl), $apiKey, rawurlencode($websiteId)];
    }

    /**
     * @param  array<string, int|string>  $extra
     * @return array<string, int|string>
     */
    private function query(string $date, array $extra): array
    {
        $day = CarbonImmutable::parse($date, 'UTC');

        return [
            'startAt' => $day->startOfDay()->getTimestampMs(),
            // Umami's range is inclusive. endOfDay() would round up to the
            // next midnight and count its first millisecond twice.
            'endAt' => $day->addDay()->startOfDay()->getTimestampMs() - 1,
            'timezone' => 'UTC',
            ...$extra,
        ];
    }

    /**
     * @param  array{base: string, host: string, port: int, ip: string}  $target
     */
    private function request(array $target, string $apiKey): PendingRequest
    {
        return $this->pooled(Http::withHeaders([]), $target, $apiKey);
    }

    /**
     * @param  array{base: string, host: string, port: int, ip: string}  $target
     */
    private function pooled(PendingRequest $request, array $target, string $apiKey): PendingRequest
    {
        return $request->withToken($apiKey)
            ->acceptJson()
            ->timeout(20)
            ->withOptions($this->guard->curlOptions($target));
    }

    /**
     * @return array<mixed>
     */
    private function decode(?ProjectIntegration $integration, mixed $response, string $kind): array
    {
        if (! $response instanceof Response) {
            $reason = $response instanceof Throwable ? $response->getMessage() : 'no response';

            throw new RuntimeException("Failed to fetch Umami {$kind} metrics: {$reason}");
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $integration?->markNeedsReconnect('Umami API key unauthorized.');

            throw new SearchConsoleAuthException('Umami rejected the API key, or it can\'t read this website.');
        }

        if ($response->redirect()) {
            throw new RuntimeException("Umami redirected the {$kind} request; use the instance's final https address.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("Failed to fetch Umami {$kind} metrics: HTTP {$response->status()}");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<array{page: string, visitors: int, visits: int, pageviews: int, bounces: int, totaltime: int}>
     */
    private function parsePages(array $rows): array
    {
        $pages = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['name']) || (string) $row['name'] === '') {
                continue;
            }

            $pages[] = [
                'page' => (string) $row['name'],
                'visitors' => (int) ($row['visitors'] ?? 0),
                'visits' => (int) ($row['visits'] ?? 0),
                // Umami returns pageviews as a string.
                'pageviews' => (int) ($row['pageviews'] ?? 0),
                'bounces' => (int) ($row['bounces'] ?? 0),
                'totaltime' => (int) ($row['totaltime'] ?? 0),
            ];
        }

        return $pages;
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<array{referrer: string, visitors: int}>
     */
    private function parseReferrers(array $rows): array
    {
        return array_map(
            fn (array $row): array => ['referrer' => $row['page'], 'visitors' => $row['visitors']],
            $this->parseCounts($rows),
        );
    }

    /**
     * Rows of Umami's {x, y} metric format.
     *
     * @param  array<mixed>  $rows
     * @return list<array{page: string, visitors: int}>
     */
    private function parseCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['x']) || (string) $row['x'] === '') {
                continue;
            }

            $counts[] = ['page' => (string) $row['x'], 'visitors' => (int) ($row['y'] ?? 0)];
        }

        return $counts;
    }
}
