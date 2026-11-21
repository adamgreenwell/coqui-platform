<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domains\Serp\Jobs\ProcessDataForSeoPostbackJob;
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
                    ->where('status', 'pending')
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

        // Claim the task under a row lock and store the result on it; a job
        // records it off the request. A duplicate delivery finds the task
        // already claimed and changes nothing.
        $claimed = DB::transaction(function () use ($task, $payload): ?string {
            $current = DB::table('dataforseo_tasks')->where('id', $task->id)->lockForUpdate()->first();
            if ($current === null) {
                return 'missing';
            }
            if (! in_array($current->status, ['pending', 'failed'], true)) {
                return (string) $current->status;
            }

            DB::table('dataforseo_tasks')
                ->where('id', $task->id)
                ->update([
                    'status' => 'processing',
                    'result_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            ProcessDataForSeoPostbackJob::dispatch((int) $task->id)->afterCommit();

            return null;
        });

        return match ($claimed) {
            null => response()->json(['status' => 'accepted']),
            'collected' => response()->json(['status' => 'already_collected']),
            'superseded' => response()->json(['status' => 'superseded']),
            default => response()->json(['status' => 'already_received']),
        };
    }
}
