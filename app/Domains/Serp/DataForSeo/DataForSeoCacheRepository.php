<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use Illuminate\Support\Facades\DB;

class DataForSeoCacheRepository
{
    public const KEYWORD_ENDPOINT = '/v3/dataforseo_labs/google/keyword_overview/live';

    /**
     * @return array<string, mixed>
     */
    public function keywordSignature(string $keyword, int $locationCode = 2840, string $languageCode = 'en'): array
    {
        return [
            'keyword' => strtolower(trim($keyword)),
            'location_code' => $locationCode,
            'language_code' => strtolower(trim($languageCode)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getKeywordMetrics(string $keyword, int $locationCode = 2840, string $languageCode = 'en'): ?array
    {
        return $this->get(self::KEYWORD_ENDPOINT, $this->keywordSignature($keyword, $locationCode, $languageCode));
    }

    /**
     * Batch fetch cached keyword metrics in a single query.
     *
     * @param  list<string>  $keywords
     * @return array<string, array<string, mixed>> Map of normalized_keyword => metrics
     */
    public function getKeywordMetricsBatch(array $keywords, int $locationCode = 2840, string $languageCode = 'en'): array
    {
        $keyMap = [];
        foreach ($keywords as $kw) {
            $norm = strtolower(trim($kw));
            if ($norm === '') {
                continue;
            }
            $signature = $this->keywordSignature($norm, $locationCode, $languageCode);
            $cacheKey = $this->buildKey(self::KEYWORD_ENDPOINT, $signature);
            $keyMap[$cacheKey] = $norm;
        }

        if ($keyMap === []) {
            return [];
        }

        $rows = DB::table('dataforseo_cache')
            ->whereIn('cache_key', array_keys($keyMap))
            ->where('expires_at', '>', now())
            ->get(['cache_key', 'payload']);

        $results = [];
        foreach ($rows as $row) {
            $cacheKey = (string) $row->cache_key;
            $normKeyword = $keyMap[$cacheKey] ?? null;
            if ($normKeyword !== null) {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode((string) $row->payload, true);
                if (is_array($decoded)) {
                    $results[$normKeyword] = $decoded;
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function putKeywordMetrics(
        string $keyword,
        array $metrics,
        int $locationCode = 2840,
        string $languageCode = 'en',
        int $ttlHours = 720,
    ): void {
        $this->put(
            self::KEYWORD_ENDPOINT,
            $this->keywordSignature($keyword, $locationCode, $languageCode),
            $metrics,
            $ttlHours,
        );
    }

    /**
     * Batch store keyword metrics in a single upsert query.
     *
     * @param  array<string, array<string, mixed>>  $keywordMetricsMap  Map of keyword => metrics
     */
    public function putKeywordMetricsBatch(
        array $keywordMetricsMap,
        int $locationCode = 2840,
        string $languageCode = 'en',
        int $ttlHours = 720,
    ): void {
        if ($keywordMetricsMap === []) {
            return;
        }

        $now = now();
        $expiresAt = $now->copy()->addHours(max(1, $ttlHours));
        $rows = [];

        foreach ($keywordMetricsMap as $keyword => $metrics) {
            $norm = strtolower(trim((string) $keyword));
            if ($norm === '') {
                continue;
            }
            $signature = $this->keywordSignature($norm, $locationCode, $languageCode);
            $cacheKey = $this->buildKey(self::KEYWORD_ENDPOINT, $signature);

            $rows[] = [
                'cache_key' => $cacheKey,
                'endpoint' => self::KEYWORD_ENDPOINT,
                'request_signature' => json_encode($this->normalizeArray($signature), JSON_THROW_ON_ERROR),
                'payload' => json_encode($metrics, JSON_THROW_ON_ERROR),
                'fetched_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('dataforseo_cache')->upsert(
                    $chunk,
                    ['cache_key'],
                    ['payload', 'fetched_at', 'expires_at', 'updated_at'],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $signature
     * @return array<string, mixed>|null
     */
    public function get(string $endpoint, array $signature): ?array
    {
        $cacheKey = $this->buildKey($endpoint, $signature);

        $row = DB::table('dataforseo_cache')
            ->where('cache_key', $cacheKey)
            ->where('expires_at', '>', now())
            ->first(['payload']);

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) $row->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $signature
     * @param  array<string, mixed>  $payload
     */
    public function put(string $endpoint, array $signature, array $payload, int $ttlHours): void
    {
        $cacheKey = $this->buildKey($endpoint, $signature);
        $now = now();

        DB::table('dataforseo_cache')->upsert(
            [
                [
                    'cache_key' => $cacheKey,
                    'endpoint' => $endpoint,
                    'request_signature' => json_encode($this->normalizeArray($signature), JSON_THROW_ON_ERROR),
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'fetched_at' => $now,
                    'expires_at' => $now->copy()->addHours(max(1, $ttlHours)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['cache_key'],
            ['payload', 'fetched_at', 'expires_at', 'updated_at'],
        );
    }

    /**
     * @param  array<string, mixed>  $signature
     */
    public function buildKey(string $endpoint, array $signature): string
    {
        $normalized = [
            'endpoint' => trim($endpoint, '/'),
            'signature' => $this->normalizeArray($signature),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private function normalizeArray(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input);
        }

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->normalizeArray($value);
            } elseif (is_string($value)) {
                $input[$key] = strtolower(trim($value));
            }
        }

        return $input;
    }
}
