<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Billing\Exceptions\FeatureNotAvailableException;
use App\Domains\Billing\Models\BillingInvoice;
use App\Domains\Billing\Models\WorkspaceSubscription;
use App\Domains\Billing\PlanFeature;
use App\Domains\Serp\RankFallback\RankFallbackSpendGuard;
use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $plan_tier
 * @property string $monthly_dfs_budget_usd
 * @property int $tracked_keyword_limit
 * @property int $cloud_crawl_url_limit
 * @property int $owner_id
 * @property string|null $stripe_id
 * @property string|null $stripe_subscription_id
 * @property string $subscription_status
 * @property bool $has_runnr_addon
 * @property CarbonInterface|null $current_period_end
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property int|null $projects_count
 * @property int|null $members_count
 */
class Workspace extends Model
{
    use HasPublicUuid;

    /**
     * Base plans a workspace can pay for, cheapest first. Free is the
     * absence of one, and the Runnr add-on is billed on top of any of them.
     */
    public const PAID_PLAN_TIERS = ['personal', 'pro', 'agency'];

    /**
     * Base plans that unlock the Pro feature set (briefs, backlinks,
     * attribution, bot telemetry). Personal has them with smaller limits.
     */
    public const PRO_FEATURE_TIERS = ['personal', 'pro', 'agency'];

    protected static function booted(): void
    {
        // Plan features are resolved from billing state, so drop any values
        // already resolved in this request or job once that state changes.
        static::saved(function (Workspace $workspace): void {
            if ($workspace->wasChanged(['plan_tier', 'has_runnr_addon'])) {
                Feature::flushCache();
            }
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'plan_tier',
        'monthly_dfs_budget_usd',
        'tracked_keyword_limit',
        'cloud_crawl_url_limit',
        'owner_id',
        'stripe_id',
        'stripe_subscription_id',
        'subscription_status',
        'has_runnr_addon',
        'current_period_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'tracked_keyword_limit' => 'integer',
            'cloud_crawl_url_limit' => 'integer',
            'has_runnr_addon' => 'boolean',
            'current_period_end' => 'datetime',
        ];
    }

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

    /**
     * @return HasMany<WorkspaceActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(WorkspaceActivity::class);
    }

    /**
     * @return HasMany<WorkspaceSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(WorkspaceSubscription::class);
    }

    /**
     * @return HasMany<BillingInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    /**
     * Check if workspace has Runnr hosting bundle entitlement.
     *
     * Only billing state counts: the paid Runnr add-on or the Agency plan.
     * Linking a project to a Runnr site does not, because the site ID is typed
     * in by the user and Runnr never confirms who owns it, so anyone could
     * enter one and unlock smoke tests and bot telemetry for free.
     */
    public function hasRunnrEntitlement(): bool
    {
        return $this->has_runnr_addon || $this->plan_tier === 'agency';
    }

    /**
     * Whether this workspace's plan includes the feature (see the Pennant definitions).
     */
    public function hasFeature(PlanFeature $feature): bool
    {
        return $feature->activeFor($this);
    }

    /**
     * Stop the request with an upgrade prompt unless the plan includes the feature.
     *
     * @throws FeatureNotAvailableException
     */
    public function ensureFeature(PlanFeature $feature): void
    {
        if (! $this->hasFeature($feature)) {
            throw new FeatureNotAvailableException($this, $feature);
        }
    }

    /**
     * Live data spend for the current month: primary provider lookups plus
     * backup rank checks, which share the monthly allowance.
     */
    public function currentMonthDfsSpend(): float
    {
        $primary = (float) DB::table('dataforseo_spend_ledger')
            ->where('workspace_id', $this->id)
            ->where('is_sandbox', false)
            ->where('recorded_at', '>=', now()->startOfMonth())
            ->sum('actual_cost_usd');

        return $primary + RankFallbackSpendGuard::spendSince(now()->startOfMonth(), $this->id);
    }

    /**
     * Calculate remaining DataForSEO budget for the current month.
     */
    public function remainingDfsBudget(): float
    {
        $budget = (float) $this->monthly_dfs_budget_usd;
        $spend = $this->currentMonthDfsSpend();

        return max(0.0, $budget - $spend);
    }

    /**
     * Get count of tracked keywords across all projects in the workspace.
     */
    public function trackedKeywordsCount(): int
    {
        return (int) DB::table('tracked_keywords')
            ->join('projects', 'tracked_keywords.project_id', '=', 'projects.id')
            ->where('projects.workspace_id', $this->id)
            ->whereNull('projects.deleted_at')
            ->count();
    }

    /**
     * Assert that adding the specified number of keywords is within plan limits.
     */
    public function assertCanTrackKeywords(int $additional = 1): void
    {
        $current = $this->trackedKeywordsCount();
        $limit = $this->tracked_keyword_limit;

        if (($current + $additional) > $limit) {
            throw ValidationException::withMessages([
                'keywords' => "You have reached your plan limit of {$limit} tracked keywords (currently using {$current}). Upgrade your plan to track more keywords.",
            ]);
        }
    }

    /**
     * Lock the workspace for the rest of the current transaction and return
     * how many more keywords it can track. Concurrent bulk adds wait on the
     * lock, so they can't both fill the same remaining slots.
     */
    public function lockKeywordAllowance(): int
    {
        static::query()->whereKey($this->id)->lockForUpdate()->first();

        return max(0, $this->tracked_keyword_limit - $this->trackedKeywordsCount());
    }

    /**
     * Assert that the requested crawl URL limit does not exceed plan limits.
     */
    public function assertCanCrawlUrls(int $requestedUrls): void
    {
        $limit = $this->cloud_crawl_url_limit;

        if ($requestedUrls > $limit) {
            throw ValidationException::withMessages([
                'max_urls' => "Requested crawl URL limit ({$requestedUrls}) exceeds your plan limit of {$limit} URLs on the {$this->plan_tier} plan. Please upgrade your plan.",
            ]);
        }
    }

    /**
     * Update workspace plan tier and calibrate usage limits.
     */
    public function applyPlanTier(string $tier, bool $hasRunnrAddon = false): void
    {
        $tier = strtolower(trim($tier));

        $limits = match ($tier) {
            'agency' => [
                'tracked_keyword_limit' => 10000,
                'cloud_crawl_url_limit' => 250000,
                'monthly_dfs_budget_usd' => '750.0000',
            ],
            'pro' => [
                'tracked_keyword_limit' => 1000,
                'cloud_crawl_url_limit' => 25000,
                'monthly_dfs_budget_usd' => '150.0000',
            ],
            'personal' => [
                'tracked_keyword_limit' => 300,
                'cloud_crawl_url_limit' => 5000,
                'monthly_dfs_budget_usd' => '8.0000',
            ],
            default => [
                'tracked_keyword_limit' => 100,
                'cloud_crawl_url_limit' => 1000,
                'monthly_dfs_budget_usd' => '25.0000',
            ],
        };

        $this->update([
            'plan_tier' => in_array($tier, self::PAID_PLAN_TIERS, true) ? $tier : 'free',
            'tracked_keyword_limit' => $limits['tracked_keyword_limit'],
            'cloud_crawl_url_limit' => $limits['cloud_crawl_url_limit'],
            'monthly_dfs_budget_usd' => $limits['monthly_dfs_budget_usd'],
            'has_runnr_addon' => $hasRunnrAddon || $this->has_runnr_addon,
        ]);
    }
}
