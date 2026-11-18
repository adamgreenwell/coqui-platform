<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DataForSeoWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $taskId = (string) ($request->input('id') ?? $request->query('id', ''));
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

        DB::table('dataforseo_tasks')
            ->where('id', $task->id)
            ->update([
                'status' => 'collected',
                'result_payload' => json_encode($request->all(), JSON_THROW_ON_ERROR),
                'collected_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['status' => 'accepted']);
    }
}
