<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\ProviderNotConfiguredException;
use App\Domains\Serp\Services\KeywordResearchService;
use App\Domains\Serp\Services\SeoProviderRouter;
use App\Domains\Serp\SpendRefusedException;
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

    protected string $description = 'Look up search volume, CPC, competition and keyword difficulty for keywords. Runs as a dry run unless execute is true; live lookups are billed to a project\'s workspace allowance.';

    public function __construct(
        private readonly SeoProviderRouter $router,
        private readonly KeywordResearchService $keywordResearch,
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
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) $validated['keywords']),
        ))));

        if (! (bool) ($validated['execute'] ?? false)) {
            return Response::json([
                'mode' => 'dry_run',
                'source' => $source,
                'keywords' => $keywords,
                'next_step' => 'Call again with execute=true and a project domain to fetch live metrics.',
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

        $locationCode = (int) ($project->location_code ?: 2840);
        $languageCode = (string) ($project->language_code ?: 'en');

        try {
            $result = $this->router->lookupKeywords(
                $keywords,
                project: $project,
                locationCode: $locationCode,
                languageCode: $languageCode,
                seRankingSource: $source,
            );
        } catch (SpendRefusedException $e) {
            return Response::error($e->publicMessage());
        } catch (ProviderNotConfiguredException $e) {
            return Response::error($e->getMessage());
        }

        $metrics = $this->metrics($result['providers'], $locationCode, $languageCode);
        $missing = array_values(array_filter(
            $keywords,
            static fn (string $keyword): bool => ! isset($metrics[strtolower($keyword)]),
        ));

        // A failed upstream task can still come back as HTTP 200 with no
        // results. Don't pass that off as "no data" for every keyword.
        $lookupFailed = $this->lookupFailed($result['providers']['dataforseo']);
        if ($lookupFailed && $metrics === []) {
            return Response::error("Couldn't fetch keyword metrics right now. Try again in a few minutes.");
        }

        return Response::json([
            'mode' => 'live',
            'source' => $source,
            'project' => $project->domain,
            'keywords' => array_values($metrics),
            ...($lookupFailed ? ['unavailable' => $missing] : ['not_found' => $missing]),
        ]);
    }

    /**
     * Whether the detailed lookup ran and failed, at the request or task level.
     *
     * @param  array<string, mixed>  $payload
     */
    private function lookupFailed(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }

        if ((int) ($payload['status_code'] ?? 20000) >= 40000) {
            return true;
        }

        $tasks = is_array($payload['tasks'] ?? null) ? $payload['tasks'] : [];
        if ($tasks === []) {
            return true;
        }

        foreach ($tasks as $task) {
            if (! is_array($task) || (int) ($task['status_code'] ?? 20000) >= 40000) {
                return true;
            }
        }

        return false;
    }

    /**
     * One row per keyword in the same shape whichever source answered, so the
     * response doesn't reveal where the data came from.
     *
     * @param  array{seranking: list<array<string, mixed>>, dataforseo: array<string, mixed>}  $providers
     * @return array<string, array{keyword: string, search_volume: int|null, cpc: float|null, competition: float|null, keyword_difficulty: int|null}>
     */
    private function metrics(array $providers, int $locationCode, string $languageCode): array
    {
        $metrics = [];

        foreach ($providers['seranking'] as $row) {
            $keyword = strtolower(trim((string) ($row['keyword'] ?? '')));
            if ($keyword === '' || ($row['is_data_found'] ?? true) === false) {
                continue;
            }

            $metrics[$keyword] = [
                'keyword' => $keyword,
                'search_volume' => isset($row['volume']) ? (int) $row['volume'] : null,
                'cpc' => isset($row['cpc']) ? (float) $row['cpc'] : null,
                'competition' => isset($row['competition']) ? (float) $row['competition'] : null,
                'keyword_difficulty' => isset($row['difficulty']) ? (int) $row['difficulty'] : null,
            ];
        }

        foreach ($this->keywordResearch->parseOverviewItems($providers['dataforseo'], $locationCode, $languageCode) as $keyword => $row) {
            $metrics[$keyword] ??= [
                'keyword' => $keyword,
                'search_volume' => isset($row['search_volume']) ? (int) $row['search_volume'] : null,
                'cpc' => isset($row['cpc']) ? (float) $row['cpc'] : null,
                'competition' => isset($row['competition']) ? (float) $row['competition'] : null,
                'keyword_difficulty' => isset($row['keyword_difficulty']) ? (int) $row['keyword_difficulty'] : null,
            ];
        }

        return $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keywords' => $schema->string()
                ->description('Comma-separated keywords to look up')
                ->required(),
            'source' => $schema->string()
                ->description('Regional database code (default: us)'),
            'execute' => $schema->boolean()
                ->description('Set true to run a live, billed lookup; defaults to a dry run that only echoes the keywords'),
            'domain' => $schema->string()
                ->description('Project domain whose workspace allowance pays for live lookups (required when execute=true)'),
        ];
    }
}
