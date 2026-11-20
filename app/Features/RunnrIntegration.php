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
 * workspace. It always lives in Pennant's `database` store, whatever the
 * default store is: plan features use the non-persistent `array` store so
 * they are resolved fresh from billing state (PR 116), and an activation
 * kept only in memory would be lost after the request. To turn it on or off
 * in an environment:
 *
 *     php artisan tinker --execute="App\Features\RunnrIntegration::activate()"
 *     php artisan tinker --execute="App\Features\RunnrIntegration::deactivate()"
 */
#[Name(self::NAME)]
final class RunnrIntegration
{
    public const NAME = 'runnr-integration';

    /**
     * The Pennant store that keeps the switch. Must persist across requests.
     */
    public const STORE = 'database';

    /**
     * Off unless it has been activated explicitly.
     */
    public function resolve(mixed $scope): bool
    {
        return false;
    }

    public static function enabled(): bool
    {
        return Feature::store(self::STORE)->for(null)->active(self::class);
    }

    public static function activate(): void
    {
        Feature::store(self::STORE)->for(null)->activate(self::class);
    }

    public static function deactivate(): void
    {
        Feature::store(self::STORE)->for(null)->deactivate(self::class);
    }
}
