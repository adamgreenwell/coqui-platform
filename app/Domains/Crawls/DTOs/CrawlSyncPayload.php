<?php

declare(strict_types=1);

namespace App\Domains\Crawls\DTOs;

/**
 * Validates and scrubs camelCase payloads emitted by `coqui-core` (Rust).
 *
 * Matches `CrawlSummary` and `CrawlConfig` in `coqui/crates/coqui-core/src/lib.rs`.
 */
final readonly class CrawlSyncPayload
{
    private const array SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'proxy-authorization',
        'x-api-key',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $externalCrawlId,
        public string $seed,
        public string $seedKind,
        public string $status,
        public array $config,
        public int $createdAtMs,
        public ?int $startedAtMs,
        public ?int $finishedAtMs,
        public int $totalUrls,
        public string $source,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        /** @var array<string, mixed> $rawConfig */
        $rawConfig = is_array($validated['config'] ?? null) ? $validated['config'] : [];

        return new self(
            externalCrawlId: (string) $validated['id'],
            seed: (string) $validated['seed'],
            seedKind: (string) $validated['seedKind'],
            status: (string) $validated['status'],
            config: self::scrubConfigSecrets($rawConfig),
            createdAtMs: (int) $validated['createdAt'],
            startedAtMs: isset($validated['startedAt']) ? (int) $validated['startedAt'] : null,
            finishedAtMs: isset($validated['finishedAt']) ? (int) $validated['finishedAt'] : null,
            totalUrls: (int) ($validated['totalUrls'] ?? 0),
            source: (string) ($validated['source'] ?? 'desktop'),
        );
    }

    /**
     * Strip credentials and sensitive header values before persisting config JSON.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function scrubConfigSecrets(array $config): array
    {
        unset($config['authPassword']);

        if (isset($config['authUser']) && $config['authUser'] !== null && $config['authUser'] !== '') {
            $config['authUser'] = '[redacted]';
        }

        if (isset($config['customHeaders']) && is_array($config['customHeaders'])) {
            $config['customHeaders'] = array_values(array_map(static function (mixed $line): string {
                $raw = (string) $line;
                if (! str_contains($raw, ':')) {
                    return $raw;
                }

                [$name, $value] = explode(':', $raw, 2);
                $headerName = strtolower(trim($name));

                if (in_array($headerName, self::SENSITIVE_HEADERS, true)) {
                    return trim($name).': [redacted]';
                }

                return trim($name).': '.trim($value);
            }, $config['customHeaders']));
        }

        return $config;
    }
}
