<?php

declare(strict_types=1);

namespace App\Domains\Crawls;

/**
 * Where a crawl run came from. Client-synced runs (desktop, import) arrive
 * through the sync API; server-side runs (worker, deploy_smoke) are created by
 * the platform itself and are never accepted from an API client.
 */
enum CrawlSource: string
{
    case Desktop = 'desktop';
    case Import = 'import';
    case Worker = 'worker';
    case DeploySmoke = 'deploy_smoke';

    /**
     * Every source a crawl run may have.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }

    /**
     * Sources a desktop client may sync through the public API.
     *
     * @return list<string>
     */
    public static function clientValues(): array
    {
        return [self::Desktop->value, self::Import->value];
    }

    /**
     * Sources the platform creates on the server side.
     *
     * @return list<string>
     */
    public static function serverValues(): array
    {
        return [self::Worker->value, self::DeploySmoke->value];
    }

    public function isClient(): bool
    {
        return in_array($this->value, self::clientValues(), true);
    }

    /**
     * An imported run (e.g. Screaming Frog) has URLs and links but no issue
     * data, so it must never stand in for a real audit.
     */
    public function isImported(): bool
    {
        return $this === self::Import;
    }

    public function label(): string
    {
        return match ($this) {
            self::Desktop => 'Desktop',
            self::Import => 'Import',
            self::Worker => 'Worker',
            self::DeploySmoke => 'Deploy smoke',
        };
    }
}
