<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domains\Projects\Models\Project;
use App\Mcp\Concerns\ResolvesAccessibleProject;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListCrawlIssuesTool extends Tool
{
    use ResolvesAccessibleProject;

    protected string $name = 'list_crawl_issues';

    protected string $description = 'List technical SEO issues from the latest crawl run of a project domain.';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'severity' => ['nullable', 'in:error,warning,opportunity'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Authentication required to list crawl issues.');
        }

        $normalized = Project::normalizeDomain((string) $validated['domain']);
        $project = $this->resolveAccessibleProject($user, $normalized);

        if ($project === null) {
            return Response::error("Project not found for domain [{$normalized}].");
        }

        $latestCrawl = $project->latestAuditRun;
        if ($latestCrawl === null) {
            return Response::json([
                'domain' => $normalized,
                'issues' => [],
            ]);
        }

        $query = $latestCrawl->issues()->select(['url', 'rule', 'severity', 'detail']);

        if (isset($validated['severity'])) {
            $query->where('severity', (string) $validated['severity']);
        }

        $issues = $query
            ->limit((int) ($validated['limit'] ?? 50))
            ->get()
            ->toArray();

        return Response::json([
            'domain' => $normalized,
            'externalCrawlId' => $latestCrawl->external_crawl_id,
            'issues' => $issues,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'domain' => $schema->string()
                ->description('Project domain (e.g. coquiseo.com)')
                ->required(),
            'severity' => $schema->string()
                ->description('Optional severity filter: error, warning, or opportunity'),
            'limit' => $schema->integer()
                ->description('Maximum issue rows to return (1-100, default 50)'),
        ];
    }
}
