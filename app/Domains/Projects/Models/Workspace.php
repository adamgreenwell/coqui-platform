<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $plan_tier
 * @property string $monthly_dfs_budget_usd
 * @property int $tracked_keyword_limit
 * @property int $cloud_crawl_url_limit
 * @property int $owner_id
 * @property int|null $projects_count
 * @property int|null $members_count
 */
class Workspace extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'plan_tier',
        'monthly_dfs_budget_usd',
        'tracked_keyword_limit',
        'cloud_crawl_url_limit',
        'owner_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
