<?php

declare(strict_types=1);

namespace App\Domains\Serp\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $keyword
 * @property string $device
 * @property int $location_code
 * @property string $language_code
 * @property string $cadence
 * @property int|null $search_volume
 * @property int|null $keyword_difficulty
 * @property bool $is_active
 * @property CarbonInterface|null $last_checked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class TrackedKeyword extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'keyword',
        'device',
        'location_code',
        'language_code',
        'cadence',
        'search_volume',
        'keyword_difficulty',
        'is_active',
        'last_checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location_code' => 'integer',
            'search_volume' => 'integer',
            'keyword_difficulty' => 'integer',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
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
     * @return HasMany<KeywordRankSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(KeywordRankSnapshot::class);
    }
}
