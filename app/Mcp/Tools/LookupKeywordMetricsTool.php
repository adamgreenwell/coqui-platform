<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\DataForSeo\DataForSeoBudgetException;
use App\Domains\Serp\SeRanking\SeRankingAcceptanceRule;
use App\Domains\Serp\SeRanking\SeRankingBudgetException;
use App\Domains\Serp\Services\SeoProviderRouter;
use App\Mcp\Concerns\ResolvesAccessibleProject;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LookupKeywordMetricsTool extends Tool
{
    use ResolvesAccessibleProject;

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
            'domain' => ['nullable', 'string', 'max:255'],
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

        // Live lookups are paid: they must be billed to a project's workspace budget.
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Authentication required for live keyword lookups.');
        }

        $domain = trim((string) ($validated['domain'] ?? ''));
        if ($domain === '') {
            return Response::error('Live keyword lookups require the domain of a project to bill.');
        }

        $normalized = Project::normalizeDomain($domain);
        $project = $this->resolveAccessibleProject($user, $normalized, 'update');
        if ($project === null) {
            return Response::error("Project not found for domain [{$normalized}].");
        }

        try {
            $result = $this->router->lookupKeywords(
                $keywords,
                project: $project,
                locationCode: (int) ($project->location_code ?: 2840),
                languageCode: (string) ($project->language_code ?: 'en'),
                seRankingSource: $source,
            );
        } catch (DataForSeoBudgetException|SeRankingBudgetException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'mode' => 'live',
            'source' => $source,
            'project' => $project->domain,
            ...$result,
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
            'domain' => $schema->string()
                ->description('Project domain whose workspace budget pays for live lookups (required when execute=true)'),
        ];
    }
}
