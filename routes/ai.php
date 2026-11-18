<?php

declare(strict_types=1);

use App\Mcp\Servers\CoquiSeoServer;
use Laravel\Mcp\Facades\Mcp;

$route = Mcp::web((string) config('coqui.mcp.path', '/mcp/coqui'), CoquiSeoServer::class)
    ->name('mcp.coqui');

if ((bool) config('coqui.mcp.require_auth', false)) {
    $route->middleware('auth:sanctum');
}

Mcp::local('coqui-seo', CoquiSeoServer::class);
