<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Models;

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $external_crawl_id
 * @property string $source
 * @property string $seed
 * @property string $seed_kind
 * @property string $status
 * @property array<string, mixed> $config
 * @property int $total_urls
 * @property int $error_count
 * @property int $warning_count
 * @property int $opportunity_count
 */
class CrawlRun extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'external_crawl_id',
        'source',
        'seed',
        'seed_kind',
        'status',
        'config',
        'total_urls',
        'error_count',
        'warning_count',
        'opportunity_count',
        'client_created_at_ms',
        'client_started_at_ms',
        'client_finished_at_ms',
        'finalized_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'total_urls' => 'integer',
            'error_count' => 'integer',
            'warning_count' => 'integer',
            'opportunity_count' => 'integer',
            'client_created_at_ms' => 'integer',
            'client_started_at_ms' => 'integer',
            'client_finished_at_ms' => 'integer',
            'finalized_at' => 'datetime',
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
     * @return HasMany<CrawlUrl, $this>
     */
    public function urls(): HasMany
    {
        return $this->hasMany(CrawlUrl::class);
    }

    /**
     * @return HasMany<CrawlIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(CrawlIssue::class);
    }
}
