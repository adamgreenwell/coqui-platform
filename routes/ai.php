<?php

declare(strict_types=1);

use App\Mcp\Servers\CoquiSeoServer;
use Laravel\Mcp\Facades\Mcp;

$route = Mcp::web((string) config('coqui.mcp.path', '/mcp/coqui'), CoquiSeoServer::class)
    ->name('mcp.coqui')
    ->middleware('throttle:'.max(1, (int) config('coqui.mcp.rate_limit_per_minute', 60)).',1');

if ((bool) config('coqui.mcp.require_auth', true)) {
    // Session users pass the ability check; API tokens must carry mcp:use.
    $route->middleware(['auth:sanctum', 'abilities:mcp:use']);
}

Mcp::local('coqui-seo', CoquiSeoServer::class);
