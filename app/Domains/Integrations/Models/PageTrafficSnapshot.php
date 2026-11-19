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
 * @property string $provider
 * @property string $path
 * @property string $path_hash
 * @property CarbonInterface $date
 * @property int $organic_sessions
 * @property int $ai_referral_sessions
 * @property int $direct_sessions
 * @property int $total_sessions
 * @property float $bounce_rate
 * @property int $avg_engagement_time_sec
 * @property array<string, int>|null $ai_referrals_breakdown
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Project|null $project
 */
class PageTrafficSnapshot extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'provider',
        'path',
        'path_hash',
        'date',
        'organic_sessions',
        'ai_referral_sessions',
        'direct_sessions',
        'total_sessions',
        'bounce_rate',
        'avg_engagement_time_sec',
        'ai_referrals_breakdown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'organic_sessions' => 'integer',
            'ai_referral_sessions' => 'integer',
            'direct_sessions' => 'integer',
            'total_sessions' => 'integer',
            'bounce_rate' => 'float',
            'avg_engagement_time_sec' => 'integer',
            'ai_referrals_breakdown' => 'array',
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
