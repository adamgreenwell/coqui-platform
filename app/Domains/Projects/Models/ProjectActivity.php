<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Projects\Events\ProjectActivityLogged;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property string $event_type
 * @property string $title
 * @property string|null $description
 * @property array<string, mixed>|null $properties
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Project $project
 * @property-read User|null $user
 */
class ProjectActivity extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'user_id',
        'event_type',
        'title',
        'description',
        'properties',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>|null  $properties
     */
    public static function log(
        Project $project,
        string $eventType,
        string $title,
        ?string $description = null,
        ?array $properties = null,
        ?User $user = null
    ): self {
        /** @var self $activity */
        $activity = $project->activities()->create([
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'properties' => $properties,
        ]);

        broadcast(new ProjectActivityLogged($activity));

        return $activity;
    }
}
