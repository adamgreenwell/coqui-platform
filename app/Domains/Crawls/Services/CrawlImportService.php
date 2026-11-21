<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Services;

use App\Domains\Billing\PlanCatalog;
use App\Domains\Crawls\Models\CrawlImport;
use App\Domains\Crawls\StorageNotConfiguredException;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues and completes uploads of crawl exports (Screaming Frog databases
 * today, other exports later) straight to object storage.
 *
 * The bytes never pass through PHP: the client asks for a presigned PUT,
 * uploads directly to the bucket, then calls back to complete. That keeps a
 * multi-hundred-megabyte export away from both the application disk and the
 * web server's request-size limits.
 */
final class CrawlImportService
{
    /** Extensions we accept. Screaming Frog saves SQLite databases, optionally zipped. */
    public const ALLOWED_EXTENSIONS = ['seospider', 'dbseospider', 'sqlite', 'db', 'zip'];

    public const UPLOAD_URL_TTL_MINUTES = 15;

    public const DOWNLOAD_URL_TTL_MINUTES = 5;

    public const PENDING_TTL_HOURS = 24;

    /** Failed imports are forgotten after this long. */
    public const FAILED_RETENTION_DAYS = 7;

    public function diskName(): string
    {
        return (string) config('coqui.imports.disk', 'r2');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function isConfigured(): bool
    {
        $disk = (array) config('filesystems.disks.'.$this->diskName(), []);

        return ! empty($disk['bucket']) && ! empty($disk['key']) && ! empty($disk['secret']) && ! empty($disk['endpoint']);
    }

    /**
     * The largest export this workspace's plan accepts.
     */
    public function maxBytesFor(Project $project): int
    {
        return (int) PlanCatalog::get($project->workspace?->plan_tier)['max_import_bytes'];
    }

    /**
     * Reserve an import row and return it with the URL to upload to.
     *
     * @return array{import: CrawlImport, upload: array{url: string, headers: array<string, string>, expires_at: string}}
     */
    public function createPending(Project $project, ?User $user, string $filename, int $declaredBytes, ?string $externalImportId = null): array
    {
        $this->assertConfigured();

        $name = $this->sanitiseName($filename);
        $this->assertExtension($name);

        $maxBytes = $this->maxBytesFor($project);
        if ($declaredBytes > $maxBytes) {
            throw ValidationException::withMessages([
                'size_bytes' => sprintf(
                    'The file is %s; this plan accepts imports up to %s.',
                    $this->humanBytes($declaredBytes),
                    $this->humanBytes($maxBytes),
                ),
            ]);
        }

        $import = new CrawlImport([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'uploaded_by' => $user?->id,
            'external_import_id' => $externalImportId !== null && $externalImportId !== '' ? $externalImportId : (string) Str::uuid(),
            'original_name' => $name,
            'disk' => $this->diskName(),
            'object_key' => '',
            'size_bytes' => $declaredBytes,
            'status' => CrawlImport::STATUS_PENDING,
            'expires_at' => now()->addHours(self::PENDING_TTL_HOURS),
        ]);

        $import->object_key = $this->objectKey($project, $import);
        $import->save();

        return ['import' => $import, 'upload' => $this->uploadUrl($import)];
    }

    /**
     * A presigned PUT. The client uploads the file itself, then calls complete().
     *
     * @return array{url: string, headers: array<string, string>, expires_at: string}
     */
    public function uploadUrl(CrawlImport $import): array
    {
        $this->assertConfigured();
        $expires = now()->addMinutes(self::UPLOAD_URL_TTL_MINUTES);

        $temporary = $this->disk()->temporaryUploadUrl(
            $import->object_key,
            $expires,
            ['ContentType' => 'application/octet-stream'],
        );

        return [
            'url' => (string) $temporary['url'],
            'headers' => (array) ($temporary['headers'] ?? []),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * Mark the upload finished after checking what actually landed in the
     * bucket. A file larger than the plan allows is deleted rather than kept.
     */
    public function complete(CrawlImport $import): CrawlImport
    {
        $this->assertConfigured();

        if (! $import->isPending()) {
            return $import;
        }

        if ($import->hasExpired()) {
            throw ValidationException::withMessages([
                'import' => 'The upload window for this file has expired. Start the upload again.',
            ]);
        }

        if (! $this->disk()->exists($import->object_key)) {
            throw ValidationException::withMessages([
                'import' => 'No file was found in storage for this import. Upload it before completing the import.',
            ]);
        }

        $size = (int) $this->disk()->size($import->object_key);
        $maxBytes = $this->maxBytesFor($import->project);

        if ($size > $maxBytes) {
            $this->disk()->delete($import->object_key);
            $import->update([
                'status' => CrawlImport::STATUS_FAILED,
                'size_bytes' => $size,
                'error' => 'The uploaded file is larger than the plan allows.',
            ]);

            throw ValidationException::withMessages([
                'size_bytes' => sprintf(
                    'The uploaded file is %s; this plan accepts imports up to %s.',
                    $this->humanBytes($size),
                    $this->humanBytes($maxBytes),
                ),
            ]);
        }

        $import->update([
            'status' => CrawlImport::STATUS_UPLOADED,
            'size_bytes' => $size,
            'expires_at' => null,
            'completed_at' => now(),
            'error' => null,
        ]);

        return $import;
    }

    /**
     * Short-lived read URL for a worker or the desktop app to fetch the file.
     */
    public function downloadUrl(CrawlImport $import): string
    {
        $this->assertConfigured();

        return $this->disk()->temporaryUrl(
            $import->object_key,
            now()->addMinutes(self::DOWNLOAD_URL_TTL_MINUTES),
        );
    }

    /**
     * Remove the row and its object. Used when a client cancels an import.
     */
    public function forget(CrawlImport $import): void
    {
        if ($this->isConfigured() && $import->object_key !== '') {
            $this->disk()->delete($import->object_key);
        }

        $import->delete();
    }

    /**
     * Delete expired pending uploads and old failures, objects included.
     *
     * @return array{pending: int, failed: int}
     */
    public function prune(): array
    {
        $pending = 0;
        $failed = 0;

        $expired = CrawlImport::query()
            ->where('status', CrawlImport::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $import) {
            $this->forget($import);
            $pending++;
        }

        $stale = CrawlImport::query()
            ->where('status', CrawlImport::STATUS_FAILED)
            ->where('updated_at', '<', now()->subDays(self::FAILED_RETENTION_DAYS))
            ->get();

        foreach ($stale as $import) {
            $this->forget($import);
            $failed++;
        }

        return ['pending' => $pending, 'failed' => $failed];
    }

    public function objectKey(Project $project, CrawlImport $import): string
    {
        return sprintf(
            'ws/%s/projects/%s/imports/%s/%s',
            $project->workspace->uuid,
            $project->uuid,
            $import->uuid,
            $import->original_name,
        );
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new StorageNotConfiguredException;
        }
    }

    private function sanitiseName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
        $name = trim($name, '-._');

        if ($name === '') {
            throw ValidationException::withMessages(['filename' => 'A file name is required.']);
        }

        return Str::limit($name, 120, '');
    }

    private function assertExtension(string $name): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'filename' => 'Unsupported file type. Upload a Screaming Frog database (.seospider, .dbseospider) or a .zip of one.',
            ]);
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 1).' GB';
        }

        if ($bytes >= 1024 ** 2) {
            return round($bytes / 1024 ** 2).' MB';
        }

        return round($bytes / 1024).' KB';
    }
}
