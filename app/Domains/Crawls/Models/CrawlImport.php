<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Models;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a client uploaded for import: today a Screaming Frog database
 * (.seospider / .dbseospider), later any crawl export we accept.
 *
 * The row is the contract. The bytes live on the configured object-storage
 * disk under object_key and are only ever read through a presigned URL, so
 * the application disk never holds a customer's crawl.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $project_id
 * @property int|null $uploaded_by
 * @property string $external_import_id
 * @property string $original_name
 * @property string $disk
 * @property string $object_key
 * @property int $size_bytes
 * @property string|null $checksum_sha256
 * @property string $status
 * @property int|null $crawl_run_id
 * @property string|null $error
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $completed_at
 */
class CrawlImport extends Model
{
    use HasPublicUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'uploaded_by',
        'external_import_id',
        'original_name',
        'disk',
        'object_key',
        'size_bytes',
        'checksum_sha256',
        'status',
        'crawl_run_id',
        'error',
        'expires_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'project_id' => 'integer',
            'uploaded_by' => 'integer',
            'size_bytes' => 'integer',
            'crawl_run_id' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<CrawlRun, $this> */
    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
