<?php

declare(strict_types=1);

namespace App\Domains\Serp\SeRanking;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoCacheRepository;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Domains\Serp\SpendRefusedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for SE Ranking's standalone Data API (`api.seranking.com`).
 *
 * Includes payload-size bisection (~26KB limit) on 400/413/414/422, linear 429
 * throttle backoff, shared caching, credit spend guardrails, and an append-only
 * archive for point-in-time domain metrics. Only successful responses count
 * against the credit caps.
 */
class SeRankingClient
{
    public function __construct(
        private readonly DataForSeoCacheRepository $cache,
        private readonly SeRankingSpendGuard $spendGuard,
    ) {}

    public function isConfigured(): bool
    {
        return trim((string) config('coqui.seranking.api_key')) !== '';
    }

    /**
     * Query `/v1/keywords/export` (flat 100 credits per request) with batch bisection.
     *
     * @param  list<string>  $keywords
     * @return list<array<string, mixed>>
     */
    public function exportKeywords(
        array $keywords,
        string $source = 'us',
        ?Project $project = null,
        bool $bypassCache = false,
    ): array {
        return $this->exportKeywordsDetailed($keywords, $source, $project, $bypassCache)['rows'];
    }

    /**
     * Like exportKeywords, but a request that fails (an error status, a
     * connection error, a spend cap reached part-way) only loses its own
     * keywords: they come back in `failed` so a caller can try them
     * elsewhere, and the rows already fetched are kept. `credits` is what
     * this call spent (0 when served from cache).
     *
     * @param  list<string>  $keywords
     * @return array{rows: list<array<string, mixed>>, failed: list<string>, credits: int}
     */
    public function exportKeywordsDetailed(
        array $keywords,
        string $source = 'us',
        ?Project $project = null,
        bool $bypassCache = false,
    ): array {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $k): string => mb_strtolower(trim($k)),
            $keywords,
        ), static fn (string $k): bool => $k !== '')));

        if ($normalized === []) {
            return ['rows' => [], 'failed' => [], 'credits' => 0];
        }

        $endpoint = 'seranking:v1/keywords/export';
        $signature = [
            'keywords' => $normalized,
            'source' => strtolower($source),
        ];

        if (! $bypassCache) {
            $cached = $this->cache->get($endpoint, $signature);
            if ($cached !== null && isset($cached['items']) && is_array($cached['items'])) {
                /** @var list<array<string, mixed>> $cachedItems */
                $cachedItems = $cached['items'];

                return ['rows' => $cachedItems, 'failed' => [], 'credits' => 0];
            }
        }

        $batchSize = max(50, (int) config('coqui.seranking.batch_size', 1000));
        $rows = [];
        $failed = [];
        $credits = 0;
        $refused = null;

        foreach (array_chunk($normalized, $batchSize) as $chunk) {
            if ($refused !== null) {
                array_push($failed, ...$chunk);

                continue;
            }

            try {
                $result = $this->fetchKeywordChunkWithBisection($chunk, $source, $project);
            } catch (SpendRefusedException $e) {
                // A cap reached part-way: keep what was fetched, hand the rest back.
                $refused = $e;
                array_push($failed, ...$chunk);

                continue;
            }

            array_push($rows, ...$result['rows']);
            array_push($failed, ...$result['failed']);
            $credits += $result['credits'];
        }

        // Nothing fetched and nothing spent: let the caller see the refusal.
        if ($refused !== null && $rows === [] && $credits === 0) {
            throw $refused;
        }

        // A partial result isn't cached, so the failed keywords get retried.
        if ($failed === []) {
            $ttl = (int) config('coqui.seranking.cache_ttl_hours.keywords_export', 720);
            $this->cache->put($endpoint, $signature, ['items' => $rows], $ttl);
        }

        return ['rows' => $rows, 'failed' => $failed, 'credits' => $credits];
    }

    /**
     * Fetch `/v1/domain/overview/db` (100 credits) and archive point-in-time snapshot.
     *
     * @return array<string, mixed>
     */
    public function domainOverview(
        string $domain,
        string $source = 'us',
        ?Project $project = null,
        bool $bypassCache = false,
    ): array {
        $normalizedDomain = Project::normalizeDomain($domain);
        $endpoint = 'seranking:v1/domain/overview/db';
        $signature = [
            'domain' => $normalizedDomain,
            'source' => strtolower($source),
        ];

        if (! $bypassCache) {
            $cached = $this->cache->get($endpoint, $signature);
            if ($cached !== null) {
                return $cached;
            }
        }

        $estimatedCredits = (int) config('coqui.seranking.credit_costs.domain_overview', 100);
        $this->spendGuard->assertCanSpend($estimatedCredits);

        $response = $this->sendWithRateLimitRetry(
            fn (): Response => $this->http()->get($this->url('/v1/domain/overview/db'), [
                'domain' => $normalizedDomain,
                'source' => strtolower($source),
            ]),
        );

        // SE Ranking doesn't bill throttled or failed requests.
        $this->spendGuard->recordSpend(
            endpoint: '/v1/domain/overview/db',
            estimatedCredits: $estimatedCredits,
            actualCredits: $response->successful() ? $estimatedCredits : 0,
            workspaceId: $project?->workspace_id,
            projectId: $project?->id,
            httpStatus: $response->status(),
        );

        $response->throw();

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        $this->archiveCapture('/v1/domain/overview/db', $source, $normalizedDomain, $decoded, $project?->id);

        $ttl = (int) config('coqui.seranking.cache_ttl_hours.domain_overview', 336);
        $this->cache->put($endpoint, $signature, $decoded, $ttl);

        return $decoded;
    }

    /**
     * Splits a rejected batch in half until it fits. A batch that still fails
     * at the smallest size, or fails for another reason, lands in `failed`
     * instead of aborting the export.
     *
     * @param  list<string>  $chunk
     * @return array{rows: list<array<string, mixed>>, failed: list<string>, credits: int}
     */
    private function fetchKeywordChunkWithBisection(array $chunk, string $source, ?Project $project): array
    {
        $estimatedCredits = (int) config('coqui.seranking.credit_costs.keywords_export', 100);
        $this->spendGuard->assertCanSpend($estimatedCredits);

        try {
            $response = $this->sendWithRateLimitRetry(
                fn (): Response => $this->http()->post($this->url('/v1/keywords/export?source='.urlencode(strtolower($source))), [
                    'keywords' => $chunk,
                ]),
            );
        } catch (ConnectionException) {
            $this->spendGuard->recordSpend(
                endpoint: '/v1/keywords/export',
                estimatedCredits: $estimatedCredits,
                actualCredits: 0,
                workspaceId: $project?->workspace_id,
                projectId: $project?->id,
                meta: ['batch_count' => count($chunk), 'error' => 'connection'],
            );

            return ['rows' => [], 'failed' => $chunk, 'credits' => 0];
        }

        if (in_array($response->status(), [400, 413, 414, 422], true)) {
            $minSize = max(1, (int) config('coqui.seranking.min_split_batch_size', 50));
            if (count($chunk) > $minSize) {
                $mid = (int) ceil(count($chunk) / 2);
                $left = $this->fetchKeywordChunkWithBisection(array_slice($chunk, 0, $mid), $source, $project);
                try {
                    $right = $this->fetchKeywordChunkWithBisection(array_slice($chunk, $mid), $source, $project);
                } catch (SpendRefusedException) {
                    // The cap was reached after the left half was paid for; keep it.
                    $right = ['rows' => [], 'failed' => array_slice($chunk, $mid), 'credits' => 0];
                }

                return [
                    'rows' => [...$left['rows'], ...$right['rows']],
                    'failed' => [...$left['failed'], ...$right['failed']],
                    'credits' => $left['credits'] + $right['credits'],
                ];
            }
        }

        $actualCredits = $response->successful() ? $estimatedCredits : 0;

        $this->spendGuard->recordSpend(
            endpoint: '/v1/keywords/export',
            estimatedCredits: $estimatedCredits,
            actualCredits: $actualCredits,
            workspaceId: $project?->workspace_id,
            projectId: $project?->id,
            httpStatus: $response->status(),
            meta: ['batch_count' => count($chunk)],
        );

        if (! $response->successful()) {
            return ['rows' => [], 'failed' => $chunk, 'credits' => 0];
        }

        $decoded = $response->json();
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];

        return ['rows' => $rows, 'failed' => [], 'credits' => $actualCredits];
    }

    /**
     * SE Ranking throttles at 429 without billing; honor Retry-After up to 5 attempts.
     *
     * @param  callable(): Response  $sender
     */
    private function sendWithRateLimitRetry(callable $sender): Response
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $sender();

            if ($response->status() !== 429 || $attempt === 5) {
                return $response;
            }

            $retryAfter = (int) ($response->header('Retry-After') ?: $attempt);
            if ($retryAfter > 0 && ! app()->runningUnitTests()) {
                sleep(min(5, $retryAfter));
            }
        }

        throw new RuntimeException('Unreachable retry termination.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function archiveCapture(
        string $endpoint,
        string $sourceRegion,
        ?string $targetDomain,
        array $payload,
        ?int $projectId,
    ): void {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $encoded);
        $now = now();

        $existing = DB::table('seranking_archive')
            ->where('endpoint', $endpoint)
            ->where('source_region', strtolower($sourceRegion))
            ->where('target_domain', $targetDomain)
            ->where('payload_hash', $hash)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('seranking_archive')
                ->where('id', $existing->id)
                ->update([
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('seranking_archive')->insert([
            'project_id' => $projectId,
            'endpoint' => $endpoint,
            'source_region' => strtolower($sourceRegion),
            'target_domain' => $targetDomain,
            'payload_hash' => $hash,
            'payload' => $encoded,
            'first_captured_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function http(): PendingRequest
    {
        $apiKey = trim((string) config('coqui.seranking.api_key'));
        if ($apiKey === '') {
            throw new ProviderNotConfiguredException('seranking');
        }

        return Http::withHeaders([
            'Authorization' => 'Token '.$apiKey,
        ])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('coqui.seranking.timeout', 30));
    }

    private function url(string $endpoint): string
    {
        $base = rtrim((string) config('coqui.seranking.base_url'), '/');

        return $base.'/'.ltrim($endpoint, '/');
    }
}
