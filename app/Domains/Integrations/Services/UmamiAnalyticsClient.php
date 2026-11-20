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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Reads page traffic from a self-hosted or cloud Umami (v3) instance.
 *
 * Umami's metrics endpoints only return totals for the requested range, so
 * each day is fetched separately. Umami can't filter by channel, but it can
 * filter pages by referrer, which is how search and AI visits are counted
 * per page.
 *
 * Self-hosted instances serve the API under /api. Umami Cloud serves it at
 * api.umami.is/v1 and limits each key to 50 calls every 15 seconds, so Cloud
 * requests are paced.
 */
class UmamiAnalyticsClient
{
    private const string CLOUD_HOST = 'api.umami.is';

    private const int CLOUD_WINDOW_MS = 15_000;

    /** A margin under Umami Cloud's 50 calls per window. */
    private const int CLOUD_CALLS_PER_WINDOW = 45;

    private const int REFERRERS_PER_BATCH = 20;

    private const int PAGE_LIMIT = 1000;

    private const int REFERRER_LIMIT = 500;

    /** Stop after this many pages of one metric (1,000 rows each). */
    private const int MAX_PAGES = 25;

    /** @var list<int> when recent Cloud requests were sent, in ms */
    private array $cloudSentAt = [];

    public function __construct(
        private readonly PublicUrlGuard $guard,
    ) {}

