<?php

declare(strict_types=1);

namespace App\Domains\Serp;

use RuntimeException;
use Throwable;

/**
 * A paid data lookup was refused before it ran. The exception message is for
 * logs and may name the data provider and platform spend; publicMessage() is
 * what users and MCP clients see.
 */
class SpendRefusedException extends RuntimeException
{
    public const DEFAULT_PUBLIC_MESSAGE = 'Live data lookups are paused right now. Cached results are still available; try again later.';

    public function __construct(
        string $message = '',
        private readonly ?string $publicMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function publicMessage(): string
    {
        return $this->publicMessage ?? self::DEFAULT_PUBLIC_MESSAGE;
    }
}
