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

class InspectProjectSeoTool extends Tool
{
    use ResolvesAccessibleProject;

    protected string $name = 'inspect_project_seo';

    protected string $description = 'Inspect SEO project status, latest Coqui crawl totals, tracked keywords, and monitored backlinks for a domain.';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Authentication required to inspect project SEO.');
        }

        $normalized = Project::normalizeDomain((string) $validated['domain']);
        $project = $this->resolveAccessibleProject($user, $normalized);

        if ($project === null) {
            return Response::error("Project not found for domain [{$normalized}].");
        }

        $project->load(['competitors:id,project_id,domain']);

        $latestCrawl = $project->crawlRuns()->latest('id')->first();

        return Response::json([
            'project' => [
                'id' => $project->uuid,
                'name' => $project->name,
                'domain' => $project->domain,
                'canonicalUrl' => $project->canonical_url,
                'locationCode' => $project->location_code,
                'languageCode' => $project->language_code,
                'competitors' => $project->competitors->pluck('domain')->values()->all(),
            ],
            'trackedKeywordsCount' => $project->trackedKeywords()->count(),
            'monitoredBacklinksCount' => $project->monitoredBacklinks()->count(),
            'latestCrawl' => $latestCrawl === null ? null : [
                'externalCrawlId' => $latestCrawl->external_crawl_id,
                'source' => $latestCrawl->source,
                'status' => $latestCrawl->status,
                'totalUrls' => $latestCrawl->total_urls,
                'errors' => $latestCrawl->error_count,
                'warnings' => $latestCrawl->warning_count,
                'opportunities' => $latestCrawl->opportunity_count,
            ],
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
        ];
    }
}
