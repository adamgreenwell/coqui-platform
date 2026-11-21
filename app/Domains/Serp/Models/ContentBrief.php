<?php

declare(strict_types=1);

namespace App\Domains\Serp\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $keyword
 * @property int $location_code
 * @property string $language_code
 * @property int $search_volume
 * @property float $cpc
 * @property int|null $recommended_words_min
 * @property int|null $recommended_words_max
 * @property array<string, mixed> $data
 * @property string $markdown
 * @property string $status
 * @property string|null $error
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Project $project
 */
class ContentBrief extends Model
{
    /** Queued; generation has not finished. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'keyword',
        'location_code',
        'language_code',
        'search_volume',
        'cpc',
        'recommended_words_min',
        'recommended_words_max',
        'data',
        'markdown',
        'status',
        'error',
        'generation',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'location_code' => 'integer',
            'search_volume' => 'integer',
            'cpc' => 'float',
            'recommended_words_min' => 'integer',
            'recommended_words_max' => 'integer',
            'data' => 'array',
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
