<?php

declare(strict_types=1);

namespace App\Domains\Crawls\DTOs;

use App\Domains\Crawls\CrawlSource;
use InvalidArgumentException;

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
        $source = (string) ($validated['source'] ?? CrawlSource::Desktop->value);

        // The DTO is the last line of defence against a source the platform
        // does not recognise; the API controller narrows this to client sources.
        if (CrawlSource::tryFrom($source) === null) {
            throw new InvalidArgumentException(sprintf('Unknown crawl source [%s].', $source));
        }

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
            source: $source,
        );
    }

    /**
     * Validation rules for one chunk of camelCase `UrlRecord`, `LinkRecord`, and `Issue` items.
     *
     * Shared by the desktop sync API and internal `coqui-worker` callbacks.
     *
     * @return array<string, list<string>>
     */
    public static function chunkRules(): array
    {
        $maxUrls = (int) config('coqui.sync.max_urls_per_chunk', 1000);
        $maxLinks = (int) config('coqui.sync.max_links_per_chunk', 2500);
        $maxIssues = (int) config('coqui.sync.max_issues_per_chunk', 2500);

        return [
            'urls' => ['sometimes', 'array', "max:{$maxUrls}"],
            'urls.*.id' => ['nullable', 'integer'],
            'urls.*.url' => ['required', 'string', 'max:4096'],
            'urls.*.status' => ['nullable', 'integer', 'min:0', 'max:999'],
            'urls.*.contentType' => ['nullable', 'string', 'max:160'],
            'urls.*.title' => ['nullable', 'string'],
            'urls.*.metaDescription' => ['nullable', 'string'],
            'urls.*.h1' => ['nullable', 'string'],
            'urls.*.h1Count' => ['nullable', 'integer', 'min:0'],
            'urls.*.canonical' => ['nullable', 'string'],
            'urls.*.metaRobots' => ['nullable', 'string', 'max:255'],
            'urls.*.redirectTarget' => ['nullable', 'string'],
            'urls.*.redirectChain' => ['nullable', 'array'],
            'urls.*.wordCount' => ['nullable', 'integer', 'min:0'],
            'urls.*.depth' => ['nullable', 'integer', 'min:0'],
            'urls.*.responseTimeMs' => ['nullable', 'integer', 'min:0'],
            'urls.*.sizeBytes' => ['nullable', 'integer', 'min:0'],
            'urls.*.error' => ['nullable', 'string'],
            'urls.*.discoveredAt' => ['nullable', 'integer', 'min:0'],

            'links' => ['sometimes', 'array', "max:{$maxLinks}"],
            'links.*.fromUrl' => ['required', 'string', 'max:4096'],
            'links.*.toUrl' => ['required', 'string', 'max:4096'],
            'links.*.anchor' => ['nullable', 'string'],
            'links.*.rel' => ['nullable', 'string', 'max:120'],
            'links.*.internal' => ['required', 'boolean'],

            'issues' => ['sometimes', 'array', "max:{$maxIssues}"],
            'issues.*.url' => ['required', 'string', 'max:4096'],
            'issues.*.rule' => ['required', 'string', 'max:96'],
            'issues.*.severity' => ['required', 'in:error,warning,opportunity'],
            'issues.*.detail' => ['nullable', 'string'],
        ];
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

        if (isset($config['authUser']) && $config['authUser'] !== '') {
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
