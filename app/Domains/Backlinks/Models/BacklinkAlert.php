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
 * @property string $type
 * @property string $severity
 * @property string $dedupe_key
 * @property string $message
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface $detected_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class BacklinkAlert extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'type',
        'severity',
        'dedupe_key',
        'message',
        'meta',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array{type: string, severity: string, message: string, detected_at: string|null, meta: array<string, mixed>}
     */
    public function toPayload(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'message' => $this->message,
            'detected_at' => $this->detected_at->toIso8601String(),
            'meta' => $this->meta ?? [],
        ];
    }
}
