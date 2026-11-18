<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates internal callbacks from the headless `coqui-worker` sidecar.
 *
 * Fails closed: when COQUI_WORKER_TOKEN is not configured, every callback is rejected.
 */
class VerifyCoquiWorkerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('coqui.worker.token');

        if ($expected === '') {
            return response()->json(['message' => 'Worker callbacks are not configured.'], 503);
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid worker token.'], 401);
        }

        return $next($request);
    }
}
