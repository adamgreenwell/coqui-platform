<?php

declare(strict_types=1);

namespace App\Domains\Crawls;

use RuntimeException;

/**
 * File imports need an object-storage bucket that isn't configured on this
 * server. The message is shown to clients, so it doesn't name the vendor.
 */
final class StorageNotConfiguredException extends RuntimeException
{
    public const string USER_MESSAGE = 'File imports are not available on this server yet. An administrator needs to configure object storage.';

    public function __construct()
    {
        parent::__construct(self::USER_MESSAGE);
    }
}
