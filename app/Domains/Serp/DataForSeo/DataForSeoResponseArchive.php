<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Raw request/response archive for paid DataForSEO calls, kept for
 * `coqui.dataforseo.archive_retention_days` so a charge can be traced back to
 * what was asked and what came back.
 */
class DataForSeoResponseArchive
{
    /**
     * @param  array<int|string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    public function record(
        ?int $ledgerId,
        string $endpoint,
        array $request,
        ?array $response,
        ?int $httpStatus,
        ?Project $project,
        ?string $error = null,
    ): void {
        if (! (bool) config('coqui.dataforseo.archive_responses', true)) {
            return;
        }

        try {
            $now = now();
            DB::table('dataforseo_responses')->insert([
                'ledger_id' => $ledgerId,
                'workspace_id' => $project?->workspace_id,
                'project_id' => $project?->id,
                'endpoint' => $endpoint,
                'http_status' => $httpStatus,
                'request_payload' => json_encode($request, JSON_THROW_ON_ERROR),
                'response_payload' => $response === null ? null : json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'error' => $error === null ? null : mb_substr($error, 0, 2000),
                'captured_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            // The ledger already has the charge; a lost archive row mustn't
            // fail a call that was paid for.
            report($e);
        }
    }

    /**
     * Delete archive rows past the retention window. Returns rows deleted.
     */
    public function prune(): int
    {
        $days = max(1, (int) config('coqui.dataforseo.archive_retention_days', 30));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = DB::table('dataforseo_responses')
                ->where('captured_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('dataforseo_responses')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $deleted;
    }
}
