<?php

declare(strict_types=1);

namespace App\Domains\Serp\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tracked_keyword_id
 * @property int $project_id
 * @property CarbonInterface|string $checked_on
 * @property int $position
 * @property string $domain
 * @property string|null $url
 * @property string|null $title
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class KeywordAiOverviewCitation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'tracked_keyword_id',
        'project_id',
        'checked_on',
        'position',
        'domain',
        'url',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'checked_on' => 'date',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<TrackedKeyword, $this> */
    public function trackedKeyword(): BelongsTo
    {
        return $this->belongsTo(TrackedKeyword::class);
    }
}
