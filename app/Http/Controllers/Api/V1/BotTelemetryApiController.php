<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Integrations\Services\BotTelemetryService;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BotTelemetryApiController extends Controller
{
    public function __construct(
        private readonly BotTelemetryService $telemetry,
    ) {}

    /**
     * Ingest batched hourly bot crawler hits from Runnr hosting access logs.
     */
    public function ingest(Request $request): JsonResponse
    {
        $expectedToken = (string) config('services.runnr.telemetry_token');
        $bearer = (string) $request->bearerToken();

        $authorized = false;
        if ($expectedToken !== '' && hash_equals($expectedToken, $bearer)) {
            $authorized = true;
        } elseif ($request->user() !== null) {
            $authorized = true;
        }

        if (! $authorized) {
            return response()->json(['message' => 'Unauthorized telemetry access.'], 401);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'siteId' => ['required_without:site_id', 'nullable', 'string', 'max:120'],
            'site_id' => ['required_without:siteId', 'nullable', 'string', 'max:120'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.botFamily' => ['required', 'string', 'max:64'],
            'entries.*.statusBucket' => ['required', 'integer'],
            'entries.*.hitCount' => ['required', 'integer', 'min:0'],
            'entries.*.bytesServed' => ['nullable', 'integer', 'min:0'],
            'entries.*.bucketHour' => ['required', 'date'],
            'entries.*.topErrorPaths' => ['nullable', 'array'],
            'entries.*.topErrorPaths.*.path' => ['required_with:entries.*.topErrorPaths', 'string', 'max:2048'],
            'entries.*.topErrorPaths.*.hits' => ['required_with:entries.*.topErrorPaths', 'integer', 'min:1'],
            'entries.*.topErrorPaths.*.status' => ['nullable', 'integer'],
        ]);

        $siteId = (string) ($validated['siteId'] ?? $validated['site_id']);

        /** @var list<array{botFamily: string, statusBucket: int, hitCount: int, bytesServed?: int|null, bucketHour: string, topErrorPaths?: list<array{path: string, hits: int, status?: int|null}>|null}> $entries */
        $entries = $validated['entries'];

        $result = $this->telemetry->ingest($siteId, $entries);

        return response()->json([
            'status' => 'ingested',
            'ingestedCount' => $result['ingestedCount'],
            'totalHits' => $result['totalHits'],
            'projectId' => $result['project_id'],
        ], 202);
    }

    /**
     * Inspect telemetry dashboard aggregates for an authenticated project.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $days = max(1, min(90, (int) $request->query('days', 14)));
        $dashboard = $this->telemetry->getTelemetryDashboard($project, $days);

        return response()->json([
            'projectId' => $project->id,
            'days' => $days,
            ...$dashboard,
        ]);
    }
}
