<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domains\Serp\SeRanking\SeRankingAcceptanceRule;
use App\Domains\Serp\Services\SeoProviderRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LookupKeywordMetricsTool extends Tool
{
    protected string $name = 'lookup_keyword_metrics';

    protected string $description = 'Inspect keyword acceptance routing and fetch metrics across SE Ranking and DataForSEO.';

    public function __construct(
        private readonly SeRankingAcceptanceRule $acceptanceRule,
        private readonly SeoProviderRouter $router,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'keywords' => ['required', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:16'],
            'execute' => ['nullable', 'boolean'],
        ]);

        $source = strtolower((string) ($validated['source'] ?? config('coqui.seranking.default_source', 'us')));
        $keywords = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) $validated['keywords']),
        )));

        $partition = $this->acceptanceRule->partition($keywords, $source);
        $shouldExecute = (bool) ($validated['execute'] ?? false);

        if (! $shouldExecute) {
            return Response::json([
                'mode' => 'dry_run',
                'source' => $source,
                'routing' => [
                    'seranking' => $partition['accepted'],
                    'dataforseo_fallback' => $partition['rejected'],
                ],
            ]);
        }

        return Response::json([
            'mode' => 'live',
            'source' => $source,
            ...$this->router->lookupKeywords($keywords, seRankingSource: $source),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keywords' => $schema->string()
                ->description('Comma-separated keywords to inspect')
                ->required(),
            'source' => $schema->string()
                ->description('Regional database code (default: us)'),
            'execute' => $schema->boolean()
                ->description('Set true to execute live provider lookups; defaults to dry-run routing preview'),
        ];
    }
}
