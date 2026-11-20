<?php

declare(strict_types=1);

namespace App\Features;

use Laravel\Pennant\Attributes\Name;
use Laravel\Pennant\Feature;

/**
 * Global switch for every user-facing part of the Runnr integration.
 *
 * It stays off until the Runnr platform is ready. While it is off, Runnr UI is
 * hidden, the Runnr add-on cannot be bought, and Runnr routes return 404.
 * Stripe webhooks still sync existing `runnr_addon` subscriptions regardless.
 *
 * The flag is global (null scope), so one stored value turns it on for every
 * workspace. To turn it on or off in an environment:
 *
 *     php artisan tinker --execute="App\Features\RunnrIntegration::activate()"
 *     php artisan tinker --execute="App\Features\RunnrIntegration::deactivate()"
 */
#[Name(self::NAME)]
final class RunnrIntegration
{
    public const NAME = 'runnr-integration';

    /**
     * Off unless it has been activated explicitly.
     */
    public function resolve(mixed $scope): bool
    {
        return false;
    }

    public static function enabled(): bool
    {
        return Feature::for(null)->active(self::class);
    }

    public static function activate(): void
    {
        Feature::for(null)->activate(self::class);
    }

    public static function deactivate(): void
    {
        Feature::for(null)->deactivate(self::class);
    }
}
