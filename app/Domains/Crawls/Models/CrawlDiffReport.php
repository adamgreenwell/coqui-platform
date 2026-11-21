<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Models;

use App\Domains\Projects\Models\Project;
use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $baseline_run_id
 * @property int $target_run_id
 * @property string $share_token
 * @property string|null $title
 * @property array<string, mixed>|null $summary
 * @property CarbonInterface|null $expires_at
 * @property int $view_count
 * @property int|null $created_by_user_id
 * @property CarbonInterface|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Project $project
 * @property-read CrawlRun $baselineRun
 * @property-read CrawlRun $targetRun
 * @property-read User|null $creator
 */
class CrawlDiffReport extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'baseline_run_id',
        'target_run_id',
        'share_token',
        'title',
        'summary',
        'expires_at',
        'view_count',
        'created_by_user_id',
        'revoked_at',
        'revoked_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'baseline_run_id' => 'integer',
            'target_run_id' => 'integer',
            'summary' => 'array',
            'expires_at' => 'datetime',
            'view_count' => 'integer',
            'created_by_user_id' => 'integer',
            'revoked_at' => 'datetime',
            'revoked_by_user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<CrawlRun, $this>
     */
    public function baselineRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'baseline_run_id');
    }

    /**
     * @return BelongsTo<CrawlRun, $this>
     */
    public function targetRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'target_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function incrementViews(): void
    {
        $this->increment('view_count');
    }

    public static function generateShareToken(): string
    {
        return Str::random(48);
    }
}
