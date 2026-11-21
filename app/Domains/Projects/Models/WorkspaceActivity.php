<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace-level audit record, such as a membership change. Shown on the
 * members page and merged into every project's activity feed.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $event_type
 * @property string $title
 * @property string|null $description
 * @property array<string, mixed>|null $properties
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $user
 */
class WorkspaceActivity extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
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
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
        Workspace $workspace,
        string $eventType,
        string $title,
        ?string $description = null,
        ?array $properties = null,
        ?User $user = null,
    ): self {
        /** @var self $activity */
        $activity = self::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'properties' => $properties,
        ]);

        return $activity;
    }
}
