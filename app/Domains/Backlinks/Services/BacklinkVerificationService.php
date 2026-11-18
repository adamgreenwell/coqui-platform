<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Services;

use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Crawls\Services\CoquiWorkerClient;
use App\Domains\Projects\Models\Project;

class BacklinkVerificationService
{
    public function __construct(
        private readonly CoquiWorkerClient $worker,
    ) {}

    public function verifyProjectWatchlist(Project $project): int
    {
        $links = $project->monitoredBacklinks()->get();
        if ($links->isEmpty()) {
            return 0;
        }

        $urls = $links->pluck('source_url')->values()->all();
        $result = $this->worker->inspectUrls($urls, $project->domain);

        /** @var array<string, array<string, mixed>> $observations */
        $observations = [];
        foreach (($result['results'] ?? []) as $item) {
            if (is_array($item) && isset($item['url'])) {
                $observations[(string) $item['url']] = $item;
            }
        }

        $updated = 0;
        foreach ($links as $link) {
            /** @var MonitoredBacklink $link */
            $obs = $observations[$link->source_url] ?? null;
            if ($obs === null) {
                continue;
            }

            $status = isset($obs['status']) ? (int) $obs['status'] : null;
            $hasTargetLink = (bool) ($obs['hasTargetLink'] ?? false);
            $observedRel = isset($obs['observedRel']) ? (string) $obs['observedRel'] : null;
            $noindex = (bool) ($obs['noindex'] ?? false);

            $state = match (true) {
                $status === null || $status >= 400 => 'error',
                ! $hasTargetLink || $noindex => 'lost',
                $link->expected_rel !== null && $observedRel !== $link->expected_rel => 'rel_changed',
                default => 'active',
            };

            $link->update([
                'state' => $state,
                'last_http_status' => $status,
                'last_observed_rel' => $observedRel,
                'last_page_noindex' => $noindex,
                'last_verified_at' => now(),
            ]);

            $updated++;
        }

        return $updated;
    }
}
