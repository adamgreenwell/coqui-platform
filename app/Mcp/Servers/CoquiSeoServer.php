<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\InspectProjectSeoTool;
use App\Mcp\Tools\ListCrawlIssuesTool;
use App\Mcp\Tools\LookupKeywordMetricsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class CoquiSeoServer extends Server
{
    protected string $name = 'Coqui SEO Platform MCP';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'MARKDOWN'
        Coqui SEO Platform exposes project crawl summaries, technical SEO issues, and
        multi-provider keyword routing across SE Ranking and DataForSEO.
    MARKDOWN;

    /**
     * @var array<int|string, Tool|class-string<Tool>|array<int, Tool|class-string<Tool>>>
     */
    protected array $tools = [
        InspectProjectSeoTool::class,
        LookupKeywordMetricsTool::class,
        ListCrawlIssuesTool::class,
    ];
}
