<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Projects\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $type
 * @property string $stripe_id
 * @property string $stripe_status
 * @property string|null $stripe_price
 * @property int|null $quantity
 * @property CarbonInterface|null $trial_ends_at
 * @property CarbonInterface|null $ends_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Workspace|null $workspace
 */
class WorkspaceSubscription extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'type',
        'stripe_id',
        'stripe_status',
        'stripe_price',
        'quantity',
        'trial_ends_at',
        'ends_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function active(): bool
    {
        return in_array($this->stripe_status, ['active', 'trialing'], true) &&
            ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
