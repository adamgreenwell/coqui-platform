<?php

declare(strict_types=1);

namespace App\Domains\Serp\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backup rank check for one keyword on one day.
 *
 * @property int $id
 * @property int $tracked_keyword_id
 * @property int $project_id
 * @property int $workspace_id
 * @property int|null $dataforseo_task_id
 * @property string $reason
 * @property string $status
 * @property CarbonInterface $checked_on
 * @property CarbonInterface $reference_at
 * @property int $pages_fetched
 * @property string $cost_usd
 * @property string|null $error_message
 * @property CarbonInterface|null $completed_at
 */
class RankFallbackCheck extends Model
{
    /** The primary task failed. */
    public const REASON_FAILED = 'primary_failed';

    /** The primary task has been pending for too long. */
    public const REASON_STALE = 'primary_stale';

    /** The primary batch couldn't be submitted. */
    public const REASON_SUBMIT_ERROR = 'submit_error';

    protected $fillable = [
        'tracked_keyword_id',
        'project_id',
        'workspace_id',
        'dataforseo_task_id',
        'reason',
        'status',
        'checked_on',
        'reference_at',
        'pages_fetched',
        'cost_usd',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_on' => 'date',
            'reference_at' => 'datetime',
            'completed_at' => 'datetime',
            'pages_fetched' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TrackedKeyword, $this>
     */
    public function trackedKeyword(): BelongsTo
    {
        return $this->belongsTo(TrackedKeyword::class);
    }
}
