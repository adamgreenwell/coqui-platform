<?php

declare(strict_types=1);

namespace App\Domains\Backlinks\Models;

use App\Domains\Projects\Models\Project;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $source_url
 * @property string $source_url_hash
 * @property string $target_url
 * @property string|null $expected_anchor
 * @property string|null $expected_rel
 * @property string $state
 * @property int|null $last_http_status
 * @property string|null $last_observed_rel
 * @property bool $last_page_noindex
 * @property CarbonInterface|null $last_verified_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Project $project
 */
class MonitoredBacklink extends Model
{
    use HasPublicUuid;

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