    /**
     * Websites the API key can read, including team websites.
     *
     * @return list<array{id: string, name: string, domain: string}>
     */
    public function listWebsites(string $baseUrl, string $apiKey): array
    {
        $target = $this->guard->check($baseUrl);

        $this->throttle($target, 1);
        $response = $this->request($target, $apiKey)->get($this->apiRoot($target).'/websites', ['pageSize' => 200, 'includeTeams' => 'true']);

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
        $endpoint = "{$this->apiRoot($target)}/websites/{$websiteId}";

        $dates = [];
        $last = CarbonImmutable::parse($endDate->format('Y-m-d'), 'UTC');
        for ($day = CarbonImmutable::parse($startDate->format('Y-m-d'), 'UTC'); $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        $daily = [];
        foreach (array_chunk($dates, max(1, $daysPerBatch)) as $batch) {
            $this->throttle($target, count($batch) * 2);
            $responses = Http::pool(function (Pool $pool) use ($batch, $endpoint, $target, $apiKey): array {
                $requests = [];
                foreach ($batch as $date) {
                    $requests[] = $this->pooled($pool->as("pages:{$date}"), $target, $apiKey)
                        ->get("{$endpoint}/metrics/expanded", $this->query($date, ['type' => 'path', 'limit' => self::PAGE_LIMIT]));
                    $requests[] = $this->pooled($pool->as("referrers:{$date}"), $target, $apiKey)
                        ->get("{$endpoint}/metrics", $this->query($date, ['type' => 'referrer', 'limit' => self::REFERRER_LIMIT]));
                }

                return $requests;
            });

            foreach ($batch as $date) {
                $pages = $this->remainingPages(
                    $integration, $target, $apiKey, "{$endpoint}/metrics/expanded",
                    $this->query($date, ['type' => 'path', 'limit' => self::PAGE_LIMIT]),
                    $this->decode($integration, $responses["pages:{$date}"] ?? null, 'page'), 'page',
                );
                $referrers = $this->remainingPages(
                    $integration, $target, $apiKey, "{$endpoint}/metrics",
                    $this->query($date, ['type' => 'referrer', 'limit' => self::REFERRER_LIMIT]),
                    $this->decode($integration, $responses["referrers:{$date}"] ?? null, 'referrer'), 'referrer',
                );

                $daily[$date] = [
                    'pages' => $this->parsePages($pages),
                    'referrers' => $this->parseReferrers($referrers),
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
        $endpoint = "{$this->apiRoot($target)}/websites/{$websiteId}/metrics";

        $pages = [];
        foreach (array_chunk($referrers, self::REFERRERS_PER_BATCH) as $batch) {
            $this->throttle($target, count($batch));

            $responses = Http::pool(function (Pool $pool) use ($batch, $endpoint, $date, $target, $apiKey): array {
                $requests = [];
                foreach ($batch as $i => $referrer) {
                    $requests[] = $this->pooled($pool->as("ref:{$i}"), $target, $apiKey)
                        ->get($endpoint, $this->query($date, ['type' => 'path', 'limit' => self::PAGE_LIMIT, 'referrer' => $referrer]));
                }

                return $requests;
            });

            foreach ($batch as $i => $referrer) {
                $pages[$referrer] = $this->parseCounts($this->remainingPages(
                    $integration, $target, $apiKey, $endpoint,
                    $this->query($date, ['type' => 'path', 'limit' => self::PAGE_LIMIT, 'referrer' => $referrer]),
                    $this->decode($integration, $responses["ref:{$i}"] ?? null, 'referrer page'), 'referrer page',
                ));
            }
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
     * Umami returns at most `limit` rows per metric. When the first page is
     * full, fetch the following ones by offset so a busy day isn't cut short.
     *
     * @param  array{base: string, host: string, port: int, ip: string}  $target
     * @param  array<string, int|string>  $query
     * @param  array<mixed>  $firstPage
     * @return array<mixed>
     */
    private function remainingPages(
        ProjectIntegration $integration,
        array $target,
        string $apiKey,
        string $url,
        array $query,
        array $firstPage,
        string $kind,
    ): array {
        $limit = (int) $query['limit'];
        $rows = $firstPage;
        $previous = $firstPage;

        for ($page = 1; count($previous) >= $limit && $page < self::MAX_PAGES; $page++) {
            $this->throttle($target, 1);
            $next = $this->decode(
                $integration,
                $this->request($target, $apiKey)->get($url, [...$query, 'offset' => $page * $limit]),
                $kind,
            );

            // An instance that ignores offset sends the first page again.
            if ($next === [] || $next === $previous) {
                break;
            }

            $rows = [...$rows, ...$next];
            $previous = $next;
        }

        return $rows;
    }

    /**
     * The API root: /api on a self-hosted instance, /v1 (or a regional
     * /v1/us, /v1/eu) on Umami Cloud. A root already in the URL is kept.
     *
     * @param  array{base: string, host: string, port: int, ip: string}  $target
     */
    private function apiRoot(array $target): string
    {
        $path = (string) parse_url($target['base'], PHP_URL_PATH);

        if ($target['host'] === self::CLOUD_HOST) {
            return preg_match('#^/v1(/|$)#', $path) === 1 ? $target['base'] : $target['base'].'/v1';
        }

        return str_ends_with($path, '/api') ? $target['base'] : $target['base'].'/api';
    }

    /**
     * Wait, if needed, so the next $calls Cloud requests stay under the
     * per-key limit.
     *
     * @param  array{base: string, host: string, port: int, ip: string}  $target
     */
    private function throttle(array $target, int $calls): void
    {
        if ($target['host'] !== self::CLOUD_HOST) {
            return;
        }

        while (true) {
            $now = Carbon::now()->getTimestampMs();
            $this->cloudSentAt = array_values(array_filter(
                $this->cloudSentAt,
                fn (int $sentAt): bool => $sentAt > $now - self::CLOUD_WINDOW_MS,
            ));

            if ($this->cloudSentAt === [] || count($this->cloudSentAt) + $calls <= self::CLOUD_CALLS_PER_WINDOW) {
                break;
            }

            Sleep::for(min($this->cloudSentAt) + self::CLOUD_WINDOW_MS - $now + 1)->milliseconds();
        }

        array_push($this->cloudSentAt, ...array_fill(0, max(1, $calls), Carbon::now()->getTimestampMs()));
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

        if ($response->status() === 429) {
            throw new RuntimeException("Umami rate-limited the {$kind} request. The sync will retry.");
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
