<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Features\RunnrIntegration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hide Runnr routes (404) until the runnr-integration feature is turned on.
 */
class EnsureRunnrIntegrationEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(RunnrIntegration::enabled(), 404);

        return $next($request);
    }
}
