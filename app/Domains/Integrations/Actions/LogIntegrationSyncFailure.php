<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Projects\Models\ProjectActivity;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records a data collector (integration sync) that failed for good in the
 * project's activity feed.
 */
final class LogIntegrationSyncFailure
{
    public static function handle(ProjectIntegration $integration, string $label, ?Throwable $exception): void
    {
        $project = $integration->project;
        if ($project === null) {
            return;
        }

        $reason = $exception !== null ? Str::limit($exception->getMessage(), 300) : null;

        rescue(fn () => ProjectActivity::log(
            project: $project,
            eventType: 'integration.sync_failed',
            title: sprintf('%s sync failed', $label),
            description: $reason !== null && $reason !== ''
                ? sprintf('The %s sync failed after retrying: %s', $label, $reason)
                : sprintf('The %s sync failed after retrying.', $label),
            properties: [
                'provider' => $integration->provider,
                'error' => $reason,
            ],
        ), report: true);
    }
}
