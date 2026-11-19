<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Exceptions;

use RuntimeException;

class SsrfProtectionException extends RuntimeException
{
    public static function blocked(string $url, string $reason): self
    {
        return new self(sprintf('Target URL [%s] was blocked by SSRF protection: %s', $url, $reason));
    }
}
