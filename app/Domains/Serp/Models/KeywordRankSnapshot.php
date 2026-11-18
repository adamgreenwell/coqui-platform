<?php

declare(strict_types=1);

namespace App\Domains\Serp\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tracked_keyword_id
 * @property int $project_id
 * @property string $domain
 * @property int|null $rank_group
 * @property int|null $rank_absolute
 * @property string|null $ranking_url
 * @property string|null $ranking_title
 * @property bool $has_ai_overview
 * @property bool $has_featured_snippet
 * @property array<string, mixed>|null $serp_features
 * @property CarbonInterface|string $checked_on
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class KeywordRankSnapshot extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'tracked_keyword_id',
        'project_id',
        'domain',
        'rank_group',
        'rank_absolute',
        'ranking_url',
        'ranking_title',
        'has_ai_overview',
        'has_featured_snippet',
        'serp_features',
        'checked_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank_group' => 'integer',
            'rank_absolute' => 'integer',
            'has_ai_overview' => 'boolean',
            'has_featured_snippet' => 'boolean',
            'serp_features' => 'array',
            'checked_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<TrackedKeyword, $this>
     */
    public function trackedKeyword(): BelongsTo
    {
        return $this->belongsTo(TrackedKeyword::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
