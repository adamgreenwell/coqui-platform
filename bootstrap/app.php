<?php

declare(strict_types=1);

use App\Domains\Crawls\StorageNotConfiguredException;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Http\Middleware\EnsureWorkspaceHasFeature;
use App\Http\Middleware\ForceHttpsScheme;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->statefulApi();
        // TLS terminates at the platform proxy (Coolify/Traefik), then nginx.
        // php-fpm is only reachable inside the compose network, so trust the
        // forwarded scheme/host/IP headers from any hop.
        $middleware->trustProxies(at: '*');
        // Cloudflare reaches the origin over HTTP, so the forwarded scheme says
        // http even though visitors use https. Override it when APP_URL is https.
        $middleware->prepend(ForceHttpsScheme::class);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            // feature:<pennant-feature> requires the route's workspace plan to include it.
            'feature' => EnsureWorkspaceHasFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Missing provider credentials are a setup problem, not a crash: say
        // so instead of a 500, and keep it out of the error log.
        // Object storage that isn't configured is a setup problem too: say so
        // instead of failing with a 500 when a client asks to upload a file.
        $exceptions->dontReport(StorageNotConfiguredException::class);
        $exceptions->render(function (StorageNotConfiguredException $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'storage_not_configured' => true,
                ], 503);
            }

            return redirect()->back()->with('error', $e->getMessage());
        });

        $exceptions->dontReport(ProviderNotConfiguredException::class);
        $exceptions->render(function (ProviderNotConfiguredException $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'provider_not_configured' => true,
                ], 503);
            }

            return redirect()->back()->with('error', $e->getMessage());
        });
    })->create();
