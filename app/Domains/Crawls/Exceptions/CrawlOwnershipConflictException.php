<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Exceptions;

use RuntimeException;

/**
 * A desktop sync referenced a crawl ID that belongs to another project or was
 * created by a server-side source (cloud worker, deploy smoke test).
 */
class CrawlOwnershipConflictException extends RuntimeException
{
    public static function forCrawl(string $externalCrawlId): self
    {
        return new self(sprintf('Crawl [%s] already exists and cannot be synced to this project.', $externalCrawlId));
    }
}
