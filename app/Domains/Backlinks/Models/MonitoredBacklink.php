<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Models;

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoredBacklink extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'source_url',
        'source_url_hash',
        'target_url',
        'expected_anchor',
        'expected_rel',
        'state',
        'last_http_status',
        'last_observed_rel',
        'last_page_noindex',
        'last_verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_http_status' => 'integer',
            'last_page_noindex' => 'boolean',
            'last_verified_at' => 'datetime',
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
