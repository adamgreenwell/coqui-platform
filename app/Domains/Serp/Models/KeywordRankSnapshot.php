<?php

declare(strict_types=1);

namespace App\Domains\Serp\Models;

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
