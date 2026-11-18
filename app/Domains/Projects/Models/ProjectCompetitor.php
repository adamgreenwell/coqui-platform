<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCompetitor extends Model
{
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
