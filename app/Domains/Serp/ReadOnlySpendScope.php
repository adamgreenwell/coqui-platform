<?php

declare(strict_types=1);

namespace App\Domains\Serp;

use App\Domains\Serp\DataForSeo\DataForSeoReadOnlyException;
use Throwable;

/**
 * Runs provider lookups in "cached data only" mode.
 *
 * Inside run(), every spend guard (DataForSEO and SE Ranking) refuses new
 * paid requests, so callers still get cache hits but a cache miss throws
 * DataForSeoReadOnlyException instead of billing the workspace. Used for
 * read-only workspace members.
 */
final class ReadOnlySpendScope
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws Throwable
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @throws DataForSeoReadOnlyException
     */
    public static function assertSpendAllowed(): void
    {
        if (self::active()) {
            throw new DataForSeoReadOnlyException(
                'Your workspace role can view cached SEO data only. Ask an owner, admin or editor to run a fresh lookup.'
            );
        }
    }
}
