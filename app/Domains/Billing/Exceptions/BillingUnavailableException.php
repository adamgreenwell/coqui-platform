<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use RuntimeException;

/**
 * A billing action can't go ahead. The message is safe to show to the user;
 * Stripe response bodies and other internals are logged, never put here.
 */
class BillingUnavailableException extends RuntimeException {}
