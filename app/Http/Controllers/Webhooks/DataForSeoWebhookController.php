<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domains\Serp\Actions\RecordSerpRankSnapshots;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DataForSeoWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = [];
        $rawContent = (string) $request->getContent();

        if ($request->header('Content-Encoding') === 'gzip' || str_starts_with($rawContent, "\x1f\x8b")) {
            $decompressed = @gzdecode($rawContent);
            if ($decompressed !== false) {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($decompressed, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        if (empty($payload)) {
            /** @var array<string, mixed> $payload */
            $payload = $request->all();
        }

        $taskId = (string) (
            $request->input('id')
            ?? $request->query('id')
            ?? ($payload['id'] ?? null)
            ?? ($payload['tasks'][0]['id'] ?? null)
            ?? ''
        );
        $token = (string) $request->query('token', '');

        if ($taskId === '' || $token === '') {
            return response()->json(['message' => 'Invalid postback parameters.'], 400);
        }

        $task = DB::table('dataforseo_tasks')
            ->where('external_task_id', $taskId)
            ->first();

        if ($task === null || ! hash_equals((string) $task->postback_token, $token)) {
            return response()->json(['message' => 'Task not found or token mismatch.'], 403);
        }

        if ($task->status === 'collected') {
            return response()->json(['status' => 'already_collected']);
        }

        // A fallback check already recorded this keyword's rankings.
        if ($task->status === 'superseded') {
            return response()->json(['status' => 'superseded']);
        }

        /** @var array<string, mixed>|null $firstTask */
        $firstTask = $payload['tasks'][0] ?? null;
        if (is_array($firstTask)) {
            $taskStatusCode = (int) ($firstTask['status_code'] ?? 20000);
            if ($taskStatusCode >= 40000) {
                DB::table('dataforseo_tasks')
                    ->where('id', $task->id)
                    ->update([
                        'status' => 'failed',
                        'result_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);

                return response()->json([
                    'status' => 'failed',
                    'message' => $firstTask['status_message'] ?? 'DataForSEO task failed.',
                ], 422);
            }
        }

        DB::transaction(function () use ($task, $payload): void {
            if ($task->task_type === 'serp_rank_tracking' && is_string($task->request_context)) {
                /** @var array<string, mixed> $context */
                $context = json_decode($task->request_context, true) ?? [];
                $trackedKeywordId = isset($context['tracked_keyword_id']) ? (int) $context['tracked_keyword_id'] : null;

                if ($trackedKeywordId !== null) {
                    $trackedKeyword = TrackedKeyword::query()
                        ->whereHas('project')
                        ->with('project')
                        ->find($trackedKeywordId);

                    if ($trackedKeyword !== null && $trackedKeyword->project !== null) {
                        app(RecordSerpRankSnapshots::class)->handle($trackedKeyword, $payload);
                    }
                }
            }

            DB::table('dataforseo_tasks')
                ->where('id', $task->id)
                ->update([
                    'status' => 'collected',
                    'result_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'collected_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return response()->json(['status' => 'accepted']);
    }
}
