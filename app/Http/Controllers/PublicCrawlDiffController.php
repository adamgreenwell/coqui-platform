<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Crawls\Services\CrawlDiffService;
use Inertia\Inertia;
use Inertia\Response;

class PublicCrawlDiffController extends Controller
{
    public function __construct(
        private readonly CrawlDiffService $diffService,
    ) {}

    public function show(string $shareToken): Response
    {
        $report = $this->diffService->getReportByToken($shareToken);

        if ($report === null) {
            abort(404, 'Shared crawl report has expired or does not exist.');
        }

        $report->incrementViews();

        $diff = $this->diffService->compare($report->baselineRun, $report->targetRun);

        return Inertia::render('Crawls/Compare', [
            'workspace' => [
                'name' => $report->project->workspace->name,
            ],
            // Public page: no project identifier at all, only what the report shows.
            'project' => [
                'name' => $report->project->name,
                'domain' => $report->project->domain,
            ],
            'availableRuns' => [],
            'selectedBaselineId' => $report->baselineRun->external_crawl_id,
            'selectedTargetId' => $report->targetRun->external_crawl_id,
            'diff' => $diff,
            'isReadOnly' => true,
            'reportTitle' => $report->title,
            'expiresAt' => $report->expires_at?->toIso8601String(),
        ]);
    }
}
