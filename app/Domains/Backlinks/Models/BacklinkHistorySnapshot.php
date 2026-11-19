<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property CarbonInterface $snapshot_date
 * @property int $backlinks_count
 * @property int $referring_domains_count
 * @property int $new_backlinks
 * @property int $lost_backlinks
 * @property int $new_referring_domains
 * @property int $lost_referring_domains
 * @property int $rank
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Project $project
 */
class BacklinkHistorySnapshot extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'snapshot_date',
        'backlinks_count',
        'referring_domains_count',
        'new_backlinks',
        'lost_backlinks',
        'new_referring_domains',
        'lost_referring_domains',
        'rank',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'snapshot_date' => 'date',
            'backlinks_count' => 'integer',
            'referring_domains_count' => 'integer',
            'new_backlinks' => 'integer',
            'lost_backlinks' => 'integer',
            'new_referring_domains' => 'integer',
            'lost_referring_domains' => 'integer',
            'rank' => 'integer',
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
