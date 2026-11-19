<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $bot_family
 * @property int $status_bucket
 * @property int $hit_count
 * @property int $bytes_served
 * @property array<string, mixed>|list<array<string, mixed>>|null $top_error_paths
 * @property CarbonInterface $bucket_hour
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Project $project
 */
class BotCrawlLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'bot_family',
        'status_bucket',
        'hit_count',
        'bytes_served',
        'top_error_paths',
        'bucket_hour',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_bucket' => 'integer',
            'hit_count' => 'integer',
            'bytes_served' => 'integer',
            'top_error_paths' => 'array',
            'bucket_hour' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
