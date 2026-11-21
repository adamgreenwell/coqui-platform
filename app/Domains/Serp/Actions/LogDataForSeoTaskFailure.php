<?php

declare(strict_types=1);

namespace App\Domains\Serp\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;

/**
 * Records a failed rank-tracking task in its project's activity feed.
 */
final class LogDataForSeoTaskFailure
{
    /**
     * @param  object  $task  A dataforseo_tasks row.
     */
    public static function handle(object $task, int $statusCode, string $statusMessage): void
    {
        $project = Project::query()->find((int) ($task->project_id ?? 0));
        if (! $project instanceof Project) {
            return;
        }

        $context = is_string($task->request_context ?? null) ? (json_decode($task->request_context, true) ?: []) : [];
        $keyword = is_array($context) && isset($context['keyword']) ? (string) $context['keyword'] : null;

        rescue(fn () => ProjectActivity::log(
            project: $project,
            eventType: 'serp.task_failed',
            title: 'Rank check failed',
            description: $keyword !== null
                ? sprintf('The rank check for "%s" failed (%d%s).', $keyword, $statusCode, $statusMessage !== '' ? ': '.$statusMessage : '')
                : sprintf('A rank check failed (%d%s).', $statusCode, $statusMessage !== '' ? ': '.$statusMessage : ''),
            properties: [
                'keyword' => $keyword,
                'status_code' => $statusCode,
                'status_message' => $statusMessage !== '' ? $statusMessage : null,
                'task_type' => $task->task_type ?? null,
            ],
        ), report: true);
    }
}
