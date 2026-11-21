<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Billing\PlanFeature;
use App\Domains\Integrations\Services\BotTelemetryService;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureRunnrIntegrationEnabled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;

class BotTelemetryApiController extends Controller implements HasMiddleware
{
    /**
     * Runnr actions stay hidden (404) until the runnr-integration feature is on.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware(EnsureRunnrIntegrationEnabled::class, only: ['ingest']),
        ];
    }

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

        $isRunnr = $expectedToken !== '' && $bearer !== '' && hash_equals($expectedToken, $bearer);

        if (! $isRunnr && $request->user() === null) {
            return response()->json(['message' => 'Unauthorized telemetry access.'], 401);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'siteId' => ['required_without:site_id', 'nullable', 'string', 'max:120'],
            'site_id' => ['required_without:siteId', 'nullable', 'string', 'max:120'],
            // Resending a batch with the same ID replaces it instead of
            // counting it twice. Without one, identical payloads are deduped.
            'batchId' => ['nullable', 'string', 'max:120'],
            'entries' => ['required', 'array', 'min:1', 'max:5000'],
            'entries.*.botFamily' => ['required', 'string', 'max:64'],
            'entries.*.statusBucket' => ['required', 'integer'],
            'entries.*.hitCount' => ['required', 'integer', 'min:0'],
            'entries.*.bytesServed' => ['nullable', 'integer', 'min:0'],
            'entries.*.parameterHitCount' => ['nullable', 'integer', 'min:0'],
            'entries.*.bucketHour' => ['required', 'date'],
            'entries.*.topPaths' => ['nullable', 'array', 'max:500'],
            'entries.*.topPaths.*.path' => ['required_with:entries.*.topPaths', 'string', 'max:2048'],
            'entries.*.topPaths.*.hits' => ['required_with:entries.*.topPaths', 'integer', 'min:1'],
            'entries.*.topPaths.*.status' => ['nullable', 'integer', 'between:100,599'],
            'entries.*.topPaths.*.redirectTarget' => ['nullable', 'string', 'max:2048'],
            'entries.*.topPaths.*.hops' => ['nullable', 'integer', 'min:1', 'max:50'],
            'entries.*.topErrorPaths' => ['nullable', 'array', 'max:500'],
            'entries.*.topErrorPaths.*.path' => ['required_with:entries.*.topErrorPaths', 'string', 'max:2048'],
            'entries.*.topErrorPaths.*.hits' => ['required_with:entries.*.topErrorPaths', 'integer', 'min:1'],
            'entries.*.topErrorPaths.*.status' => ['nullable', 'integer'],
        ]);

        $siteId = (string) ($validated['siteId'] ?? $validated['site_id']);

        // The Runnr platform token may write telemetry for any site; a signed-in
        // user may only write telemetry for a project they can edit.
        //
        // Plan gating: Runnr's platform pushes are always stored, even when the
        // workspace's plan lacks bot telemetry, and stay hidden behind the gated
        // dashboard and API until it has the feature again. Runnr can't act on a
        // rejection, so refusing would only leave gaps in the history. A person
        // pushing telemetry themselves is told plainly that the plan lacks it.
        if (! $isRunnr) {
            $project = Project::query()->with('workspace')->where('runnr_site_id', $siteId)->first();
            if ($project === null || ! Gate::forUser($request->user())->allows('update', $project)) {
                return response()->json(['message' => 'Unauthorized telemetry access.'], 403);
            }

            $project->workspace->ensureFeature(PlanFeature::BotTelemetry);
        }

        /** @var list<array{botFamily: string, statusBucket: int, hitCount: int, bytesServed?: int|null, parameterHitCount?: int|null, bucketHour: string, topPaths?: list<array{path: string, hits: int, status?: int|null, redirectTarget?: string|null, hops?: int|null}>|null, topErrorPaths?: list<array{path: string, hits: int, status?: int|null}>|null}> $entries */
        $entries = $validated['entries'];

        $batchId = isset($validated['batchId']) ? (string) $validated['batchId'] : null;
        $result = $this->telemetry->ingest($siteId, $entries, $batchId);

        return response()->json([
            'status' => 'ingested',
            'duplicate' => $result['duplicate'],
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
