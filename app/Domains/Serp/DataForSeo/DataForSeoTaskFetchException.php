<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use RuntimeException;

/**
 * Fetching a standard-queue task's result failed (transport error or an
 * error status), as opposed to the task still being queued.
 */
class DataForSeoTaskFetchException extends RuntimeException {}
