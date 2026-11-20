<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $domain
 * @property string|null $label
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ProjectCompetitor extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'domain',
        'label',
        'sort_order',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
