<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function gate(): void
    {
        // Horizon shows every workspace's job payloads and can retry or delete
        // jobs, so outside local it is limited to platform operators.
        Gate::define('viewHorizon', function (?User $user): bool {
            return $user instanceof User && $user->isOperator();
        });
    }
}
