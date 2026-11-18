<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use Illuminate\Support\Facades\DB;

class DataForSeoCacheRepository
{
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
