<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Treat every request as HTTPS when the app is configured for an https URL.
 *
 * In production, Cloudflare terminates TLS and reaches the origin over HTTP,
 * so Traefik forwards `X-Forwarded-Proto: http`. Laravel then builds
 * `http://` redirects and URLs, which browsers block as mixed content inside
 * Inertia requests. Visitors only ever reach the app over https, so the
 * forwarded scheme is overridden rather than trusted.
 */
final class ForceHttpsScheme
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https') === true) {
            $request->headers->set('X-Forwarded-Proto', 'https');
            $request->headers->set('X-Forwarded-Port', '443');
            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', 443);

            URL::forceScheme('https');
        }

        return $next($request);
    }
}
