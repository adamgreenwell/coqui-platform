<?php

declare(strict_types=1);

namespace App\Support\Http;

use InvalidArgumentException;

/**
 * A user-supplied URL failed PublicUrlGuard; the message is safe to show.
 */
class UnsafeUrlException extends InvalidArgumentException {}
