<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectIntegration extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'provider',
        'external_identifier',
        'credentials_encrypted',
        'status',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials_encrypted' => 'encrypted',
            'last_synced_at' => 'datetime',
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
