<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchPerformanceQuery extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'provider',
        'query',
        'date',
        'clicks',
        'impressions',
        'ctr',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'float',
            'position' => 'float',
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
