<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Serp\DataForSeo\DataForSeoClient;
use App\Domains\Serp\Models\RankFallbackCheck;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\SpendRefusedException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RunRankBatchesCommand extends Command
{
    protected $signature = 'dataforseo:run-rank-batches
                            {--project= : Optional project ID to process}
                            {--limit= : Optional maximum keywords to submit}
                            {--force : Force dispatch ignoring cadence timing}';

    protected $description = 'Submit due rank tracking batches to DataForSEO standard queue.';

    public function handle(DataForSeoClient $client): int
    {
        $scheduledSpendEnabled = (bool) config('coqui.dataforseo.scheduled_spend');
        $isTesting = app()->environment('testing');

        if (! $scheduledSpendEnabled && ! $isTesting) {
            $this->info('Skipped: DATAFORSEO_SCHEDULED_SPEND is disabled.');

            return self::SUCCESS;
        }

        // Every submission would fail; skip quietly instead of logging an
        // error per keyword on each scheduled run.
        if (! $client->isConfigured()) {
            $this->info('Skipped: DataForSEO credentials are not configured.');

            return self::SUCCESS;
        }

        $projectId = $this->option('project') ? (int) $this->option('project') : null;
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $force = (bool) $this->option('force');

        $query = TrackedKeyword::query()
            ->whereHas('project')
            ->with(['project.workspace'])
            ->where('is_active', true);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        if (! $force) {
            $query->where(function ($q): void {
                $q->whereNull('last_checked_at')
                    ->orWhere(function ($dailyQ): void {
                        $dailyQ->where('cadence', 'daily')
                            ->where('last_checked_at', '<=', now()->subHours(20));
                    })
                    ->orWhere(function ($weeklyQ): void {
                        $weeklyQ->where('cadence', 'weekly')
                            ->where('last_checked_at', '<=', now()->subDays(6)->subHours(20));
                    });
            });
        }

        /** @var list<int> $pendingKeywordIds */
        $pendingKeywordIds = DB::table('dataforseo_tasks')
            ->where('task_type', 'serp_rank_tracking')
            ->whereIn('status', ['pending', 'processing'])
            ->pluck('request_context')
            ->map(function ($context): ?int {
                if (! $context) {
                    return null;
                }
                /** @var array<string, mixed> $data */
                $data = is_string($context) ? json_decode($context, true) : (array) $context;

                return isset($data['tracked_keyword_id']) ? (int) $data['tracked_keyword_id'] : null;
            })
            ->filter()
            ->values()
            ->all();

        if (! empty($pendingKeywordIds)) {
            $query->whereNotIn('id', $pendingKeywordIds);
        }

        $query->orderByRaw('(CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END) ASC')
            ->orderBy('last_checked_at', 'asc')
            ->orderBy('id', 'asc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $keywords = $query->get();

        if ($keywords->isEmpty()) {
            $this->info('No tracked keywords are currently due for rank tracking.');

            return self::SUCCESS;
        }

        $this->info("Found {$keywords->count()} keywords due for rank tracking.");

        /** @var array<int, list<TrackedKeyword>> $grouped */
        $grouped = [];
        foreach ($keywords as $keyword) {
            $grouped[$keyword->project_id][] = $keyword;
        }

        $submittedCount = 0;

        foreach ($grouped as $kwList) {
            $project = $kwList[0]->project;
            if ($project === null || $project->workspace === null) {
                continue;
            }

            // Per-project tallies for the activity feed.
            $projectSubmitted = 0;
            $projectRejected = 0;
            $projectRefused = 0;
            $projectFailures = [];

            /** @var list<list<TrackedKeyword>> $chunks */
            $chunks = array_chunk($kwList, 100);

            foreach ($chunks as $chunk) {
                $tasksPayload = [];
                $tokenMap = [];

                foreach ($chunk as $kw) {
                    $token = Str::random(32);
                    $callbackUrl = route('webhooks.dataforseo', ['token' => $token]);

                    $escapedKeyword = str_replace(['%', '+'], ['%25', '%2B'], $kw->keyword);

                    $tasksPayload[] = [
                        'keyword' => $escapedKeyword,
                        'device' => $kw->device,
                        'location_code' => $kw->location_code,
                        'language_code' => $kw->language_code,
                        'depth' => 100,
                        'postback_url' => $callbackUrl,
                        'postback_data' => 'advanced',
                        'tag' => "kw:{$kw->id}",
                    ];

                    $tokenMap[$kw->id] = $token;
                }

                try {
                    $response = $client->submitStandardSerpTasks($tasksPayload, $project, isScheduled: true);
                } catch (SpendRefusedException $e) {
                    // A spend cap refused it: don't pay another provider instead.
                    $this->warn("Skipped SERP tasks for project {$project->id}: {$e->getMessage()}");
                    $projectRefused += count($chunk);
                    $projectFailures[] = $e->getMessage();

                    continue;
                } catch (Throwable $e) {
                    $this->error("Failed submitting SERP tasks for project {$project->id}: {$e->getMessage()}");
                    $this->queueFallback($chunk, $project->workspace_id);
                    $projectRejected += count($chunk);
                    $projectFailures[] = $e->getMessage();

                    continue;
                }

                $returnedTasks = is_array($response['tasks'] ?? null) ? $response['tasks'] : [];
                $byId = [];
                foreach ($chunk as $kw) {
                    $byId[$kw->id] = $kw;
                }
                $accepted = [];

                foreach ($returnedTasks as $index => $taskData) {
                    if (! is_array($taskData)) {
                        continue;
                    }

                    $externalTaskId = (string) ($taskData['id'] ?? '');
                    // Match by our tag when it's echoed back, else by position.
                    $tag = (string) ($taskData['data']['tag'] ?? '');
                    $kw = str_starts_with($tag, 'kw:') ? ($byId[(int) substr($tag, 3)] ?? null) : ($chunk[$index] ?? null);

                    // DataForSEO reports per-task errors inside a 200 response.
                    if ($externalTaskId === '' || $kw === null || (int) ($taskData['status_code'] ?? 20100) >= 40000) {
                        continue;
                    }

                    $accepted[$kw->id] = true;

                    $token = $tokenMap[$kw->id] ?? Str::random(32);

                    DB::table('dataforseo_tasks')->insert([
                        'workspace_id' => $project->workspace_id,
                        'project_id' => $project->id,
                        'external_task_id' => $externalTaskId,
                        'endpoint' => '/v3/serp/google/organic/task_post',
                        'task_type' => 'serp_rank_tracking',
                        'status' => 'pending',
                        'postback_token' => $token,
                        'request_context' => json_encode([
                            'tracked_keyword_id' => $kw->id,
                            'keyword' => $kw->keyword,
                            'device' => $kw->device,
                            'location_code' => $kw->location_code,
                        ], JSON_THROW_ON_ERROR),
                        'result_payload' => null,
                        'submitted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $submittedCount++;
                    $projectSubmitted++;
                }

                // Keywords with no accepted task (a rejected batch or task
                // inside a 200 response) get a fallback check instead.
                $rejected = array_values(array_filter($chunk, fn (TrackedKeyword $kw): bool => ! isset($accepted[$kw->id])));
                if ($rejected !== []) {
                    $this->error(count($rejected)." SERP task(s) for project {$project->id} weren't accepted (status ".($response['status_code'] ?? 'unknown').').');
                    $this->queueFallback($rejected, $project->workspace_id);
                    $projectRejected += count($rejected);
                }
            }

            $this->logBatchActivity($project, $projectSubmitted, $projectRejected, $projectRefused, $projectFailures);
        }

        $this->info("Successfully submitted {$submittedCount} SERP rank tracking tasks to DataForSEO standard queue.");

        return self::SUCCESS;
    }

    /**
     * One activity entry per project per run: what was sent for ranking and
     * what wasn't.
     *
     * @param  list<string>  $failures
     */
    private function logBatchActivity(Project $project, int $submitted, int $rejected, int $refused, array $failures): void
    {
        if ($submitted + $rejected + $refused === 0) {
            return;
        }

        $failed = $rejected + $refused > 0;
        $parts = [sprintf('%d keyword%s sent for rank checks', $submitted, $submitted === 1 ? '' : 's')];
        if ($rejected > 0) {
            $parts[] = sprintf('%d not accepted (backup checks queued)', $rejected);
        }
        if ($refused > 0) {
            $parts[] = sprintf('%d skipped by a spend limit', $refused);
        }

        rescue(fn () => ProjectActivity::log(
            project: $project,
            eventType: $submitted === 0 && $failed ? 'serp.rank_batch_failed' : 'serp.rank_batch_submitted',
            title: $submitted === 0 && $failed ? 'Rank check batch failed' : 'Rank check batch submitted',
            description: ucfirst(implode('; ', $parts)).'.',
            properties: [
                'submitted' => $submitted,
                'not_accepted' => $rejected,
                'refused_by_spend_limit' => $refused,
                'errors' => array_values(array_unique(array_slice($failures, 0, 3))),
            ],
        ), report: true);
    }

    /**
     * Leave fallback checks for keywords whose batch couldn't be submitted;
     * rank:fallback runs them if the fallback is on.
     *
     * @param  list<TrackedKeyword>  $keywords
     */
    private function queueFallback(array $keywords, int $workspaceId): void
    {
        $rows = array_map(fn (TrackedKeyword $keyword): array => [
            'tracked_keyword_id' => $keyword->id,
            'project_id' => $keyword->project_id,
            'workspace_id' => $workspaceId,
            'dataforseo_task_id' => null,
            'reason' => RankFallbackCheck::REASON_SUBMIT_ERROR,
            'status' => 'pending',
            'checked_on' => today()->toDateString(),
            'reference_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $keywords);

        DB::table('rank_fallback_checks')->insertOrIgnore($rows);
    }
}
