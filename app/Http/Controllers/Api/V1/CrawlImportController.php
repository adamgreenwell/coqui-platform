<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Crawls\Models\CrawlImport;
use App\Domains\Crawls\Services\CrawlImportService;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CrawlImportController extends Controller
{
    public function __construct(private readonly CrawlImportService $imports) {}

    /**
     * Reserve an import and hand back a presigned upload URL.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'external_import_id' => ['nullable', 'uuid'],
            'checksum_sha256' => ['nullable', 'string', 'size:64'],
        ]);

        $result = $this->imports->createPending(
            $project,
            $request->user(),
            (string) $validated['filename'],
            (int) $validated['size_bytes'],
            isset($validated['external_import_id']) ? (string) $validated['external_import_id'] : null,
        );

        /** @var CrawlImport $import */
        $import = $result['import'];

        if (isset($validated['checksum_sha256'])) {
            $import->update(['checksum_sha256' => (string) $validated['checksum_sha256']]);
        }

        return response()->json([
            'data' => $this->payload($import),
            'upload' => $result['upload'],
        ], 201);
    }

    /**
     * The client calls this once the PUT finishes, so we can verify what
     * actually landed and hand it to the importer.
     */
    public function complete(CrawlImport $import): JsonResponse
    {
        $this->authorizeImport($import, 'update');

        $import = $this->imports->complete($import);

        return response()->json(['data' => $this->payload($import)]);
    }

    public function show(CrawlImport $import): JsonResponse
    {
        $this->authorizeImport($import, 'view');

        $data = $this->payload($import);

        if ($import->status === CrawlImport::STATUS_UPLOADED) {
            $data['downloadUrl'] = $this->imports->downloadUrl($import);
        }

        return response()->json(['data' => $data]);
    }

    public function destroy(CrawlImport $import): JsonResponse
    {
        $this->authorizeImport($import, 'update');

        $this->imports->forget($import);

        return response()->json(null, 204);
    }

    /**
     * An import id is not addressable outside its workspace. Answer 404
     * rather than 403 so ids cannot be probed for existence.
     */
    private function authorizeImport(CrawlImport $import, string $ability): void
    {
        if (! Gate::allows($ability, $import->project)) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CrawlImport $import): array
    {
        return [
            'id' => $import->uuid,
            'status' => $import->status,
            'filename' => $import->original_name,
            'sizeBytes' => $import->size_bytes,
            'checksumSha256' => $import->checksum_sha256,
            'crawlRunId' => $import->crawlRun?->external_crawl_id,
            'error' => $import->error,
            'expiresAt' => $import->expires_at?->toIso8601String(),
            'completedAt' => $import->completed_at?->toIso8601String(),
        ];
    }
}
