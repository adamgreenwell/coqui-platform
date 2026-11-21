<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Billing\PlanFeature;
use App\Domains\Crawls\CrawlSource;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureRunnrIntegrationEnabled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;

class RunnrDeploySmokeController extends Controller implements HasMiddleware
{
    /**
     * Runnr endpoints stay hidden (404) until the runnr-integration feature is on.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware(EnsureRunnrIntegrationEnabled::class),
        ];
    }

    /**
     * Render an embeddable SVG status badge for the project's Runnr post-deploy smoke tests.
     */
    public function badge(Request $request, string $token): Response
    {
        $project = Project::query()->with('workspace')->where('deploy_badge_token', $token)->firstOrFail();

        // Badges stay embeddable after a downgrade but stop reporting results.
        $smokeTestsActive = $project->workspace?->hasFeature(PlanFeature::RunnrSmokeTests) === true;

        /** @var CrawlRun|null $latestRun */
        $latestRun = $smokeTestsActive
            ? CrawlRun::query()
                ->where('project_id', $project->id)
                ->where('source', CrawlSource::DeploySmoke->value)
                ->latest('id')
                ->first()
            : null;

        $statusText = $smokeTestsActive ? 'none' : 'inactive';
        $statusColor = '#9f9f9f';

        if ($latestRun !== null) {
            if (in_array($latestRun->status, ['pending', 'running'], true)) {
                $statusText = 'running';
                $statusColor = '#007ec6';
            } else {
                $config = $latestRun->config;
                /** @var array<string, mixed>|null $smokeResults */
                $smokeResults = is_array($config['smoke_results'] ?? null) ? $config['smoke_results'] : null;

                if ($smokeResults !== null && isset($smokeResults['passed'])) {
                    if ((bool) $smokeResults['passed']) {
                        $statusText = 'passing';
                        $statusColor = '#4c1';
                    } else {
                        $statusText = 'failing';
                        $statusColor = '#e05d44';
                    }
                } elseif ($latestRun->status === 'completed' && $latestRun->error_count === 0) {
                    $statusText = 'passing';
                    $statusColor = '#4c1';
                } else {
                    $statusText = 'failing';
                    $statusColor = '#e05d44';
                }
            }
        }

        $label = 'runnr deploy';
        $labelWidth = 84;
        $statusWidth = match ($statusText) {
            'passing' => 56,
            'failing' => 52,
            'running' => 54,
            'inactive' => 58,
            default => 44,
        };
        $totalWidth = $labelWidth + $statusWidth;
        $labelCenter = (int) round(($labelWidth / 2) * 10);
        $statusCenter = (int) round(($labelWidth + ($statusWidth / 2)) * 10);
        $labelTextLength = 700;
        $statusTextLength = match ($statusText) {
            'passing' => 440,
            'failing' => 400,
            'running' => 420,
            'inactive' => 480,
            default => 320,
        };

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$totalWidth}" height="20" role="img" aria-label="{$label}: {$statusText}">
  <linearGradient id="s" x2="0" y2="100%">
    <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
    <stop offset="1" stop-opacity=".1"/>
  </linearGradient>
  <clipPath id="r">
    <rect width="{$totalWidth}" height="20" rx="3" fill="#fff"/>
  </clipPath>
  <g clip-path="url(#r)">
    <rect width="{$labelWidth}" height="20" fill="#555"/>
    <rect x="{$labelWidth}" width="{$statusWidth}" height="20" fill="{$statusColor}"/>
    <rect width="{$totalWidth}" height="20" fill="url(#s)"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" text-rendering="geometricPrecision" font-size="110">
    <text aria-hidden="true" x="{$labelCenter}" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$labelTextLength}">{$label}</text>
    <text x="{$labelCenter}" y="140" transform="scale(.1)" fill="#fff" textLength="{$labelTextLength}">{$label}</text>
    <text aria-hidden="true" x="{$statusCenter}" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$statusTextLength}">{$statusText}</text>
    <text x="{$statusCenter}" y="140" transform="scale(.1)" fill="#fff" textLength="{$statusTextLength}">{$statusText}</text>
  </g>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Inspect the latest Runnr deploy smoke test details.
     */
    public function latest(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        /** @var CrawlRun|null $latestRun */
        $latestRun = CrawlRun::query()
            ->where('project_id', $project->id)
            ->where('source', CrawlSource::DeploySmoke->value)
            ->latest('id')
            ->first();

        if ($latestRun === null) {
            return response()->json([
                'projectId' => $project->id,
                'runnrSiteId' => $project->runnr_site_id,
                'hasSmokeRun' => false,
                'latest' => null,
            ]);
        }

        $config = $latestRun->config;
        /** @var array<string, mixed>|null $smokeResults */
        $smokeResults = is_array($config['smoke_results'] ?? null) ? $config['smoke_results'] : null;
        /** @var array<string, mixed>|null $deployMeta */
        $deployMeta = is_array($config['deploy'] ?? null) ? $config['deploy'] : null;

        return response()->json([
            'projectId' => $project->id,
            'runnrSiteId' => $project->runnr_site_id,
            'hasSmokeRun' => true,
            'latest' => [
                'crawlId' => $latestRun->external_crawl_id,
                'status' => $latestRun->status,
                'passed' => $smokeResults['passed'] ?? ($latestRun->status === 'completed' && $latestRun->error_count === 0),
                'smokeStatus' => $smokeResults['status'] ?? ($latestRun->status === 'completed' ? 'passed' : 'failed'),
                'summary' => $smokeResults['summary'] ?? "Crawl {$latestRun->status}",
                'regressions' => $smokeResults['regressions'] ?? [],
                'totalUrlsChecked' => $smokeResults['total_urls_checked'] ?? $latestRun->total_urls,
                'evaluatedAt' => $smokeResults['evaluated_at'] ?? $latestRun->finalized_at?->toIso8601String(),
                'deploy' => $deployMeta,
                'createdAt' => $latestRun->created_at->toIso8601String(),
                'finalizedAt' => $latestRun->finalized_at?->toIso8601String(),
            ],
        ]);
    }
}
